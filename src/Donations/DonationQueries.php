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
 * @version 1.0.0
 */
final class DonationQueries
{
    /**
     * Exclude test-mode donations. Returns the same query for fluent chaining.
     *
     * @template T
     * @param  T $q
     * @return T
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
     */
    public static function donationsOnly($q)
    {
        return self::live($q)->where('kind', 'donation');
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
}
