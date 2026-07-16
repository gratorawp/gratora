<?php

declare(strict_types=1);

namespace Dono\Recurring;

use Dono\Vendor\Queryable\DB;

/**
 * Aggregate queries over RecurringPlan rows. List views use the model directly.
 *
 * @version 1.0.0
 */
final class RecurringPlanRepository
{
    public function findBySubscriptionId(string $gateway, string $subscriptionId): ?RecurringPlan
    {
        if ($subscriptionId === '') return null;
        return RecurringPlan::query()
            ->where('gateway', $gateway)
            ->where('gateway_subscription_id', $subscriptionId)
            ->get();
    }

    /**
     * Apply a successful renewal: bump counters and timestamps. Idempotent on
     * (plan, donation_id) is enforced by the caller; this method just persists.
     */
    public function recordPayment(RecurringPlan $plan, int $amountCents, string $occurredAt, ?string $nextPaymentAt = null): void
    {
        $update = [
            'last_payment_at' => $occurredAt,
            'updated_at'      => $occurredAt,
        ];
        if ($nextPaymentAt !== null) {
            $update['next_payment_at'] = $nextPaymentAt;
        }

        // Atomic increment avoids lost updates from concurrent webhooks.
        DB::table('dono_recurring_plans')->where('id', $plan->id)->update($update);
        DB::table('dono_recurring_plans')->where('id', $plan->id)->increment('payments_count');
        DB::table('dono_recurring_plans')->where('id', $plan->id)->increment('total_paid_cents', $amountCents);

        $plan->payments_count   = (int) $plan->payments_count + 1;
        $plan->total_paid_cents = (int) $plan->total_paid_cents + $amountCents;
        $plan->last_payment_at  = $occurredAt;
        if ($nextPaymentAt !== null) $plan->next_payment_at = $nextPaymentAt;
        $plan->updated_at = $occurredAt;
    }

    public function recordFailedRenewal(RecurringPlan $plan, string $occurredAt): void
    {
        DB::table('dono_recurring_plans')->where('id', $plan->id)->update(['updated_at' => $occurredAt]);
        DB::table('dono_recurring_plans')->where('id', $plan->id)->increment('failed_renewals_count');

        $plan->failed_renewals_count = (int) $plan->failed_renewals_count + 1;
        $plan->updated_at = $occurredAt;
    }

    public function markCancelled(RecurringPlan $plan, string $occurredAt, ?string $reason = null): void
    {
        if ($plan->status === 'cancelled') return;

        // Targeted column update, not a whole-row save(): Queryable's save()
        // rewrites payments_count / total_paid_cents from the loaded values,
        // so a cancellation that loaded its plan before a concurrent renewal's
        // atomic increment committed would silently lose that counter bump.
        RecurringPlan::query()
            ->where('id', $plan->id)
            ->where('status', 'cancelled', '!=')
            ->update([
                'status'              => 'cancelled',
                'cancelled_at'        => $occurredAt,
                'cancellation_reason' => $reason,
                'updated_at'          => $occurredAt,
            ]);

        // Reflect the transition on the in-memory model for the caller.
        $plan->status              = 'cancelled';
        $plan->cancelled_at        = $occurredAt;
        $plan->cancellation_reason = $reason;
        $plan->updated_at          = $occurredAt;
    }

    /**
     * Recurring revenue health roll-up. Normalises each active plan to its monthly
     * equivalent so MRR is comparable across cadences.
     *
     * @return array{
     *   active_count:int,
     *   mrr_cents:int,
     *   new_this_month:int,
     *   churned_this_month:int,
     *   churn_pct:float,
     *   active_amount_avg_cents:int
     * }
     */
    public function recurringStats(string $today): array
    {
        $monthStart = esc_sql((new \DateTimeImmutable($today))->modify('first day of this month')->format('Y-m-d 00:00:00'));

        // Normalize each plan to the org base currency (fx snapshot from the
        // first donation) so a foreign-currency plan does not inflate MRR; fall
        // back to the plan amount when there is no snapshot. interval_count=0
        // would div-by-zero; NULLIF guards against it.
        $amt = 'COALESCE(base_amount_cents, amount_cents)';
        $mrrExpr = "
            SUM(
                CASE interval_unit
                    WHEN 'month' THEN {$amt} / NULLIF(interval_count, 0)
                    WHEN 'week'  THEN {$amt} * 4.345 / NULLIF(interval_count, 0)
                    WHEN 'year'  THEN {$amt} / NULLIF(12 * interval_count, 0)
                    WHEN 'day'   THEN {$amt} * 30 / NULLIF(interval_count, 0)
                    ELSE {$amt}
                END
            )
        ";

        // Exclude test-mode plans from the live MRR widget.
        $active = DB::table('dono_recurring_plans')
            ->where('status', 'active')
            ->where('is_test', 0)
            ->selectRaw("COUNT(*) AS cnt, COALESCE({$mrrExpr}, 0) AS mrr, COALESCE(AVG({$amt}), 0) AS avg_amount")
            ->get();

        $newCount = (int) DB::table('dono_recurring_plans')
            ->whereRaw("started_at >= '{$monthStart}'")
            ->where('is_test', 0)
            ->count();

        $churnedCount = (int) DB::table('dono_recurring_plans')
            ->whereRaw("cancelled_at >= '{$monthStart}'")
            ->where('status', 'cancelled')
            ->where('is_test', 0)
            ->count();

        $activeCount = (int) ($active['cnt'] ?? 0);
        $churnBase   = $activeCount + $churnedCount;
        $churnPct    = $churnBase > 0 ? round(($churnedCount / $churnBase) * 100, 1) : 0.0;

        return [
            'active_count'             => $activeCount,
            'mrr_cents'                => (int) round((float) ($active['mrr'] ?? 0)),
            'new_this_month'           => $newCount,
            'churned_this_month'       => $churnedCount,
            'churn_pct'                => $churnPct,
            'active_amount_avg_cents'  => (int) round((float) ($active['avg_amount'] ?? 0)),
        ];
    }
}
