<?php

declare(strict_types=1);

namespace Dono\Donors;

use Dono\Foundation\Time\Clock;

/**
 * Issues and validates magic-link tokens for donor self-service.
 *
 * @version 1.0.0
 */
final class MagicLinkService
{
    public function __construct(private Clock $clock)
    {
    }

    /**
     * Generates and persists a token.
     *
     * @param int $ttlSeconds default 30 days
     */
    public function issue(int $donorId, string $purpose, ?int $targetId = null, int $ttlSeconds = 2_592_000): string
    {
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
        $token->expires_at = $expires->format('Y-m-d H:i:s');
        $token->created_at = $now->format('Y-m-d H:i:s');
        $token->save();

        return $raw;
    }

    /** Validates a raw token for the given purpose and optional target. */
    public function validate(string $rawToken, string $purpose, ?int $targetId = null): ?MagicLinkToken
    {
        if ($rawToken === '') return null;
        if ($this->isRateLimited()) return null;
        $hash = hash('sha256', $rawToken);

        $query = MagicLinkToken::query()
            ->where('token_hash', $hash)
            ->where('purpose', $purpose);

        if ($targetId !== null) {
            $query = $query->where('target_id', $targetId);
        }

        $token = $query->get();
        if (! $token) return null;

        if (strtotime($token->expires_at) < $this->clock->now()->getTimestamp()) {
            return null;
        }

        return $token;
    }

    public function consumeAndValidate(string $rawToken, string $purpose, ?int $targetId = null): ?MagicLinkToken
    {
        if ($rawToken === '') return null;
        if ($this->isRateLimited()) return null;
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
        if ($result->affectedRows < 1) return null;

        $reload = MagicLinkToken::query()
            ->where('token_hash', $hash)
            ->where('purpose', $purpose);
        if ($targetId !== null) {
            $reload = $reload->where('target_id', $targetId);
        }

        return $reload->get();
    }

    /** Mark a token as consumed (single-use flows). */
    public function consume(MagicLinkToken $token): void
    {
        $token->used_at = $this->clock->now()->format('Y-m-d H:i:s');
        $token->save();
    }

    /** Rate-limit token validation attempts per IP (20 per 15 minutes). */
    private function isRateLimited(): bool
    {
        $ip  = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $key = 'dono_ml_val_' . hash('sha256', $ip);
        $count = (int) get_transient($key);
        if ($count >= 20) return true;
        set_transient($key, $count + 1, 900);
        return false;
    }

    /** Delete tokens that have expired or been consumed. */
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
