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
        return "COALESCE((
            SELECT SUM(ROUND(amount_cents * COALESCE({$donations}.fx_rate, 0)))
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
