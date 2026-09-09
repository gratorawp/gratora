<?php

declare(strict_types=1);

namespace Gratora\Foundation\Time;

defined('ABSPATH') || exit;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Gratora\Donations\DonationQueries;

/**
 * Resolve campaign and fund date windows.
 *
 * @since 1.0.0
 */
final class ScheduleWindow
{
    /**
     * UTC opening instant, or null without a start date.
     *
     * @since 1.0.0
     */
    public static function startsAtUtc(?string $stamp): ?string
    {
        return self::localToUtc(self::startBoundary($stamp));
    }

    /**
     * UTC closing instant, or null without an end date. Use this calendar for days-left
     * calculations too.
     *
     * @since 1.0.0
     */
    public static function endsAtUtc(?string $stamp): ?string
    {
        return self::localToUtc(self::endBoundary($stamp));
    }

    /**
     * @return null|'scheduled'|'ended'
     *
     * @since 1.0.0
     */
    public static function state(?string $startsAt, ?string $endsAt, ?string $now = null): ?string
    {
        $now ??= gmdate('Y-m-d H:i:s');

        $starts = self::startsAtUtc($startsAt);
        if ($starts !== null && $starts > $now) {
            return 'scheduled';
        }

        $ends = self::endsAtUtc($endsAt);
        if ($ends !== null && $ends < $now) {
            return 'ended';
        }

        return null;
    }

    /** @since 1.0.0 */
    private static function startBoundary(?string $stamp): ?string
    {
        $stamp = self::clean($stamp);
        if ($stamp === null) return null;
        return strlen($stamp) <= 10 ? $stamp . ' 00:00:00' : $stamp;
    }

    /**
     * Treat stored midnight as the end of that local day; schedule inputs are date-only.
     *
     * @since 1.0.0
     */
    private static function endBoundary(?string $stamp): ?string
    {
        $stamp = self::clean($stamp);
        if ($stamp === null) return null;
        if (strlen($stamp) <= 10) return $stamp . ' 23:59:59';
        return substr($stamp, 11) === '00:00:00'
            ? substr($stamp, 0, 10) . ' 23:59:59'
            : $stamp;
    }

    /**
     * Resolve schedule dates in the org timezone. Preserve malformed values for database
     * validation.
     *
     * @since 1.0.0
     */
    private static function localToUtc(?string $stamp): ?string
    {
        if ($stamp === null) return null;

        try {
            return (new DateTimeImmutable($stamp, DonationQueries::siteTimezone()))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return $stamp;
        }
    }

    /** @since 1.0.0 */
    private static function clean(?string $stamp): ?string
    {
        $stamp = trim(str_replace('T', ' ', (string) $stamp));
        return $stamp === '' ? null : $stamp;
    }
}
