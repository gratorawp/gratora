<?php

declare(strict_types=1);

namespace FundKit\Donors;

use FundKit\Analytics\ErrorLog;
use FundKit\Async\AsyncDispatcher;
use FundKit\Foundation\Crypto\Crypto;
use FundKit\Foundation\Identity\IdentityHasher;
use FundKit\Vendor\Queryable\DB;

/**
 * Rehashes all donor email_hash values after a pepper rotation.
 *
 * Old hashes used the lost pepper; dedup only survives by decrypting
 * email_encrypted and rehashing against the new pepper.
 *
 * @since 1.0.0
 */
final class DonorEmailRehasher
{
    public const HOOK  = 'fundkit.async.rehash_donor_email_hashes';

    /**
     * Set when a rehash is owed and cleared when it finishes.
     *
     * The pepper is written first and only once, so an enqueue that fails is
     * never retried and every existing donor keeps a hash nobody can reproduce:
     * they stop being findable by email and the next donation makes a duplicate
     * of each of them. The enqueue does fail on a fresh install, because the
     * pepper is generated at plugins_loaded and Action Scheduler's data store
     * only exists from init.
     */
    public const PENDING_OPTION = 'fundkit_donor_rehash_pending';

    /**
     * How far the walk has got.
     *
     * Held here rather than in the job arguments so every tick is enqueued
     * with the arguments reconcile() guards on. Action Scheduler matches
     * arguments exactly, so a cursor carried in them makes the guard miss the
     * tick already in flight and fork a second walk from the top of the table
     * on the next request, and the one after that.
     */
    private const CURSOR_OPTION = 'fundkit_donor_rehash_after_id';

    private const BATCH = 200;

    /** @since 1.0.0 */
    public function __construct(
        private IdentityHasher $hasher,
        private Crypto $crypto,
        private AsyncDispatcher $async,
    ) {
    }

    /** @since 1.0.0 */
    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);

        // Late enough that Action Scheduler is up, and cheap: one option read
        // when nothing is owed.
        add_action('init', [$this, 'reconcile'], 20);
    }

    /**
     * Mark a rehash as owed. Safe to call before Action Scheduler exists.
     *
     * @since 1.0.0
     */
    public static function markPending(): void
    {
        // A new pepper invalidates every hash, including the ones an unfinished
        // walk already rewrote, so the next tick starts from the top again.
        delete_option(self::CURSOR_OPTION);
        update_option(self::PENDING_OPTION, '1', false);
    }

    /**
     * Queue the owed rehash once, if it is not already running.
     *
     * @since 1.0.0
     */
    public function reconcile(): void
    {
        if (get_option(self::PENDING_OPTION) !== '1') {
            return;
        }

        if (\as_has_scheduled_action(self::HOOK, [], AsyncDispatcher::GROUP)) {
            return;
        }

        $this->async->enqueue(self::HOOK, []);
    }

    /** @since 1.0.0 */
    public function run(): void
    {
        $afterId = (int) get_option(self::CURSOR_OPTION, 0);

        $rows = DB::table('fundkit_donors')
            ->where('id', $afterId, '>')
            ->where('email_encrypted', '', '!=')
            ->orderBy('id', 'ASC')
            ->limit(self::BATCH)
            ->select('id, email_encrypted')
            ->getAll();

        if (empty($rows)) {
            // Walked the whole table, so nothing is owed any more.
            $this->finish();
            return;
        }

        $lastId = $afterId;
        foreach ($rows as $row) {
            $id    = (int) ($row['id'] ?? 0);
            $blob  = (string) ($row['email_encrypted'] ?? '');
            $lastId = $id;
            if ($blob === '') continue;

            $plain = $this->crypto->decrypt($blob);
            if ($plain === null) continue;

            $newHash = $this->hasher->emailHash($plain);

            // The duplicate is expected here, so the failure is handled rather
            // than printed: wpdb would otherwise echo the statement into
            // whatever request the queue happens to be running in.
            global $wpdb;
            $suppressed = $wpdb->suppress_errors(true);

            try {
                DB::table('fundkit_donors')
                    ->where('id', $id)
                    ->update(['email_hash' => $newHash]);
            } catch (\Throwable $e) {
                // email_hash is unique, and a donation arriving mid-walk has
                // already created a second row for this address under the new
                // pepper. Letting that abort the tick left the cursor unwritten
                // and every donor after this one unfindable for good, which is
                // far worse than the one duplicate.
                $this->recordCollision($id, $newHash);
            } finally {
                $wpdb->suppress_errors($suppressed);
            }
        }

        if (count($rows) === self::BATCH) {
            update_option(self::CURSOR_OPTION, (string) $lastId, false);
            $this->async->enqueue(self::HOOK, []);
            return;
        }

        $this->finish();
    }

    /**
     * The two rows are one person, and nothing here can merge them: the
     * donations, plans and receipts on each would have to move. So it is
     * written down where an admin can see it.
     */
    private function recordCollision(int $id, string $hash): void
    {
        $existing = DB::table('fundkit_donors')
            ->where('email_hash', $hash)
            ->select('id')
            ->get();

        ErrorLog::record(
            'donor.rehash',
            'Donor could not be rehashed: another donor row already holds this email. They are the same person and hold separate giving histories.',
            [
                'donor_id'     => $id,
                'duplicate_of' => (int) ($existing['id'] ?? 0),
            ]
        );
    }

    /** @since 1.0.0 */
    private function finish(): void
    {
        delete_option(self::CURSOR_OPTION);
        delete_option(self::PENDING_OPTION);
    }
}
