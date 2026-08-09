<?php

declare(strict_types=1);

namespace Dono\Recurring;

use Dono\Vendor\Queryable\DB;
use Dono\Foundation\Helpers\Money;

/**
 * Aggregate queries over RecurringPlan rows. List views use the model directly.
 *
 * @version 1.0.0
 */
final class RecurringPlanRepository
{
    /**
     * Base-currency amount of a plan.
     *
     * A plan with a snapshot uses it. A plan already IN the base currency needs
     * no snapshot and no rate: its own amount is the base amount, which matters
     * because the Give importer never writes a snapshot. Anything else is a
     * foreign plan we could not convert, so its base value is genuinely unknown
     * and must contribute 0: coalescing straight to amount_cents would report a
     * JPY 10,000/mo plan as 10,000 base, 186x too high. Callers get an
     * `unconverted` count so a partial figure can say so.
     */
    private static function baseAmountExpr(): string
    {
        $base = esc_sql(Money::defaultCurrency());
        return "COALESCE(base_amount_cents, CASE WHEN currency = '{$base}' THEN amount_cents ELSE 0 END)";
    }

    /** Plans whose base value is genuinely unknown, so callers can say the total is partial. */
    private static function unconvertedExpr(): string
    {
        $base = esc_sql(Money::defaultCurrency());
        return "SUM(CASE WHEN base_amount_cents IS NULL AND currency <> '{$base}' THEN 1 ELSE 0 END)";
    }

