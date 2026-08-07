<?php

declare(strict_types=1);

namespace Dono\Donors;

use Dono\Foundation\Crypto\Crypto;
use Dono\Foundation\Identity\IdentityHasher;
use Dono\Foundation\Time\Clock;

/**
 * Reads and writes the unproven addresses waiting to become donors.
 *
 * @version 1.0.0
 */
final class PendingSignupRepository
{
    /**
     * How long an unproven address is kept. Long enough to survive a spam
     * folder and a weekend, short enough that an address nobody confirmed is
     * not sitting here indefinitely. The link is issued with the same life, so
     * a live link never points at a row that has already gone.
     */
    public const TTL_SECONDS = 7 * DAY_IN_SECONDS;

    public function __construct(
        private Crypto $crypto,
        private IdentityHasher $hasher,
        private Clock $clock,
    ) {
    }

    /**
     * Records a claim on an address, replacing any claim already standing for
     * it. Signing up twice is a person who lost the first email, not a second
     * person, so the newer names and the fresher expiry win.
     */
    public function put(string $email, ?string $firstName, ?string $lastName): PendingSignup
    {
        $normalized = $this->hasher->normalizeEmail($email);
        $hash       = $this->hasher->emailHash($normalized);
        $now        = $this->clock->now();

        $existing = $this->findByEmailHash($hash);
        $row      = $existing ?? PendingSignup::make();

        $row->email_hash      = $hash;
        $row->email_encrypted = $this->crypto->encrypt($normalized);
        $row->first_name      = $firstName !== '' ? $firstName : null;
        $row->last_name       = $lastName  !== '' ? $lastName  : null;
        $row->expires_at      = $now->modify('+' . self::TTL_SECONDS . ' seconds')->format('Y-m-d H:i:s');
        if ($existing === null) {
            $row->created_at = $now->format('Y-m-d H:i:s');
        }
        $row->save();

        return $row;
    }

    public function findById(int $id): ?PendingSignup
    {
        return $id > 0 ? PendingSignup::query()->find('id', $id) : null;
    }

    public function findByEmailHash(string $hash): ?PendingSignup
    {
        return PendingSignup::query()->find('email_hash', $hash);
    }

    /** The address as it was typed, for the donor this row is about to create. */
    public function decryptEmail(PendingSignup $row): ?string
    {
        return $this->crypto->decrypt($row->email_encrypted);
    }

    public function delete(int $id): void
    {
        if ($id > 0) {
            PendingSignup::query()->where('id', $id)->delete();
        }
    }

    /**
     * Erasure reaches this table by address, because a row here has no donor to
     * reach it by. Nothing else identifies the person.
     */
    public function deleteByEmailHash(string $hash): void
    {
        if ($hash !== '') {
            PendingSignup::query()->where('email_hash', $hash)->delete();
        }
    }

    /** Unproven addresses are not kept past their window. */
    public function purgeExpired(): int
    {
        return PendingSignup::query()
            ->where('expires_at', $this->clock->now()->format('Y-m-d H:i:s'), '<')
            ->delete()
            ->affectedRows;
    }

    public function isLive(PendingSignup $row): bool
    {
        return strtotime($row->expires_at) >= $this->clock->now()->getTimestamp();
    }
}
