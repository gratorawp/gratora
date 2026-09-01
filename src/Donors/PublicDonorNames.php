<?php

declare(strict_types=1);

namespace FundKit\Donors;

/**
 * The names a public page is allowed to print.
 *
 * Hiding a donor is the lever an admin is given when a name has to come off
 * the public pages: a harassment case, a doxxing attempt, a donor who asked to
 * be taken down. The rule lived in each block's render method instead - four
 * of them across two plugins - and two of the four forgot it, so a takedown
 * worked on one page and the name stayed up on another.
 *
 * A hidden donor gets '' here, which is the same value an unnamed donor
 * already gets, and every donor-facing surface already knows to print
 * "Anonymous" for that. So a caller is correct without knowing hiding exists.
 * DonorAvatars::urlsFor() has always worked this way for the picture; this is
 * the same guarantee for the name.
 *
 * Being stricter is still a caller's choice - the supporter wall drops the row
 * rather than masking it, and asks about public_hidden_at itself. Forgetting
 * to be strict at all is what this prevents.
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

    /** The printable name for one already-loaded donor. */
    public static function of(?Donor $donor): string
    {
        if ($donor === null || $donor->public_hidden_at !== null) {
            return '';
        }

        return trim((string) $donor->first_name . ' ' . (string) $donor->last_name);
    }
}
