<?php

declare(strict_types=1);

namespace Dono\Recurring;

use Dono\Vendor\Queryable\DB;
use Dono\Vendor\Queryable\QueryBuilder;
use Dono\Foundation\Helpers\Money;

/**
 * Aggregate queries over RecurringPlan rows. List views use the model directly.
 *
 * @since 1.0.0
 */
final class RecurringPlanRepository
{
    /**
     * Every status a plan can still take money in, and therefore the set the
     * campaign archive sweep cancels. A paused plan resumes on its resume_at
     * date and a past_due one is recovered by the gateway's own dunning, so any
     * figure an admin authorises a cancellation from has to cover all three.
     *
     * @var list<string>
     */
    public const LIVE_STATUSES = ['active', 'paused', 'past_due'];

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
     *
     * @since 1.0.0
     */
    private static function baseAmountExpr(): string
    {
        $base = esc_sql(Money::defaultCurrency());
        return "COALESCE(base_amount_cents, CASE WHEN currency = '{$base}' THEN amount_cents ELSE 0 END)";
    }

    /**
     * Plans whose base value is genuinely unknown, so callers can say the total
     * is partial.
     *
     * @since 1.0.0
     */
    private static function unconvertedExpr(): string
    {
        $base = esc_sql(Money::defaultCurrency());
        return "SUM(CASE WHEN base_amount_cents IS NULL AND currency <> '{$base}' THEN 1 ELSE 0 END)";
    }

    /**
     * Monthly-equivalent of a plan's base amount. interval_count=0 would divide
     * by zero.
     *
     * @since 1.0.0
     */
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

    /** @since 1.0.0 */
    public function findBySubscriptionId(string $gateway, string $subscriptionId): ?RecurringPlan
    {
        if ($subscriptionId === '') return null;
        return RecurringPlan::query()
            ->where('gateway', $gateway)
            ->where('gateway_subscription_id', $subscriptionId)
            ->get();
    }

    /**
     * Apply a successful renewal: bump counters and timestamps. Idempotency on
     * (plan, donation_id) is enforced by the caller; this method just persists.
     *
     * @since 1.0.0
     */
    public function recordPayment(RecurringPlan $plan, int $amountCents, string $occurredAt, ?string $nextPaymentAt = null): void
    {
        $update = [
            'last_payment_at' => $occurredAt,
            'updated_at'      => $occurredAt,
            // Consecutive failures, which is what dunning means and what
            // recordRecurringFailure documents `attempt` as. Without this reset
            // it becomes a lifetime tally: a plan that declined once and has
            // paid every month since keeps a permanent warning on the donor
            // screen, and its next decline escalates from the wrong attempt
            // number.
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

    /** @since 1.0.0 */
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
     *
     * @since 1.0.0
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
     * Live recurring plans attributed to a campaign, plus their base-currency
     * monthly-equivalent total. This is the number the archive dialog shows next
     * to "also cancel these subscriptions", so it counts exactly the rows the
     * archive sweep cancels: every LIVE_STATUSES plan, test plans excluded. A
     * count narrowed to status = active would have an admin authorise one
     * cancellation and get every paused and past_due donor cancelled and emailed
     * too, and a campaign whose live plans are all paused would report zero and
     * never offer the choice at all.
     *
     * A malformed interval_count of 0 still counts, because the sweep still
     * cancels it. It contributes nothing to mrr_cents, which is why that figure
     * is presented as approximate.
     *
     * @return array{count:int, mrr_cents:int, unconverted:int}
     *
     * @since 1.0.0
     */
    public function activeForCampaign(int $campaignId): array
    {
        $mrrExpr = self::mrrExpr();

        $row = DB::table('dono_recurring_plans')
            ->where('campaign_id', $campaignId)
            ->whereIn('status', self::LIVE_STATUSES)
            ->where('is_test', 0)
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
     * Shared filter set for the admin list and its count, so the two cannot
     * disagree about what is being looked at.
     *
     * @param array<string,mixed> $args
     *
     * @since 1.0.0
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

    /**
     * @param array<string,mixed> $args
     *
     * @since 1.0.0
     */
    public function countAdmin(array $args = []): int
    {
        return (int) $this->applyAdminFilters(RecurringPlan::query(), $args)->count();
    }

    /**
     * @param array<string,mixed> $args
     * @param array<string,mixed> $page
     * @return list<RecurringPlan>
     *
     * @since 1.0.0
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
        // so a plain sort opens the default view on dead plans and buries the
        // live ones.
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
     *
     * @since 1.0.0
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
     * The at-risk reason needs this per row, so a per-row lookup would be a
     * query per row. Served by the (donor_id, status) index.
     *
     * 'failing' is the same rule the Recurring admin filter uses: a decline can
     * sit on a plan the gateway still calls active, so the count is what marks
     * it, not the status.
     *
     * @param  array<int> $donorIds
     * @return array<int, array{failing:int, paused:int, live:int, cancelled_at:?string}>
     *
     * @since 1.0.0
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

    /**
     * The row scope every figure in recurringStats() starts from, so a caller
     * looking at test plans cannot be handed a mix of figures that count them
     * and figures that do not.
     *
     * @since 1.0.0
     */
    private static function statsQuery(bool $includeTest): QueryBuilder
    {
        $q = DB::table('dono_recurring_plans');

        return $includeTest ? $q : $q->where('is_test', 0);
    }

    /**
     * Recurring revenue health roll-up. Normalizes each active plan to its
     * monthly equivalent so MRR is comparable across cadences.
     *
     * $includeTest is the caller saying it is showing test plans. Every figure
     * then counts them, because a total that left them out while the list under
     * it names them reads as broken, and an org setting recurring up in test
     * mode has no other way to see that these figures compute at all.
     *
     * @return array{
     *   active_count:int,
     *   failing_count:int,
     *   mrr_cents:int,
     *   new_this_month:int,
     *   churned_this_month:int,
     *   churn_pct:float,
     *   active_amount_avg_cents:int,
     *   unconverted:int
     * }
     *
     * @since 1.0.0
     */
    public function recurringStats(string $today, bool $includeTest = false): array
    {
        $monthStart = (new \DateTimeImmutable($today))->modify('first day of this month')->format('Y-m-d 00:00:00');

        // Normalize each plan to the org base currency; see baseAmountExpr()
        // for why an unconverted plan contributes nothing.
        $amt     = self::baseAmountExpr();
        $mrrExpr = self::mrrExpr();

        // interval_count = 0 would be excluded from mrrExpr (NULLIF guard) but
        // still counted, so active_count and MRR would disagree; drop such
        // malformed plans from both.
        $active = self::statsQuery($includeTest)
            ->where('status', 'active')
            ->where('interval_count', 0, '>')
            ->selectRaw("COUNT(*) AS cnt, COALESCE({$mrrExpr}, 0) AS mrr, COALESCE(AVG({$amt}), 0) AS avg_amount, " . self::unconvertedExpr() . " AS unconverted")
            ->get();

        $newCount = (int) self::statsQuery($includeTest)
            ->where('started_at', $monthStart, '>=')
            ->count();

        $churnedCount = (int) self::statsQuery($includeTest)
            ->where('cancelled_at', $monthStart, '>=')
            ->where('status', 'cancelled')
            ->count();

        // Plans carrying a decline, whatever the gateway currently calls them:
        // a failure can sit on a plan still marked active until the gateway
        // gives up on it.
        $failingCount = (int) self::statsQuery($includeTest)
            ->where('failed_renewals_count', 0, '>')
            ->whereIn('status', self::LIVE_STATUSES)
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
