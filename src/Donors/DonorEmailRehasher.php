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

        if (empty($rows)) return;

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
        }
    }
}
