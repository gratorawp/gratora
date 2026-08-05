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

    /** Mark a token as consumed (single-use flows). */
    public function consume(MagicLinkToken $token): void
    {
        $token->used_at = $this->clock->now()->format('Y-m-d H:i:s');
        $token->save();
    }

    /**
     * Rate-limit token guessing, per purpose and per IP (20 per 15 minutes).
     *
     * Two things were wrong with counting every validation against one shared
     * budget. Successes counted, so a donor opening their receipts tab spent
     * the budget on their own valid tokens; and the budget was shared across
     * purposes, so spending it there locked them out of signing in, with the
     * portal telling them their link had expired. Both are ordinary use.
     *
     * What the limit is actually for is guessing, and a guess is a failure, so
     * only failures count now. Still keyed on REMOTE_ADDR, which donors behind
     * one NAT share, but a shared budget of failed attempts is a far smaller
     * thing to share than a shared budget of successful ones.
     */
    private function isRateLimited(string $purpose): bool
    {
        return (int) get_transient($this->rateKey($purpose)) >= 20;
    }

    private function recordFailure(string $purpose): void
    {
        $key = $this->rateKey($purpose);
        set_transient($key, ((int) get_transient($key)) + 1, 900);
    }

    private function rateKey(string $purpose): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

        return 'dono_ml_val_' . hash('sha256', $purpose . '|' . $ip);
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
