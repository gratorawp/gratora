<?php

declare(strict_types=1);

namespace Gratora\Analytics;

defined('ABSPATH') || exit;

/**
 * The record of what an admin did to a donation, kept apart from the analytics
 * history of the donation itself.
 *
 * Matched by exact type, never by the `donation.` prefix: DonationService
 * records nine analytics types under that prefix, and those rows carry the
 * donor detail this family deliberately does not.
 *
 * @since 1.0.0
 */
final class DonationAudit
{
    /** @var list<string> */
    public const TYPES = [
        'donation.trashed',
        'donation.restored',
        'donation.deleted',
        'donation.untrashed_by_settlement',
    ];

    /** @since 1.0.0 */
    public static function is(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /** Placeholder list for a raw IN clause. @since 1.0.0 */
    public static function placeholders(): string
    {
        return implode(', ', array_fill(0, count(self::TYPES), '%s'));
    }
}
