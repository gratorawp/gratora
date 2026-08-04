<?php

declare(strict_types=1);

namespace Dono\Donors;

use Dono\Async\AsyncDispatcher;
use Dono\Foundation\Crypto\Crypto;
use Dono\Foundation\Identity\IdentityHasher;
use Dono\Vendor\Queryable\DB;

/**
 * Rehashes all donor email_hash values after a pepper rotation.
 *
 * Old hashes used the lost pepper; dedup only survives by decrypting
 * email_encrypted and rehashing against the new pepper.
 *
 * @version 1.0.0
 */
final class DonorEmailRehasher
{
    public const HOOK  = 'dono.async.rehash_donor_email_hashes';

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
    public const PENDING_OPTION = 'dono_donor_rehash_pending';

    private const BATCH = 200;

    public function __construct(
        private IdentityHasher $hasher,
        private Crypto $crypto,
        private AsyncDispatcher $async,
    ) {
    }

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);

        // Late enough that Action Scheduler is up, and cheap: one option read
        // when nothing is owed.
        add_action('init', [$this, 'reconcile'], 20);
    }

    /** Mark a rehash as owed. Safe to call before Action Scheduler exists. */
    public static function markPending(): void
    {
        update_option(self::PENDING_OPTION, '1', false);
    }

    /** Queue the owed rehash once, if it is not already running. */
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

    /**
     * @param array{after_id?:int}|int $args
     */
    public function run(mixed $args = []): void
    {
        $afterId = is_array($args) ? (int) ($args['after_id'] ?? 0) : (int) $args;

        $rows = DB::table('dono_donors')
            ->where('id', $afterId, '>')
            ->where('email_encrypted', '', '!=')
            ->orderBy('id', 'ASC')
            ->limit(self::BATCH)
            ->select('id, email_encrypted')
            ->getAll();

        if (empty($rows)) {
            // Walked the whole table, so nothing is owed any more.
            delete_option(self::PENDING_OPTION);
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
            DB::table('dono_donors')
                ->where('id', $id)
                ->update(['email_hash' => $newHash]);
        }

        if (count($rows) === self::BATCH) {
            $this->async->enqueue(self::HOOK, ['after_id' => $lastId]);
            return;
        }

        delete_option(self::PENDING_OPTION);
    }
}
