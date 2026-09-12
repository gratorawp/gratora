<?php

declare(strict_types=1);

namespace Gratora\Gateways;

use Gratora\Donations\Donation;
use Gratora\Vendor\Queryable\DB;

defined('ABSPATH') || exit;

/**
 * One donation, one charge in flight.
 *
 * Every confirm route reads the donation's status and then spends up to a
 * couple of minutes on the network before writing anything back. Without a lock
 * a second request reads the same pending status and charges the same card
 * again, which is the one mistake a donation form must never make.
 *
 * The lease belongs to the gateway, not to whoever claims it. A caller that
 * could name its own window would judge someone else's lock by its own clock,
 * and a short-windowed claimant would delete a lock still guarding an in-flight
 * capture. The holder's lease is written into the row and expiry reads it back.
 *
 * @since 1.0.0
 */
final class ChargeLock
{
    /**
     * Size each to the longest chain of calls it guards: a lock that expires
     * mid-charge is worse than no lock, because the second request believes it
     * is the only one.
     *
     * @var array<string,int>
     */
    private const LEASE = [
        'paypal'        => 180,
        'square'        => 180,
        'moneris'       => 150,
        'gocardless'    => 90,
        'authorize-net' => 90,
    ];

    private const DEFAULT_LEASE = 180;

    /** Separates the timestamp, the lease and the per-request suffix. */
    private const SEP = '.';

    /**
     * Proof this request is the one that took the lock, so it cannot release a
     * lock some other request took after this one expired.
     */
    private ?string $token = null;

    /** @param string $gateway gateway id, so two gateways cannot share a row. */
    public function __construct(private string $gateway)
    {
    }

    /** @since 1.0.0 */
    public function lease(): int
    {
        return self::LEASE[$this->gateway] ?? self::DEFAULT_LEASE;
    }

    /**
     * One INSERT IGNORE, so exactly one concurrent request wins.
     *
     * Not add_option: it looks like an atomic insert and is not. It reads the
     * option first and then runs INSERT ... ON DUPLICATE KEY UPDATE, so two
     * requests that both pass the read both reach the insert and both are told
     * they won. A transient is no better, being a read and a write with a gap
     * between them.
     *
     * @since 1.0.0
     */
    public function claim(Donation $donation): bool
    {
        if ($this->insert($donation)) {
            return true;
        }

        // A crashed request would otherwise hold the lock forever. Deleting
        // only the expired row keeps this one conditional statement, so a
        // second request racing here cannot free a lock that is still live.
        // Both halves come out of the value, so the holder's own lease decides
        // when it lapsed. whereRaw is spliced in verbatim, so the joiner
        // belongs to the fragment.
        $expired = DB::table('options')
            ->where('option_name', $this->key($donation))
            ->whereRaw(
                'AND CAST(SUBSTRING_INDEX(option_value, %s, 1) AS UNSIGNED)'
                . ' + CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(option_value, %s, 2), %s, -1) AS UNSIGNED)'
                . ' < %d',
                self::SEP,
                self::SEP,
                self::SEP,
                time()
            )
            ->delete();

        if ($expired->affectedRows < 1) {
            $this->forgetCache($donation);

            return false;
        }

        return $this->insert($donation);
    }

    /**
     * Releases only what this request took.
     *
     * An unconditional delete is wrong once expiry is possible: a request whose
     * lock expired mid-charge would free the lock its successor is holding, and
     * a third request would then charge alongside the second.
     *
     * @since 1.0.0
     */
    public function release(Donation $donation): void
    {
        if ($this->token === null) {
            return;
        }

        DB::table('options')
            ->where('option_name', $this->key($donation))
            ->where('option_value', $this->token)
            ->delete();

        $this->token = null;
        $this->forgetCache($donation);
    }

    private function insert(Donation $donation): bool
    {
        // Timestamp, then the lease expiry reads back, then a per-request
        // suffix so two requests in the same second are still distinguishable.
        $token = time() . self::SEP . $this->lease() . self::SEP . wp_generate_password(12, false);

        // The one statement here the query builder cannot express. IGNORE is
        // the whole mechanism: it turns "this row already exists" from an error
        // into an affected-row count of zero, which is how one request learns
        // it lost without a read that another request could race.
        $result = DB::raw(
            'INSERT IGNORE INTO ' . DB::getPrefix() . "options (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            [$this->key($donation), $token]
        );

        $this->forgetCache($donation);

        if ($result->affectedRows !== 1) {
            return false;
        }

        $this->token = $token;

        return true;
    }

    /** The raw statements bypass the options cache, so drop what it still holds. */
    private function forgetCache(Donation $donation): void
    {
        wp_cache_delete($this->key($donation), 'options');
        wp_cache_delete('alloptions', 'options');
    }

    private function key(Donation $donation): string
    {
        return self::keyFor($this->gateway, $donation);
    }

    /**
     * The option row a given gateway's lock lives in.
     *
     * @since 1.0.0
     */
    public static function keyFor(string $gateway, Donation $donation): string
    {
        return 'gratora_' . $gateway . '_charge_' . (int) $donation->id;
    }
}
