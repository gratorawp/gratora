<?php

declare(strict_types=1);

namespace Dono\Donors;

use Dono\Foundation\Time\Clock;

/**
 * Issues and validates magic-link tokens for donor self-service.
 *
 * @since 1.0.0
 */
final class MagicLinkService
{
    /** @since 1.0.0 */
    public function __construct(private Clock $clock)
    {
    }

    /**
     * @param int $ttlSeconds default 30 days
     * @param array{first_name?:?string, last_name?:?string} $profile names this
     *        one link may apply, and no other link and no shared row can change
     *
     * @since 1.0.0
     */
    public function issue(
        int $donorId,
        string $purpose,
        ?int $targetId = null,
        int $ttlSeconds = 2_592_000,
        array $profile = []
    ): string {
        $raw  = bin2hex(random_bytes(24)); // 48-char hex, ~192 bits
        $hash = hash('sha256', $raw);

        $now = $this->clock->now();
        // Bare signed relative format so negative TTLs subtract correctly
        // ("+-1 seconds" is not parsed as -1; "-1 seconds" is).
        $expires = $now->modify("{$ttlSeconds} seconds");

        $token = MagicLinkToken::make();
        $token->donor_id   = $donorId;
        $token->token_hash = $hash;
        $token->purpose    = $purpose;
        $token->target_id  = $targetId;
        $token->first_name = $this->name($profile['first_name'] ?? null);
        $token->last_name  = $this->name($profile['last_name'] ?? null);
        $token->expires_at = $expires->format('Y-m-d H:i:s');
        $token->created_at = $now->format('Y-m-d H:i:s');
        $token->save();

        return $raw;
    }

    /**
     * Clamped to the column width rather than refused: on a strict server an
     * over-long surname would throw and lose the whole signup, and a signup
     * that fails over a long name is worse than a name that is short.
     *
     * @since 1.0.0
     */
    private function name(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? mb_substr($value, 0, 100) : null;
    }

    /** @since 1.0.0 */
    public function validate(string $rawToken, string $purpose, ?int $targetId = null): ?MagicLinkToken
    {
        if ($rawToken === '') return null;
        if ($this->isRateLimited($purpose)) return null;
        $hash = hash('sha256', $rawToken);

        $query = MagicLinkToken::query()
            ->where('token_hash', $hash)
            ->where('purpose', $purpose);

        if ($targetId !== null) {
            $query = $query->where('target_id', $targetId);
        }

        $token = $query->get();
        if (! $token) {
            $this->recordFailure($purpose);
            return null;
        }

        if (strtotime($token->expires_at) < $this->clock->now()->getTimestamp()) {
            $this->recordFailure($purpose);
            return null;
        }

        return $token;
    }

    /** @since 1.0.0 */
    public function consumeAndValidate(string $rawToken, string $purpose, ?int $targetId = null): ?MagicLinkToken
    {
        if ($rawToken === '') return null;
        if ($this->isRateLimited($purpose)) return null;
        $hash = hash('sha256', $rawToken);
        $now  = $this->clock->now()->format('Y-m-d H:i:s');

        $claim = MagicLinkToken::query()
            ->where('token_hash', $hash)
            ->where('purpose', $purpose)
            ->whereIsNull('used_at')
            ->where('expires_at', $now, '>');

        if ($targetId !== null) {
            $claim = $claim->where('target_id', $targetId);
        }

        $result = $claim->update(['used_at' => $now]);
        if ($result->affectedRows < 1) {
            $this->recordFailure($purpose);
            return null;
        }

        $reload = MagicLinkToken::query()
            ->where('token_hash', $hash)
            ->where('purpose', $purpose);
        if ($targetId !== null) {
            $reload = $reload->where('target_id', $targetId);
        }

        return $reload->get();
    }

    /** @since 1.0.0 */
    public function consume(MagicLinkToken $token): void
    {
        $token->used_at = $this->clock->now()->format('Y-m-d H:i:s');
        $token->save();
    }

    /**
     * Rate-limit token guessing, per purpose and per IP (20 per 15 minutes).
     *
     * Only failures count: a guess is a failure, and counting successes would
     * spend a donor's budget on their own valid tokens. Keyed per purpose so
     * exhausting one purpose cannot lock a donor out of another, and on
     * REMOTE_ADDR, which donors behind one NAT share.
     *
     * @since 1.0.0
     */
    private function isRateLimited(string $purpose): bool
    {
        return (int) get_transient($this->rateKey($purpose)) >= 20;
    }

    /** @since 1.0.0 */
    private function recordFailure(string $purpose): void
    {
        $key = $this->rateKey($purpose);
        set_transient($key, ((int) get_transient($key)) + 1, 900);
    }

    /** @since 1.0.0 */
    private function rateKey(string $purpose): string
    {
        $ip = filter_var(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''), FILTER_VALIDATE_IP) ?: 'unknown';

        return 'dono_ml_val_' . hash('sha256', $purpose . '|' . $ip);
    }

    /**
     * Delete tokens that have expired or been consumed.
     *
     * @since 1.0.0
     */
    public function purgeExpired(): int
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $deleted = MagicLinkToken::query()
            ->where('expires_at', $now, '<')
            ->orWhereIsNotNull('used_at')
            ->delete();

        return $deleted->affectedRows;
    }
}
