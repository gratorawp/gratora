<?php

declare(strict_types=1);

namespace Dono\Donors;

use Dono\Foundation\Crypto\Crypto;
use Dono\Foundation\Identity\IdentityHasher;
use Dono\Foundation\Time\Clock;

/**
 * Repository for PendingSignup. Encrypts the address at the boundary.
 *
 * @since 1.0.0
 */
final class PendingSignupRepository
{
    /**
     * The link is issued with the same life, so a live link never points at a
     * row that has already gone.
     */
    public const TTL_SECONDS = 7 * DAY_IN_SECONDS;

    /** @since 1.0.0 */
    public function __construct(
        private Crypto $crypto,
        private IdentityHasher $hasher,
        private Clock $clock,
    ) {
    }

    /**
     * Signing up twice is a person who lost the first email, not a second
     * person, so it refreshes the one row rather than opening a second claim on
     * the mailbox. Nothing here is contestable: the row says an address was
     * typed and when the claim on it dies, and each signup's own name rides the
     * token that signup mints.
     *
     * @since 1.0.0
     */
    public function put(string $email): PendingSignup
    {
        $normalized = $this->hasher->normalizeEmail($email);
        $hash       = $this->hasher->emailHash($normalized);
        $now        = $this->clock->now();

        $existing = $this->findByEmailHash($hash);
        $row      = $existing ?? PendingSignup::make();

        $row->email_hash      = $hash;
        $row->email_encrypted = $this->crypto->encrypt($normalized);
        $row->expires_at      = $now->modify('+' . self::TTL_SECONDS . ' seconds')->format('Y-m-d H:i:s');
        if ($existing === null) {
            $row->created_at = $now->format('Y-m-d H:i:s');
        }
        $row->save();

        return $row;
    }

    /** @since 1.0.0 */
    public function findById(int $id): ?PendingSignup
    {
        return $id > 0 ? PendingSignup::query()->find('id', $id) : null;
    }

    /** @since 1.0.0 */
    public function findByEmailHash(string $hash): ?PendingSignup
    {
        return PendingSignup::query()->find('email_hash', $hash);
    }

    /** @since 1.0.0 */
    public function decryptEmail(PendingSignup $row): ?string
    {
        return $this->crypto->decrypt($row->email_encrypted);
    }

    /** @since 1.0.0 */
    public function delete(int $id): void
    {
        if ($id > 0) {
            self::deleteSignupTokensFor($id);
            PendingSignup::query()->where('id', $id)->delete();
        }
    }

    /**
     * A signup token holds the name its registration typed, so a claim that
     * goes has to take its tokens with it or the name outlives the row that
     * erasure deleted. They are dead links either way once the claim is gone:
     * redemption reloads the claim by target_id and refuses without it.
     *
     * @since 1.0.0
     */
    public static function deleteSignupTokensFor(int $claimId): void
    {
        if ($claimId <= 0) return;

        MagicLinkToken::query()
            ->where('purpose', SignupRedemption::PURPOSE)
            ->where('target_id', $claimId)
            ->delete();
    }

    /**
     * Erasure reaches this table by address, because a row here has no donor to
     * reach it by.
     *
     * @since 1.0.0
     */
    public function deleteByEmailHash(string $hash): void
    {
        if ($hash === '') return;

        $claim = $this->findByEmailHash($hash);
        if ($claim !== null) {
            self::deleteSignupTokensFor((int) $claim->id);
        }

        PendingSignup::query()->where('email_hash', $hash)->delete();
    }

    /** @since 1.0.0 */
    public function purgeExpired(): int
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        // The same rule the single deletes follow: a token outlives its claim
        // otherwise, still carrying the name its registration typed. The daily
        // sweep runs the token purge before this one, so anything left here
        // waits a full day for the next pass.
        foreach (PendingSignup::query()->where('expires_at', $now, '<')->getAll() as $claim) {
            self::deleteSignupTokensFor((int) $claim->id);
        }

        return PendingSignup::query()
            ->where('expires_at', $now, '<')
            ->delete()
            ->affectedRows;
    }

    /** @since 1.0.0 */
    public function isLive(PendingSignup $row): bool
    {
        return strtotime($row->expires_at) >= $this->clock->now()->getTimestamp();
    }
}
