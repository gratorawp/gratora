<?php

declare(strict_types=1);

namespace Gratora\Recurring;

use Gratora\Foundation\Helpers\Money;
use Gratora\Gateways\GatewayLabels;
use Gratora\Vendor\Queryable\DB;
use Gratora\Vendor\Queryable\QueryBuilder;

/** @since 1.0.0 */
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
     * Every status a cancellation has to reach.
     *
     * The live set plus pending, which is a plan PayPal has approved and is
     * already billing while its activation event is still in flight. Reporting
     * leaves it out, correctly, because it is not yet collecting on this site's
     * account of things. A cancellation cannot: a sweep that skips it leaves a
     * subscription billing a donor for a campaign the org has archived, and no
     * later event brings it back into scope.
     *
     * @var list<string>
     */
    public const CANCELLABLE_STATUSES = ['active', 'paused', 'past_due', 'pending'];

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
     * Track missing conversions so callers can label partial totals.
     *
     * @since 1.0.0
     */
    private static function unconvertedExpr(): string
    {
        $base = esc_sql(Money::defaultCurrency());
        return "SUM(CASE WHEN base_amount_cents IS NULL AND currency <> '{$base}' THEN 1 ELSE 0 END)";
    }

    /**
     * Guard zero intervals when calculating monthly equivalents.
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
     * The caller must enforce idempotency on (plan, donation_id).
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
            DB::table('gratora_recurring_plans')->where('id', $plan->id)->update($update);
            DB::table('gratora_recurring_plans')->where('id', $plan->id)->increment('payments_count');
            DB::table('gratora_recurring_plans')->where('id', $plan->id)->increment('total_paid_cents', $amountCents);
        });

        $plan->payments_count         = (int) $plan->payments_count + 1;
        $plan->total_paid_cents       = (int) $plan->total_paid_cents + $amountCents;
        $plan->failed_renewals_count  = 0;
        $plan->last_payment_at        = $occurredAt;
        if ($nextPaymentAt !== null) $plan->next_payment_at = $nextPaymentAt;
        $plan->updated_at = $occurredAt;
    }

    /**
     * Count one failed renewal, at most once per gateway delivery.
     *
     * @param  string $marker what names this decline. The gateway's id for the
     *   thing that declined where it has one, because two event types can
     *   report the same decline under two delivery ids; the delivery id
     *   otherwise. Empty means the caller cannot name it, and every call counts.
     * @return bool whether this call was the one that counted it, so the caller
     *   fires the notice and the log line exactly once for one decline.
     *
     * @since 1.0.0
     */
    public function recordFailedRenewal(RecurringPlan $plan, string $occurredAt, string $marker = ''): bool
    {
        if ($marker === '') {
            DB::table('gratora_recurring_plans')->where('id', $plan->id)->update(['updated_at' => $occurredAt]);
        } else {
            $seen = $this->recentFailureMarkers($plan);
            if (in_array($marker, $seen, true)) {
                return false;
            }

            // Compare and swap on the whole list, so two deliveries racing each
            // other cannot both read the same list and both write over it.
            $claim = DB::table('gratora_recurring_plans')
                ->where('id', $plan->id)
                ->where('last_failed_event_id', implode(' ', $seen))
                ->update([
                    'last_failed_event_id' => $this->withMarker($seen, $marker),
                    'updated_at'           => $occurredAt,
                ]);

            if ((int) ($claim->affectedRows ?? 0) === 0) {
                return false;
            }
        }

        DB::table('gratora_recurring_plans')->where('id', $plan->id)->increment('failed_renewals_count');

        // Read back rather than adding one to what was read before the
        // increment: the attempt number rides on this into the donor's notice,
        // and two deliveries racing would otherwise both call themselves the
        // first attempt and both send it.
        $fresh = RecurringPlan::query()->where('id', (int) $plan->id)->get();
        $plan->failed_renewals_count = $fresh instanceof RecurringPlan
            ? (int) $fresh->failed_renewals_count
            : (int) $plan->failed_renewals_count + 1;
        $plan->last_failed_event_id = $fresh instanceof RecurringPlan
            ? (string) $fresh->last_failed_event_id
            : $marker;
        $plan->updated_at = $occurredAt;

        return true;
    }

    /**
     * The deliveries this plan has already counted a failure for.
     *
     * A list rather than one slot, because a gateway that did not get a 2xx
     * retries for days: a redelivery of an earlier decline can land after a
     * later one has moved a single marker, and then counts again. Held in the
     * one column, oldest dropped, because what has to be remembered is only as
     * long as the gateway's own retry window.
     *
     * @return list<string>
     *
     * @since 1.0.0
     */
    private function recentFailureMarkers(RecurringPlan $plan): array
    {
        $fresh = RecurringPlan::query()->where('id', (int) $plan->id)->get();
        $raw   = $fresh instanceof RecurringPlan ? (string) $fresh->last_failed_event_id : '';

        return $raw === '' ? [] : array_values(array_filter(explode(' ', $raw)));
    }

    /**
     * @param  list<string> $seen
     * @since 1.0.0
     */
    private function withMarker(array $seen, string $marker): string
    {
        array_unshift($seen, $marker);

        // Trimmed to the column, newest kept: a marker that no longer fits is
        // older than anything a gateway is still retrying.
        $out = '';
        foreach ($seen as $one) {
            $next = $out === '' ? $one : $out . ' ' . $one;
            if (strlen($next) > 191) {
                break;
            }
            $out = $next;
        }

        return $out;
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
                // Nothing will be charged again, so there is no next payment
                // and no resume owed. Left standing, the donor's portal kept
                // showing a future charge date on a donation they had just
                // stopped, which reads as though the cancellation did nothing.
                'next_payment_at'     => null,
                'resume_at'           => null,
                'updated_at'          => $occurredAt,
            ]);

        // Reflect the transition on the in-memory model for the caller.
        $plan->status              = 'cancelled';
        $plan->cancelled_at        = $occurredAt;
        $plan->cancellation_reason = $reason;
        $plan->next_payment_at     = null;
        $plan->resume_at           = null;
        $plan->updated_at          = $occurredAt;

        return ($result->affectedRows ?? 0) > 0;
    }

    /**
     * Match the archive sweep’s cancellable statuses and exclude test plans. Invalid intervals
     * still count but contribute no MRR.
     *
     * @return array{count:int, mrr_cents:int, unconverted:int}
     *
     * @since 1.0.0
     */
    public function liveForCampaign(int $campaignId): array
    {
        $mrrExpr = self::mrrExpr();

        $row = DB::table('gratora_recurring_plans')
            ->where('campaign_id', $campaignId)
            ->whereIn('status', self::CANCELLABLE_STATUSES)
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
        // A cadence, not a unit: quarterly is ('month', 3) and biweekly is
        // ('week', 2), so filtering on the unit alone files both under the
        // monthly and weekly chips they are not.
        if (! empty($args['frequency'])) {
            $frequency = (string) $args['frequency'];
            if (in_array($frequency, FrequencyMap::recurringFrequencies(), true)) {
                [$unit, $count] = FrequencyMap::toStripe($frequency);
                $q = $q->where('interval_unit', $unit)->where('interval_count', $count);
            } else {
                // A cadence this product cannot name matches no plan. Widening
                // to every plan is how a filter comes to disagree with its chip.
                $q = $q->where('id', 0);
            }
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
        // whole book and read as "no such donor has plans" being false. A
        // numeric term also names a plan, so it matches either side.
        if (($args['search'] ?? '') !== '') {
            $ids    = array_values(array_filter(array_map('intval', (array) ($args['donor_ids'] ?? []))));
            $term   = trim((string) $args['search']);
            $planId = ctype_digit($term) ? (int) $term : 0;

            $q = $q->where(function ($sub) use ($ids, $planId, $term): void {
                $sub->whereIn('donor_id', $ids ?: [0]);

                if ($planId > 0) {
                    $sub->orWhere('id', $planId);
                    // A digit-only term names an id, so the handle stays exact:
                    // LIKE would let "12" pull in every handle containing it.
                    $sub->orWhere('gateway_subscription_id', $term);
                } else {
                    $sub->orWhereLike('gateway_subscription_id', $term);
                }
            });
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
        $rows = DB::table('gratora_recurring_plans')
            ->selectRaw('DISTINCT gateway')
            ->orderBy('gateway', 'ASC')
            ->getAll();

        $out = [];
        foreach ($rows as $row) {
            $slug = (string) ($row['gateway'] ?? '');
            if ($slug === '') {
                continue;
            }
            $out[] = ['value' => $slug, 'label' => GatewayLabels::for($slug)];
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
            $rows = DB::table('gratora_recurring_plans')
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
     * Use one test-plan scope across all statistics.
     *
     * @since 1.0.0
     */
    private static function statsQuery(bool $includeTest): QueryBuilder
    {
        $q = DB::table('gratora_recurring_plans');

        return $includeTest ? $q : $q->where('is_test', 0);
    }

    /**
     * Normalize active plans to monthly amounts. When showing test plans, include them in every
     * corresponding total.
     *
     * @return array{
     *   active_count:int,
     *   failing_count:int,
     *   failing_ever_count:int,
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

        // The Health filter on the list is deliberately wider: a plan that has
        // since ended can still be the one an admin is looking for. Published
        // so the screen can say so rather than leaving the two to disagree.
        $failingEverCount = (int) self::statsQuery($includeTest)
            ->where('failed_renewals_count', 0, '>')
            ->count();

        $activeCount = (int) ($active['cnt'] ?? 0);
        $churnBase   = $activeCount + $churnedCount;
        $churnPct    = $churnBase > 0 ? round(($churnedCount / $churnBase) * 100, 1) : 0.0;

        return [
            'active_count'             => $activeCount,
            'failing_count'            => $failingCount,
            'failing_ever_count'       => $failingEverCount,
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
