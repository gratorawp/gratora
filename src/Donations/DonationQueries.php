<?php

declare(strict_types=1);

namespace Dono\Donations;

use DateTimeImmutable;
use DateTimeZone;
use Dono\Vendor\Queryable\DB;

/**
 * Shared query scopes for donation reads.
 *
 * `live()` is the single place the "exclude test-mode" rule lives, so all
 * aggregates, totals, and reports stay consistent. Raw-SQL callers add the
 * equivalent predicate inline.
 *
 * @since 1.0.0
 */
final class DonationQueries
{
    /**
     * Exclude test-mode donations. Returns the same query for fluent chaining.
     *
     * @template T
     * @param  T $q
     * @return T
     *
     * @since 1.0.0
     */
    public static function live($q)
    {
        return $q->where('is_test', 0);
    }

    /**
     * Rows that are donation history: real money, given as a gift.
     *
     * Event ticket orders ride the same table with kind='order'. They are a
     * purchase, not a donation, so they must stay out of donor lifetime totals,
     * campaign and fund rollups, donation reporting, receipts and the year-end
     * tax statement.
     *
     * Single owner of that rule: reach for it wherever "donations" is meant,
     * rather than repeating the where().
     *
     * @since 1.0.0
     */
    public static function donationsOnly($q)
    {
        return self::live($q)->where('kind', 'donation');
    }

    /**
     * Donation history, with test rows admitted only when the operator asked.
     *
     * The kind filter stays on both branches: "show me the test data" must
     * never quietly also mean "show me ticket orders", which are a purchase
     * rather than a gift and belong out of donation reporting either way.
     *
     * @template T
     * @param  T $q
     * @return T
     *
     * @since 1.0.0
     */
    public static function donationRows($q, bool $includeTest)
    {
        return $includeTest
            ? $q->where('kind', 'donation')
            : self::donationsOnly($q);
    }

    /**
     * How many real-looking donations the live figures are leaving out.
     *
     * Campaign and fund rollups are synced through donationsOnly(), so there is
     * no test-inclusive version of raised_cents to offer. What a screen can do
     * is say how much it is not counting, which is the difference between a
     * figure that reads zero and a screen that looks broken.
     *
     * @since 1.0.0
     */
    public static function hiddenTestCount(): int
    {
        return (int) DB::table('dono_donations')
            ->where('is_test', 1)
            ->where('kind', 'donation')
            ->whereIn('status', ['paid', 'partial_refund'])
            ->count();
    }

    /**
     * Rows whose base-currency value is unknown, so a total built on
     * netBaseExpr() is missing them.
     *
     * base_amount_cents is NULL when the donation's currency had no FX rate at
     * the time it was taken: money is never gated on reporting being
     * configured, so the donation is accepted and contributes 0. Counting them
     * lets a screen explain a campaign showing 22 donations raising what 21
     * raised.
     *
     * @since 1.0.0
     */
    public static function unconvertedExpr(): string
    {
        return 'SUM(CASE WHEN base_amount_cents IS NULL THEN 1 ELSE 0 END)';
    }

