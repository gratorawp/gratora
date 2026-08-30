<?php

declare(strict_types=1);

namespace FundKit\Foundation\Time;

defined('ABSPATH') || exit;

use DateTimeImmutable;
use DateTimeZone;
use FundKit\Donations\DonationQueries;
use Exception;

/**
 * An optional start and end date, as the admin picks them, resolved to the
 * instants they mean. Campaigns and funds both schedule this way.
 *
 * @since 1.0.0
 */
final class ScheduleWindow
{
    /**
     * The first instant the window is open, as UTC, or null when it has no
     * start date.
     *
     * @since 1.0.0
     */
    public static function startsAtUtc(?string $stamp): ?string
    {
        return self::localToUtc(self::startBoundary($stamp));
    }

    /**
     * The last instant the window is open, as UTC, or null when it has no end
     * date. Anything measuring "days left" against the clock belongs here too,
     * or it counts the remaining days of a different timezone's calendar.
     *
     * @since 1.0.0
     */
    public static function endsAtUtc(?string $stamp): ?string
    {
        return self::localToUtc(self::endBoundary($stamp));
    }

    /**
     * 'scheduled' before it opens, 'ended' after it closes, null while it runs.
     *
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
     * An end date is inclusive of the whole of that day: "ends 28 July" still
     * takes a donation at 10am on the 28th. The column is a datetime and the
     * schedule UI only emits dates, so a stored midnight means end-of-day;
     * reading it literally costs every window its final day.
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
     * The schedule is a local calendar: the admin picks dates in the org's
     * timezone and the screen reads them back the same way, so a boundary
     * compared as a UTC instant closes a window ending 31 December at 19:00 in
     * New York, losing the heaviest giving window of the year, and keeps a
     * Sydney one open into 1 January.
     *
     * A stamp too malformed to resolve is compared as written, which is the
     * database's problem to reject rather than a reason to shut the form.
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