    /** Monthly-equivalent of a plan's base amount. interval_count=0 would divide by zero. */
    private static function mrrExpr(): string
    {
        $amt = self::baseAmountExpr();
        return "
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
    }

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
            // Consecutive failures, which is what dunning means and what
            // recordRecurringFailure documents `attempt` as. Only ever
            // incremented, it turned into a lifetime tally: a plan that
            // declined once and has paid every month since kept a permanent
            // warning on the donor screen, and its next decline escalated from
            // the wrong attempt number.
            'failed_renewals_count' => 0,
        ];
        if ($nextPaymentAt !== null) {
            $update['next_payment_at'] = $nextPaymentAt;
        }

        // Atomic increments avoid lost updates from concurrent webhooks; the
        // transaction keeps the three writes consistent if one fails mid-way.
        DB::transaction(function () use ($plan, $amountCents, $update): void {
            DB::table('dono_recurring_plans')->where('id', $plan->id)->update($update);
            DB::table('dono_recurring_plans')->where('id', $plan->id)->increment('payments_count');
            DB::table('dono_recurring_plans')->where('id', $plan->id)->increment('total_paid_cents', $amountCents);
        });

        $plan->payments_count         = (int) $plan->payments_count + 1;
        $plan->total_paid_cents       = (int) $plan->total_paid_cents + $amountCents;
        $plan->failed_renewals_count  = 0;
        $plan->last_payment_at        = $occurredAt;
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

    /**
     * @return bool True if this call won the active->cancelled transition, so
     *   the caller can fire cancellation side effects exactly once even when
     *   two webhook deliveries race (both may pre-read status='active').
     */
    public function markCancelled(RecurringPlan $plan, string $occurredAt, ?string $reason = null): bool
    {
        if ($plan->status === 'cancelled') return false;

        // Targeted column update, not a whole-row save(): Queryable's save()
        // rewrites payments_count / total_paid_cents from the loaded values,
        // so a cancellation that loaded its plan before a concurrent renewal's
        // atomic increment committed would silently lose that counter bump.
        $result = RecurringPlan::query()
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

        return ($result->affectedRows ?? 0) > 0;
    }

    /**
     * Active recurring plans attributed to a campaign, plus their base-currency
     * monthly-equivalent total. Drives the archive dialog's "N active recurring
     * donations (~$X/mo)" and matches what the archive cancel run would cancel.
     *
     * @return array{count:int, mrr_cents:int, unconverted:int}
     */
    public function activeForCampaign(int $campaignId): array
    {
        $mrrExpr = self::mrrExpr();

        // Live plans only, matching recurringStats and the archive-cancel
        // loop: test-mode plans are not real money and a malformed
        // interval_count of 0 would count without contributing MRR.
        $row = DB::table('dono_recurring_plans')
            ->where('campaign_id', $campaignId)
            ->where('status', 'active')
            ->where('is_test', 0)
            ->where('interval_count', 0, '>')
            ->selectRaw("COUNT(*) AS cnt, COALESCE({$mrrExpr}, 0) AS mrr, " . self::unconvertedExpr() . " AS unconverted")
            ->get();

        return [
            'count'       => (int) ($row['cnt'] ?? 0),
            'mrr_cents'   => (int) round((float) ($row['mrr'] ?? 0)),
            // Plans whose base value is unknown contribute nothing to mrr_cents,
            // so callers must be able to say the figure is partial.
            'unconverted' => (int) ($row['unconverted'] ?? 0),
        ];
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
    /**
     * Shared filter set for the admin list and its count, so the two cannot
     * disagree about what is being looked at.
     *
     * @param array<string,mixed> $args
     */
    private function applyAdminFilters(mixed $q, array $args): mixed
    {
        if (! empty($args['status'])) {
            $q = $q->where('status', (string) $args['status']);
        }
        if (! empty($args['gateway'])) {
            $q = $q->where('gateway', (string) $args['gateway']);
        }
        if (! empty($args['campaign_id'])) {
            $q = $q->where('campaign_id', (int) $args['campaign_id']);
        }
        if (! empty($args['interval'])) {
            $q = $q->where('interval_unit', (string) $args['interval']);
        }
        // Anything the gateway could not collect from. Not the same as
        // status = past_due: a plan can be carrying a decline before the
        // gateway has moved it, and a cancelled one can still be the reason
        // an admin is looking.
        if (! empty($args['failing'])) {
            $q = $q->where('failed_renewals_count', 0, '>');
        }
        if (empty($args['include_test'])) {
            $q = $q->where('is_test', 0);
        }

        // A search term that resolved to no donor must return nothing, not
        // everything: falling through would silently widen the result to the
        // whole book and read as "no such donor has plans" being false.
        if (($args['search'] ?? '') !== '') {
            $ids = array_values(array_filter(array_map('intval', (array) ($args['donor_ids'] ?? []))));
            $q = $q->whereIn('donor_id', $ids ?: [0]);
        }

        return $q;
    }

    /** @param array<string,mixed> $args */
    public function countAdmin(array $args = []): int
    {
        return (int) $this->applyAdminFilters(RecurringPlan::query(), $args)->count();
    }

    /**
     * @param array<string,mixed> $args
     * @param array<string,mixed> $page
     * @return list<RecurringPlan>
     */
    public function listAdmin(array $args = [], array $page = []): array
    {
        $sortable = [
            'next_payment_at', 'started_at', 'amount_cents', 'status',
            'total_paid_cents', 'payments_count', 'failed_renewals_count', 'id',
        ];
        $orderby = in_array((string) ($page['orderby'] ?? ''), $sortable, true)
            ? (string) $page['orderby']
            : 'next_payment_at';
        $order = strtolower((string) ($page['order'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

        $q = $this->applyAdminFilters(RecurringPlan::query(), $args);

        // A cancelled plan has no next payment, and NULL sorts first ascending,
        // so the default view opened on dead plans and buried the live ones.
        if ($orderby === 'next_payment_at') {
            $q = $q->orderByRaw("next_payment_at IS NULL ASC, next_payment_at {$order}");
        } else {
            $q = $q->orderBy($orderby, $order);
        }

        $q = $q->orderBy('id', 'DESC')
            ->limit((int) ($page['limit'] ?? 25))
            ->offset((int) ($page['offset'] ?? 0));

        return $q->getAll();
    }

    /**
     * Gateway slugs that actually appear on plans, as filter options.
     *
     * @return list<array{value:string,label:string}>
     */
    public function gatewaysInUse(): array
    {
        $rows = DB::table('dono_recurring_plans')
            ->selectRaw('DISTINCT gateway')
            ->orderBy('gateway', 'ASC')
            ->getAll();

        $out = [];
        foreach ($rows as $row) {
            $slug = (string) ($row['gateway'] ?? '');
            if ($slug === '') {
                continue;
            }
            $out[] = ['value' => $slug, 'label' => ucfirst($slug)];
        }

        return $out;
    }

    /**
     * Plan state for a set of donors, in one grouped query.
     *
     * The at-risk reason needs this per row, and a lookup per row is a query
     * per row on a screen that was tuned to 8ms by removing exactly that.
     * Served by the (donor_id, status) index.
     *
     * 'failing' is the same rule the Recurring admin filter uses: a decline can
     * sit on a plan the gateway still calls active, so the count is what marks
     * it, not the status.
     *
     * @param  array<int> $donorIds
     * @return array<int, array{failing:int, paused:int, live:int, cancelled_at:?string}>
     */
    public function stateForDonors(array $donorIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $donorIds))));
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $rows = DB::table('dono_recurring_plans')
                ->whereIn('donor_id', $chunk)
                ->where('is_test', 0)
                ->selectRaw("
                    donor_id,
                    MAX(CASE WHEN failed_renewals_count > 0
                              AND status IN ('active','past_due','paused') THEN 1 ELSE 0 END) AS failing,
                    MAX(CASE WHEN status = 'paused' THEN 1 ELSE 0 END) AS paused,
                    MAX(CASE WHEN status IN ('active','past_due') THEN 1 ELSE 0 END) AS live,
                    MAX(CASE WHEN status = 'cancelled' THEN cancelled_at END) AS cancelled_at
                ")
                ->groupByRaw('donor_id')
                ->getAll();

            foreach ($rows as $r) {
                $out[(int) $r['donor_id']] = [
                    'failing'      => (int) $r['failing'],
                    'paused'       => (int) $r['paused'],
                    'live'         => (int) $r['live'],
                    // markCancelled always writes cancelled_at with the status,
                    // so there is no reason to fall back to updated_at, which a
                    // bulk job would re-date into the grace window.
                    'cancelled_at' => $r['cancelled_at'] !== null ? (string) $r['cancelled_at'] : null,
                ];
            }
        }

        return $out;
    }

    public function recurringStats(string $today): array
    {
        $monthStart = esc_sql((new \DateTimeImmutable($today))->modify('first day of this month')->format('Y-m-d 00:00:00'));

        // Normalize each plan to the org base currency; see baseAmountExpr()
        // for why an unconverted plan contributes nothing.
        $amt     = self::baseAmountExpr();
        $mrrExpr = self::mrrExpr();

        // Exclude test-mode plans from the live MRR widget.
        // interval_count = 0 would be excluded from mrrExpr (NULLIF guard) but
        // still counted, so active_count and MRR would disagree; drop such
        // malformed plans from both.
        $active = DB::table('dono_recurring_plans')
            ->where('status', 'active')
            ->where('is_test', 0)
            ->where('interval_count', 0, '>')
            ->selectRaw("COUNT(*) AS cnt, COALESCE({$mrrExpr}, 0) AS mrr, COALESCE(AVG({$amt}), 0) AS avg_amount, " . self::unconvertedExpr() . " AS unconverted")
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

        // Plans carrying a decline, whatever the gateway currently calls them:
        // a failure can sit on a plan still marked active until the gateway
        // gives up on it.
        $failingCount = (int) DB::table('dono_recurring_plans')
            ->where('failed_renewals_count', 0, '>')
            ->whereIn('status', ['active', 'past_due', 'paused'])
            ->where('is_test', 0)
            ->count();

        $activeCount = (int) ($active['cnt'] ?? 0);
        $churnBase   = $activeCount + $churnedCount;
        $churnPct    = $churnBase > 0 ? round(($churnedCount / $churnBase) * 100, 1) : 0.0;

        return [
            'active_count'             => $activeCount,
            'failing_count'            => $failingCount,
            'mrr_cents'                => (int) round((float) ($active['mrr'] ?? 0)),
            'new_this_month'           => $newCount,
            'churned_this_month'       => $churnedCount,
            'churn_pct'                => $churnPct,
            'active_amount_avg_cents'  => (int) round((float) ($active['avg_amount'] ?? 0)),
            // Plans with no base-currency snapshot contribute nothing to
            // mrr_cents, so the dashboard can say the figure is partial rather
            // than presenting an under-count as fact.
            'unconverted'              => (int) ($active['unconverted'] ?? 0),
        ];
    }
}