    /**
     * Correlated subquery: total succeeded refunds for the current
     * dono_donations row, expressed in the org/base currency. Refunds are
     * stored in the donation currency, so each is scaled by the donation's
     * fx_rate (base per donation unit; NULL when the donation already is base).
     * Use only where dono_donations is the main/correlated table.
     *
     * @since 1.0.0
     */
    public static function refundedBaseExpr(): string
    {
        $prefix    = DB::getPrefix();
        $refunds   = $prefix . 'dono_refunds';
        $donations = $prefix . 'dono_donations';
        // fx_rate is NULL only for a foreign donation we could not convert to
        // base (no rate available); such a row contributes nothing to base
        // totals, so its refunds must net to 0 too - scale by 0, not 1.
        //
        // Summed before rounding, not after. base_amount_cents is rounded once
        // from the whole amount, so rounding each refund separately and adding
        // them up can exceed the correctly-rounded value of the same total: at
        // 0.5107, two refunds of 50.00 give 2554 + 2554 = 5108 where the 100.00
        // they add up to is worth 5107. One rounding at the end, on one product.
        return "COALESCE((
            SELECT ROUND(SUM(amount_cents) * COALESCE({$donations}.fx_rate, 0))
            FROM {$refunds}
            WHERE donation_id = {$donations}.id AND status = 'succeeded'
        ), 0)";
    }

    /**
     * A donation's net contribution in the org/base currency: base amount minus
     * refunds (both in base). The canonical money expression every aggregate
     * should SUM, so cross-currency donations stay coherent and agree with the
     * campaign raised counter.
     *
     * @since 1.0.0
     */
    public static function netBaseExpr(): string
    {
        // base_amount_cents is NULL only for a foreign donation with no FX rate;
        // such a row has no known base value, so it must contribute 0 to base
        // sums rather than fold its raw foreign cents in (COALESCE to amount_cents
        // would corrupt every base-currency total). Base and converted rows
        // always have base_amount_cents set, so they are unaffected.
        return '(COALESCE(base_amount_cents, 0) - ' . self::refundedBaseExpr() . ')';
    }

    /**
     * A calendar year in the org's timezone, expressed as the UTC window to
     * compare paid_at against.
     *
     * paid_at is stored UTC, but every date printed on a statement or receipt
     * goes through wp_date() into the site's timezone. Filtering on a bare
     * "{year}-01-01 00:00:00" compares a local year against UTC timestamps, so
     * on any site west of UTC a late-December donation lands on the following
     * year's statement while its own line prints the December date, and the
     * donor's records disagree with the one the tax office sees.
     *
     * @return array{0:string,1:string} inclusive UTC start and end
     *
     * @since 1.0.0
     */
    public static function yearBoundsUtc(int $year): array
    {
        [$start, $end] = self::dayBoundsUtc(
            sprintf('%04d-01-01', $year),
            sprintf('%04d-12-31', $year)
        );

        return [(string) $start, (string) $end];
    }

    /**
     * A range of calendar days in the org's timezone, expressed as the UTC
     * window to compare paid_at against. Null passes through as "unbounded".
     *
     * Every reporting period is bounded through here so the revenue report,
     * its CSV, the dashboard ranges and the year-end statement all cut the
     * year in the same place. A period bounded in UTC instead puts a donation
     * taken at 23:30 on 31 December in one year on the org's books and in the
     * other on the donor's tax statement.
     *
     * @return array{0:?string,1:?string} inclusive UTC start and end
     *
     * @since 1.0.0
     */
    public static function dayBoundsUtc(?string $from, ?string $to): array
    {
        return [
            $from === null ? null : self::boundUtc($from, false),
            $to   === null ? null : self::boundUtc($to, true),
        ];
    }

    /**
     * SQL reading a UTC datetime column as the calendar date the org gave it
     * on, for the window between two UTC datetimes.
     *
     * CONVERT_TZ needs the server's named-timezone tables loaded, which no
     * install can be assumed to have, so the offset is resolved in PHP and
     * folded in as seconds. One branch per DST transition inside the window
     * keeps a spring-forward exact instead of shifting the days after it.
     *
     * @since 1.0.0
     */
    public static function localDateExpr(string $column, ?string $fromUtc, ?string $toUtc): string
    {
        $utcDate = "DATE({$column})";

        $start = $fromUtc === null ? null : strtotime($fromUtc . ' UTC');
        $end   = $toUtc   === null ? null : strtotime($toUtc . ' UTC');
        if (! is_int($start) || ! is_int($end) || $end < $start) {
            return $utcDate;
        }

        $transitions = self::siteTimezone()->getTransitions($start, $end);
        if ($transitions === false || $transitions === []) {
            return $utcDate;
        }

        $offset = (int) $transitions[0]['offset'];
        if (count($transitions) === 1) {
            return $offset === 0 ? $utcDate : "DATE(DATE_ADD({$column}, INTERVAL {$offset} SECOND))";
        }

        $case = '(CASE';
        foreach (array_slice($transitions, 1) as $t) {
            $case  .= sprintf(" WHEN {$column} < '%s' THEN %d", gmdate('Y-m-d H:i:s', (int) $t['ts']), $offset);
            $offset = (int) $t['offset'];
        }
        $case .= sprintf(' ELSE %d END)', $offset);

        return "DATE(DATE_ADD({$column}, INTERVAL {$case} SECOND))";
    }

    /** @since 1.0.0 */
    public static function siteTimezone(): DateTimeZone
    {
        return function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
    }

    /**
     * One end of a local calendar day as UTC. Anything that is not a plain
     * date is left for the database to reject rather than throwing part-way
     * through a report.
     *
     * @since 1.0.0
     */
    private static function boundUtc(string $value, bool $endOfDay): string
    {
        $value = trim($value);
        $day   = substr($value, 0, 10);
        $time  = $endOfDay ? ' 23:59:59' : ' 00:00:00';

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            return $value . $time;
        }

        return (new DateTimeImmutable($day . $time, self::siteTimezone()))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }
}
