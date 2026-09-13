<?php

declare(strict_types=1);

namespace Gratora\Donors;

/**
 * Why one donor is on the at-risk list.
 *
 * Every row on that list is there for the same reason: last gave between 90 and
 * 180 days ago. Printing that back would restate the date column. What the
 * table cannot show is whether the silence is unusual FOR THIS DONOR, so the
 * verdict is either a recorded fact about their recurring plan or their silence
 * measured against their own average gap between donations.
 *
 * Pure: no I/O, no queries. atRiskCsv runs this up to 10,000 times.
 *
 * @since 1.0.0
 */
final class AtRiskReason
{
    public const PLAN_FAILING        = 'plan_failing';
    public const PLAN_PAUSED         = 'plan_paused';
    public const PLAN_CANCELLED      = 'plan_cancelled';
    public const PLAN_ACTIVE         = 'plan_active';
    public const PLAN_UNSTARTED      = 'plan_unstarted';
    public const FIRST_DONATION_ONLY = 'first_donation_only';
    public const NO_GAP_YET          = 'no_gap_yet';
    public const WELL_PAST_GAP       = 'well_past_gap';
    public const PAST_GAP            = 'past_gap';
    public const WITHIN_GAP          = 'within_gap';

    /** Two donations a fortnight apart are one episode, not a rhythm to measure. */
    private const MIN_SPAN_DAYS = 60;

    /** A plan that ended long before the last donation did not cause this silence. */
    private const CANCEL_GRACE_DAYS = 30;

    private const WELL_PAST_MULTIPLE = 2.0;

    /**
     * First match wins. Recorded facts about a plan outrank arithmetic over
     * dates, because they are events the site witnessed rather than inference.
     *
     * @param  array<string,mixed>                                              $row  a listAtRisk row
     * @param  array{failing:int,paused:int,live:int,unstarted:int,cancelled_at:?string}|null $plan batched plan state
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
                $since = self::days((string) $cancelled, $last);
                // Cancelled at or after the last donation, or shortly before it.
                if ($since === null || $since <= self::CANCEL_GRACE_DAYS) {
                    return self::verdict(self::PLAN_CANCELLED);
                }
            }

            if (! empty($plan['live'])) return self::verdict(self::PLAN_ACTIVE);

            // Last, because a donor holding one of these and a plan that is
            // collecting is described by the one that collects.
            if (! empty($plan['unstarted'])) return self::verdict(self::PLAN_UNSTARTED);
        }

        // Guard the count before any span math: n - 1 must never be zero, and
        // a drifted count of 0 must not read as "first donation".
        $count = (int) ($row['donations_count'] ?? 0);
        if ($count === 1) {
            return self::verdict(self::FIRST_DONATION_ONLY);
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
            self::PLAN_FAILING        => __('Recurring payments failing', 'gratora-donation-platform'),
            self::PLAN_PAUSED         => __('Recurring donation paused', 'gratora-donation-platform'),
            self::PLAN_CANCELLED      => __('Recurring plan cancelled', 'gratora-donation-platform'),
            self::PLAN_ACTIVE         => __('Recurring plan still active', 'gratora-donation-platform'),
            self::PLAN_UNSTARTED      => __('Recurring plan waiting on the gateway', 'gratora-donation-platform'),
            self::FIRST_DONATION_ONLY => __('First donation, never repeated', 'gratora-donation-platform'),
            self::NO_GAP_YET          => __('Not enough giving history to compare', 'gratora-donation-platform'),
            self::WELL_PAST_GAP       => __('Well past their average gap', 'gratora-donation-platform'),
            self::PAST_GAP            => __('Past their average gap', 'gratora-donation-platform'),
            self::WITHIN_GAP          => __('Within their average gap', 'gratora-donation-platform'),
        ];
    }

    /**
     * The pill colour for each verdict. Here rather than in the browser: a
     * verdict added on this side reached a screen keeping its own map as an
     * unstyled chip, which reads as no verdict at all.
     *
     * @return array<string,string>
     *
     * @since 1.0.0
     */
    public static function tones(): array
    {
        return [
            self::PLAN_FAILING        => 'is-error',
            self::PLAN_PAUSED         => 'is-info',
            self::PLAN_CANCELLED      => 'is-warn',
            self::PLAN_ACTIVE         => 'is-ok',
            self::PLAN_UNSTARTED      => 'is-warn',
            self::FIRST_DONATION_ONLY => 'is-violet',
            self::NO_GAP_YET          => 'is-muted',
            self::WELL_PAST_GAP       => 'is-warn',
            self::PAST_GAP            => 'is-info',
            self::WITHIN_GAP          => 'is-ok',
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
