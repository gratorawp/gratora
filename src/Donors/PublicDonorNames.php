<?php

declare(strict_types=1);

namespace FundKit\Donors;

/**
 * Return an empty name for hidden donors so public surfaces display Anonymous. Callers may
 * additionally omit hidden donors entirely.
 *
 * @since 1.0.0
 */
final class PublicDonorNames
{
    /**
     * @param array<int, int> $ids
     * @return array<int, string> donor id => printable name, '' when withheld
     */
    public static function forIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach (Donor::query()->whereIn('id', $ids)->getAll() as $donor) {
            $out[(int) $donor->id] = self::of($donor);
        }

        return $out;
    }

    public static function of(?Donor $donor): string
    {
        if ($donor === null || $donor->public_hidden_at !== null) {
            return '';
        }

        return trim((string) $donor->first_name . ' ' . (string) $donor->last_name);
    }
}
