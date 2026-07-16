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
 * counter each Jan 1; false gives continuous numbering across years.
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

        $current = (int) get_option($key, 0);
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
        $key   = $this->counterOption($scope, $year);
        return (int) get_option($key, 0) + 1;
    }

    /** Build the formatted reference string. Pure, no DB. */
    public function format(string $scope, int $year, int $counter): string
    {
        $s = $this->settings();
        $sep = (string) ($s['separator'] ?? '-');
        $prefix = (string) ($s['prefixes'][$scope] ?? strtoupper($scope));
        $padding = max(1, (int) ($s['padding'] ?? 5));

        $parts = [$prefix];
        if (! empty($s['include_year'])) {
            $parts[] = (string) $year;
        }
        $parts[] = str_pad((string) $counter, $padding, '0', STR_PAD_LEFT);

        return implode($sep, $parts);
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
            add_option($key, '0', '', false);
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

    private function counterOption(string $scope, int $year): string
    {
        $s = $this->settings();
        // Yearly reset embeds the year so counters restart each Jan 1.
        return ! empty($s['reset_yearly'])
            ? "dono_reference_counter_{$scope}_{$year}"
            : "dono_reference_counter_{$scope}";
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
