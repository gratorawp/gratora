<?php

declare(strict_types=1);

namespace Dono\Donors;

/**
 * Why one donor is on the at-risk list.
 *
 * Every row on that list is there for the same reason: last gave between 90 and
 * 180 days ago. Printing that back would restate the date column. What the
 * table cannot show is whether the silence is unusual FOR THIS DONOR, so the
 * verdict is either a recorded fact about their recurring plan or their silence
 * measured against their own average gap between gifts.
 *
 * Pure: no I/O, no queries. atRiskCsv runs this up to 10,000 times.
 *
 * @since 1.0.0
 */
final class AtRiskReason
{
    public const PLAN_FAILING    = 'plan_failing';
    public const PLAN_PAUSED     = 'plan_paused';
    public const PLAN_CANCELLED  = 'plan_cancelled';
    public const PLAN_ACTIVE     = 'plan_active';
    public const FIRST_GIFT_ONLY = 'first_gift_only';
    public const NO_GAP_YET      = 'no_gap_yet';
    public const WELL_PAST_GAP   = 'well_past_gap';
    public const PAST_GAP        = 'past_gap';
    public const WITHIN_GAP      = 'within_gap';

    /** Two gifts a fortnight apart are one episode, not a rhythm to measure. */
    private const MIN_SPAN_DAYS = 60;

    /** A plan that ended long before the last gift did not cause this silence. */
    private const CANCEL_GRACE_DAYS = 30;

    private const WELL_PAST_MULTIPLE = 2.0;

    /**
     * First match wins. Recorded facts about a plan outrank arithmetic over
     * dates, because they are events the site witnessed rather than inference.
     *
     * @param  array<string,mixed>                                              $row  a listAtRisk row
     * @param  array{failing:int,paused:int,live:int,cancelled_at:?string}|null $plan batched plan state
     * @return array{key:string, avg_gap_days:?int}
     *
     * @since 1.0.0
     */
    public static function classify(array $row, ?array $plan, string $today): array
    {
        $last = isset($row['last_donation_at']) ? (string) $row['last_donation_at'] : '';

        if ($plan !== null) {
            if (! empty($plan['failing'])) return self::verdict(self::PLAN_FAILING);
            if (! empty($plan['paused']))  return self::verdict(self::PLAN_PAUSED);

            $cancelled = $plan['cancelled_at'] ?? null;
            if ($cancelled !== null) {
                $sinceGift = self::days((string) $cancelled, $last);
                // Cancelled at or after the last gift, or shortly before it.
                if ($sinceGift === null || $sinceGift <= self::CANCEL_GRACE_DAYS) {
                    return self::verdict(self::PLAN_CANCELLED);
                }
            }

            if (! empty($plan['live'])) return self::verdict(self::PLAN_ACTIVE);
        }

        // Guard the count before any span math: n - 1 must never be zero, and
        // a drifted count of 0 must not read as "first gift".
        $count = (int) ($row['donations_count'] ?? 0);
        if ($count === 1) {
            return self::verdict(self::FIRST_GIFT_ONLY);
        }
        if ($count < 1) {
            return self::verdict(self::NO_GAP_YET);
        }

        $first = isset($row['first_donation_at']) ? (string) $row['first_donation_at'] : '';
        $span  = self::days($first, $last);
        if ($span === null || $span < self::MIN_SPAN_DAYS) {
            return self::verdict(self::NO_GAP_YET);
        }

        $avgGap = (int) round($span / ($count - 1));
        if ($avgGap < 1) {
            return self::verdict(self::NO_GAP_YET);
        }

        $silent = self::days($last, $today);
        if ($silent === null) {
            return self::verdict(self::NO_GAP_YET);
        }

        if ($silent >= (int) round($avgGap * self::WELL_PAST_MULTIPLE)) {
            return self::verdict(self::WELL_PAST_GAP, $avgGap);
        }
        if ($silent >= $avgGap) {
            return self::verdict(self::PAST_GAP, $avgGap);
        }

        return self::verdict(self::WITHIN_GAP, $avgGap);
    }

    /**
     * Spelled out rather than built from a variable, or none of it reaches a
     * .pot file.
     *
     * @return array<string,string>
     *
     * @since 1.0.0
     */
    public static function labels(): array
    {
        return [
            self::PLAN_FAILING    => __('Recurring payments failing', 'dono-fundraising-platform'),
            self::PLAN_PAUSED     => __('Recurring gift paused', 'dono-fundraising-platform'),
            self::PLAN_CANCELLED  => __('Recurring plan cancelled', 'dono-fundraising-platform'),
            self::PLAN_ACTIVE     => __('Recurring plan still active', 'dono-fundraising-platform'),
            self::FIRST_GIFT_ONLY => __('First gift, never repeated', 'dono-fundraising-platform'),
            self::NO_GAP_YET      => __('Not enough giving history to compare', 'dono-fundraising-platform'),
            self::WELL_PAST_GAP   => __('Well past their average gap', 'dono-fundraising-platform'),
            self::PAST_GAP        => __('Past their average gap', 'dono-fundraising-platform'),
            self::WITHIN_GAP      => __('Within their average gap', 'dono-fundraising-platform'),
        ];
    }

    /**
     * @return array{key:string, avg_gap_days:?int}
     *
     * @since 1.0.0
     */
    private static function verdict(string $key, ?int $avgGap = null): array
    {
        return ['key' => $key, 'avg_gap_days' => $avgGap];
    }

    /**
     * Whole days between two dates, floored to the day the way daysAgo() is.
     * strtotime on the date part rather than DateTimeImmutable: this runs once
     * per exported row.
     *
     * @since 1.0.0
     */
    private static function days(string $from, string $to): ?int
    {
        if ($from === '' || $to === '') {
            return null;
        }

        $a = strtotime(substr($from, 0, 10) . ' 00:00:00 UTC');
        $b = strtotime(substr($to, 0, 10) . ' 00:00:00 UTC');
        if ($a === false || $b === false) {
            return null;
        }

        return intdiv($b - $a, 86400);
    }
}
