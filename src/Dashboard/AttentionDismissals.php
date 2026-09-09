<?php

declare(strict_types=1);

namespace Gratora\Dashboard;

/**
 * Which attention items this user has waved off, and until when.
 *
 * Dismissal is per user, not per site: one admin marking a donor note as read
 * is a statement about themselves, and hiding a misconfigured campaign from a
 * colleague who never saw it would be a way to lose it.
 *
 * A dismissal is keyed on the item AND on a signature of the state that
 * produced it, so it lapses the moment that state changes. Waving off "3
 * donations failed" must not also swallow tomorrow's fifty: same key, different
 * signature, so the item comes back. Items whose condition simply resolves (a
 * campaign that ends, a form that gets set) stop being generated at all, and
 * their stale dismissal is harmless.
 *
 * @since 1.0.0
 */
final class AttentionDismissals
{
    private const META_KEY = 'gratora_attention_dismissed';

    /** Bounds the meta blob: dismissals are per key, and the keys are few. */
    private const MAX_ENTRIES = 50;

    /**
     * The signature of an item's current state. Counted items sign with their
     * count; the rest carry their identity in the key already.
     *
     * @param array<string,mixed> $item
     * @since 1.0.0
     */
    public static function signatureFor(array $item): string
    {
        return isset($item['count']) ? (string) (int) $item['count'] : 'x';
    }

    /**
     * Whether an item is news again since it was waved off.
     *
     * A counted item is, when the count rises. A rolling window's count also
     * falls on its own as old rows age out, and the same payments counted one
     * fewer time are not a new state to wave off a second time.
     *
     * @param array<string,mixed> $item
     * @since 1.0.0
     */
    private static function hasWorsened(string $dismissed, array $item): bool
    {
        // is_numeric, so a client that lost the signature (NeedsAttention sends
        // 'x' when it has none) keeps the strict comparison rather than being
        // hidden for good.
        if (isset($item['count']) && is_numeric($dismissed)) {
            return (int) $item['count'] > (int) $dismissed;
        }

        return $dismissed !== self::signatureFor($item);
    }

    /**
     * Drop the items this user has waved off at their current signature.
     *
     * @param  array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     * @since 1.0.0
     */
    public function filter(array $items, int $userId): array
    {
        $dismissed = $this->all($userId);
        if ($dismissed === []) {
            return $items;
        }

        return array_values(array_filter($items, static function (array $item) use ($dismissed): bool {
            $key = (string) ($item['key'] ?? '');
            return ! isset($dismissed[$key])
                || self::hasWorsened($dismissed[$key], $item);
        }));
    }

    /** @since 1.0.0 */
    public function dismiss(int $userId, string $key, string $signature): void
    {
        $key = trim($key);
        if ($key === '' || $userId <= 0) {
            return;
        }

        $all = $this->all($userId);
        // Re-inserted at the end so the oldest entry is the one trimmed.
        unset($all[$key]);
        $all[$key] = $signature;

        if (count($all) > self::MAX_ENTRIES) {
            $all = array_slice($all, -self::MAX_ENTRIES, null, true);
        }

        update_user_meta($userId, self::META_KEY, wp_json_encode($all));
    }

    /** @since 1.0.0 */
    public function restore(int $userId, string $key): void
    {
        $all = $this->all($userId);
        if (! isset($all[$key])) {
            return;
        }

        unset($all[$key]);
        update_user_meta($userId, self::META_KEY, wp_json_encode($all));
    }

    /**
     * @return array<string,string>
     * @since 1.0.0
     */
    /**
     * The campaign ids this user has already waved off under a key prefix.
     *
     * The per-campaign queues are limited in SQL and filtered afterwards, so a
     * page of dismissed rows would otherwise cost the queue its whole page: the
     * campaigns behind them are never selected and never offered.
     *
     * @return list<int>
     * @since 1.0.0
     */
    public function dismissedIds(int $userId, string $prefix): array
    {
        $out = [];
        foreach (array_keys($this->all($userId)) as $key) {
            if (! str_starts_with((string) $key, $prefix)) continue;

            $id = (int) substr((string) $key, strlen($prefix));
            if ($id > 0) $out[] = $id;
        }

        return $out;
    }

    public function all(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $raw = get_user_meta($userId, self::META_KEY, true);
        $all = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : []);
        if (! is_array($all)) {
            return [];
        }

        $out = [];
        foreach ($all as $key => $sig) {
            if (is_string($key) && (is_string($sig) || is_int($sig))) {
                $out[$key] = (string) $sig;
            }
        }

        return $out;
    }
}
