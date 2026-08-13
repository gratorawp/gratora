<?php

declare(strict_types=1);

namespace Dono\Donations;

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
     * on any site west of UTC a late-December gift lands on the following
     * year's statement while its own line prints the December date, and the
     * donor's records disagree with the one the tax office sees.
     *
     * @return array{0:string,1:string} inclusive UTC start and end
     *
     * @since 1.0.0
     */
    public static function yearBoundsUtc(int $year): array
    {
        $site = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
        $utc  = new \DateTimeZone('UTC');

        $start = new \DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $year), $site);
        $end   = new \DateTimeImmutable(sprintf('%04d-12-31 23:59:59', $year), $site);

        return [
            $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            $end->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }
}
