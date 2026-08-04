<?php

declare(strict_types=1);

namespace Dono\Foundation\References;

use Dono\Foundation\Time\Clock;
use Dono\Vendor\Queryable\DB;
use RuntimeException;

/**
 * Generates human-readable, monotonically-increasing references like DONO-2026-00001.
 *
 * Per-scope counter (donation / receipt / refund), atomically incremented via
 * MySQL LAST_INSERT_ID() - gap-free and race-safe. Configurable via the
 * dono_reference_settings option. reset_yearly (default true) starts a fresh
 * counter each Jan 1, which requires include_year to tell the two sequences
 * apart; without it, numbering is continuous across years either way.
 *
 * @version 1.0.0
 */
final class ReferenceGenerator
{
    public const OPTION_SETTINGS = 'dono_reference_settings';

    public const DEFAULT_SETTINGS = [
        'prefixes' => [
            'donation' => 'DONO',
            'receipt'  => 'REC',
            'refund'   => 'REF',
        ],
        'padding'      => 5,
        'include_year' => true,
        'reset_yearly' => true,
        'separator'    => '-',
    ];

    public function __construct(private Clock $clock)
    {
    }

    /** Increment the counter for $scope and return the formatted reference. */
    public function next(string $scope = 'donation'): string
    {
        $scope = $this->normaliseScope($scope);
        $year  = (int) $this->clock->now()->format('Y');

        $counter = $this->nextCounter($scope, $year);

        return $this->format($scope, $year, $counter);
    }

    /**
     * Set the counter so the next call to next() returns $nextValue.
     * Throws if $nextValue <= current counter (would create duplicates).
     */
    public function nextNumber(string $scope, int $nextValue): void
    {
        if ($nextValue < 1) {
            throw new \InvalidArgumentException('Next number must be >= 1.');
        }

        $scope = $this->normaliseScope($scope);
        $year  = (int) $this->clock->now()->format('Y');
        $key   = $this->counterOption($scope, $year);

        $current = $this->currentCounter($scope, $key);
        if ($nextValue <= $current) {
            throw new \RuntimeException(
                "Cannot set counter for {$scope} to {$nextValue}; current counter is already {$current}. " .
                'Choose a value > current to avoid duplicate references.'
            );
        }

        // Counter stores the last used value; seed at nextValue-1 so next() returns nextValue.
        if (get_option($key, null) === null) {
            add_option($key, (string) ($nextValue - 1), '', false);
        } else {
            update_option($key, (string) ($nextValue - 1), false);
        }
    }

    /** Current counter for a scope without incrementing. */
    public function peekNext(string $scope = 'donation'): int
    {
        $scope = $this->normaliseScope($scope);
        $year  = (int) $this->clock->now()->format('Y');

        return $this->currentCounter($scope, $this->counterOption($scope, $year)) + 1;
    }

    /**
     * What next() would treat as the last used value, seeding included but not
     * persisted.
     *
     * Turning "reset numbering each year" on or off changes which option holds
     * the counter, and the new one does not exist yet. Reading it raw answers 0
     * while sibling counters hold the real high-water mark, so the screen
     * offered "next: 00001" on a site already at 00500 and the setter accepted
     * 2, handing the next five hundred donations references that were already
     * printed on someone else's receipt. next() has always seeded through
     * seedFor; these two read past it.
     */
    private function currentCounter(string $scope, string $key): int
    {
        $stored = get_option($key, null);

        return $stored === null ? $this->seedFor($scope, $key) : (int) $stored;
    }

    /** Build the formatted reference string. Pure, no DB. */
    public function format(string $scope, int $year, int $counter): string
    {
        $s = $this->settings();
        // Coerce to the alphabet the REST routes accept ([A-Za-z0-9_-]); a stray
        // '.', '/' or '#' in the setting would mint references no admin or donor
        // route can match, permanently breaking detail/refund/confirm on them.
        $sep = $this->sanitizeToken((string) ($s['separator'] ?? '-'), '-');
        $prefix = $this->sanitizeToken((string) ($s['prefixes'][$scope] ?? strtoupper($scope)), strtoupper($scope));
        $padding = max(1, (int) ($s['padding'] ?? 5));

        $parts = [$prefix];
        if (! empty($s['include_year'])) {
            $parts[] = (string) $year;
        }
        $parts[] = str_pad((string) $counter, $padding, '0', STR_PAD_LEFT);

        return implode($sep, $parts);
    }

    private function sanitizeToken(string $raw, string $fallback): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_-]/', '', $raw);
        return $clean !== null && $clean !== '' ? $clean : $fallback;
    }

    /**
     * Atomic increment: one UPDATE stashes the new value in LAST_INSERT_ID(expr),
     * the follow-up SELECT reads it. Both run through DB::raw on the same $wpdb
     * connection, so the value survives; no read-modify-write, no lost updates.
     */
    private function nextCounter(string $scope, int $year): int
    {
        $key = $this->counterOption($scope, $year);

        if (get_option($key, null) === null) {
            add_option($key, (string) $this->seedFor($scope, $key), '', false);
        }

        DB::raw(
            'UPDATE ' . DB::getPrefix() . 'options
             SET option_value = LAST_INSERT_ID(CAST(option_value AS UNSIGNED) + 1)
             WHERE option_name = %s',
            [$key]
        );

        $result = DB::raw('SELECT LAST_INSERT_ID() AS id');
        $new    = (int) ($result['rows'][0]->id ?? 0);

        if ($new < 1) {
            throw new RuntimeException("ReferenceGenerator: counter update failed for {$key}");
        }

        wp_cache_delete($key, 'options');
        wp_cache_delete('alloptions', 'options');

        return $new;
    }

    /**
     * The counter is namespaced by whatever the printed reference is namespaced
     * by, and nothing else.
     *
     * reset_yearly and include_year are independent toggles, so keying the
     * counter off reset_yearly alone would change which counter is read without
     * changing what the reference looks like, re-issuing numbers already in use.
     * UNIQUE(reference) rejects the insert, and because next() runs inside the
     * donation's own transaction the increment rolls back with it, so the
     * counter never advances and every later donation fails the same way.
     *
     * A year-scoped counter is only sound when the year is in the reference to
     * tell the two sequences apart. reset_yearly without include_year is
     * therefore continuous numbering, the only reading that does not mint
     * DONO-00001 twice.
     */
    private function counterOption(string $scope, int $year): string
    {
        $s = $this->settings();

        return ! empty($s['reset_yearly']) && ! empty($s['include_year'])
            ? "dono_reference_counter_{$scope}_{$year}"
            : "dono_reference_counter_{$scope}";
    }

    /**
     * What a counter must clear before it issues its first number.
     *
     * Starting a fresh counter at zero is right on a new site and wrong when a
     * numbering setting has moved the generator onto a key it has never used:
     * zero walks back over references already on donations.
     *
     * Which counters it has to clear depends on which key it is, because that
     * decides which of them could have printed the same string:
     *
     *  - A year-scoped counter must be free to start at 1; that is the point of
     *    the yearly reset, and it is safe because the year is in the reference.
     *    The one counter that can already have issued a number inside *this*
     *    year is the continuous one, so that is all it clears.
     *  - The continuous counter can print any year's format, so it clears every
     *    counter this scope has ever kept.
     *
     * Counter values rather than parsed references on purpose: prefix,
     * separator and padding are all configurable, so the printed form is not
     * something to reverse-engineer.
     */
    private function seedFor(string $scope, string $key): int
    {
        $continuous = "dono_reference_counter_{$scope}";

        if ($key !== $continuous) {
            return (int) get_option($continuous, 0);
        }

        $result = DB::raw(
            'SELECT option_name, option_value FROM ' . DB::getPrefix() . "options
             WHERE option_name LIKE 'dono_reference_counter%'"
        );

        $high = 0;
        foreach (($result['rows'] ?? []) as $row) {
            $name = (string) ($row->option_name ?? '');
            // Exactly this scope, or this scope plus a year suffix. Filtered
            // here rather than in the LIKE, where every underscore in the
            // option name is a single-character wildcard.
            if ($name !== $continuous && ! str_starts_with($name, $continuous . '_')) {
                continue;
            }
            $high = max($high, (int) $row->option_value);
        }

        return $high;
    }

    /** @return array<string,mixed> */
    private function settings(): array
    {
        $stored = get_option(self::OPTION_SETTINGS, []);
        if (! is_array($stored)) {
            return self::DEFAULT_SETTINGS;
        }
        return array_replace_recursive(self::DEFAULT_SETTINGS, $stored);
    }

    private function normaliseScope(string $scope): string
    {
        $clean = preg_replace('/[^a-z0-9_]/', '', strtolower($scope));
        return $clean !== '' ? $clean : 'donation';
    }
}
