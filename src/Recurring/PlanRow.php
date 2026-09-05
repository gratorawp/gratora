<?php

declare(strict_types=1);

namespace FundKit\Recurring;

defined('ABSPATH') || exit;

use FundKit\Analytics\ErrorLog;
use FundKit\Analytics\Event;
use FundKit\Donations\Donation;
use FundKit\Gateways\GatewayManager;
use FundKit\Gateways\Sandbox\SandboxGateway;
use FundKit\Gateways\SupportsPaymentRetry;
use FundKit\Gateways\SupportsScheduleChange;
use FundKit\Vendor\Queryable\DB;

/**
 * The one shape a plan takes on a screen.
 *
 * The subscriptions list and the donor profile's recurring tab are the same
 * table with different columns showing, so a field added for one and not the
 * other is a tab that silently loses a feature.
 *
 * @since 1.0.0
 */
final class PlanRow
{
    /** How many problems a row carries before the rest are left to the log. */
    private const MAX_ERRORS = 10;

    /**
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    public static function common(RecurringPlan $p, GatewayManager $gateways): array
    {
        return self::build($p, $gateways, self::lastFailure($p), self::errors($p));
    }

    /**
     * The same shape for a whole page of plans, with the two per-row lookups
     * done once for the page. Mapping common() over a list costs four queries
     * a row, which is what made the subscriptions screen slow.
     *
     * @param list<RecurringPlan> $plans
     * @return array<int, array<string,mixed>> keyed by plan id
     *
     * @since 1.0.0
     */
    public static function commonMany(array $plans, GatewayManager $gateways): array
    {
        $failures = self::lastFailuresFor($plans);
        $errors   = self::errorsFor($plans);

        $out = [];
        foreach ($plans as $p) {
            $id       = (int) $p->id;
            $out[$id] = self::build($p, $gateways, $failures[$id] ?? null, $errors[$id] ?? []);
        }

        return $out;
    }

    /**
     * @param array{reference:string, reason:string, at:?string}|null $lastFailure
     * @param list<array{at:?string, source:string, origin:string, message:string}> $errors
     * @return array<string,mixed>
     */
    private static function build(RecurringPlan $p, GatewayManager $gateways, ?array $lastFailure, array $errors): array
    {
        $gateway   = $gateways->get((string) $p->gateway);
        $simulated = $gateway instanceof SandboxGateway;

        return [
            'id'                      => (int) $p->id,
            'gateway'                 => (string) $p->gateway,
            'gateway_subscription_id' => (string) $p->gateway_subscription_id,
            'amount_cents'            => (int) $p->amount_cents,
            'currency'                => (string) $p->currency,
            'interval_unit'           => (string) $p->interval_unit,
            'interval_count'          => (int) $p->interval_count,
            'status'                  => (string) $p->status,
            'started_at'              => $p->started_at,
            'next_payment_at'         => $p->next_payment_at,
            'last_payment_at'         => $p->last_payment_at,
            'resume_at'               => $p->resume_at,
            'cancelled_at'            => $p->cancelled_at,
            'cancellation_reason'     => $p->cancellation_reason,
            'payments_count'          => (int) $p->payments_count,
            'total_paid_cents'        => (int) $p->total_paid_cents,
            'failed_renewals_count'   => (int) $p->failed_renewals_count,
            'last_failure'            => $lastFailure,
            'errors'                  => $errors,
            'campaign_id'             => $p->campaign_id !== null ? (int) $p->campaign_id : null,
            'is_test'                 => (bool) $p->is_test,
            // PayPal owns its own retry schedule and exposes no endpoint for it,
            // so the action is offered per gateway rather than per status.
            'can_retry'               => $gateway instanceof SupportsPaymentRetry,
            // Most processors mint a mandate against a fixed cadence, so the
            // action is offered per gateway rather than per status.
            'can_change_interval'     => $gateway instanceof SupportsScheduleChange,
            'frequency'               => FrequencyMap::fromInterval(
                (string) $p->interval_unit,
                (int) $p->interval_count
            ),
            'frequency_options'       => FrequencyMap::recurringFrequencies(),
            // A sandbox cycle is minutes, not the donor's cadence, so the row
            // has to say so: a weekly plan whose next payment is five minutes
            // away otherwise reads as a bug rather than as a rehearsal.
            'simulated'               => $simulated,
            'simulated_cycle_minutes' => $simulated ? SandboxGateway::CYCLE_MINUTES : null,
        ];
    }

    /**
     * The renewal that was declined, which is the donor's card rather than the
     * site's plumbing.
     *
     * @return array{reference:string, reason:string, at:?string}|null
     *
     * @since 1.0.0
     */
    public static function lastFailure(RecurringPlan $p): ?array
    {
        if ((int) $p->failed_renewals_count < 1) {
            return null;
        }

        $donation = Donation::query()
            ->where('recurring_plan_id', (int) $p->id)
            ->where('status', 'failed')
            ->orderBy('created_at', 'DESC')
            ->get();

        if (! $donation) {
            return null;
        }

        return [
            'reference' => (string) $donation->reference,
            'reason'    => (string) $donation->failure_reason,
            'at'        => $donation->created_at,
        ];
    }

    /**
     * What went wrong on this plan that is not a declined renewal: a gateway
     * that could not be reached to cancel, a resume that failed.
     *
     * @return list<array{at:?string, source:string, origin:string, message:string}>
     *
     * @since 1.0.0
     */
    public static function errors(RecurringPlan $p): array
    {
        $rows = Event::query()
            ->where('recurring_plan_id', (int) $p->id)
            ->whereLike('type', ErrorLog::PREFIX . '%')
            ->orderBy('occurred_at', 'DESC')
            ->limit(self::MAX_ERRORS)
            ->getAll();

        return array_values(array_map(static fn ($e): array => self::errorRow($e), $rows));
    }

    /**
     * @return array{at:?string, source:string, origin:string, message:string}
     */
    private static function errorRow(Event $e): array
    {
        $payload = is_array($e->payload) ? $e->payload : [];
        $source  = (string) substr((string) $e->type, strlen(ErrorLog::PREFIX));

        return [
            'at' => $e->occurred_at,
            // Kept for support, who read these against the log.
            'source'  => $source,
            'origin'  => self::originLabel($source),
            'message' => (string) ($payload['message'] ?? ''),
        ];
    }

    /**
     * The newest declined renewal per plan, in one query for the page.
     *
     * @param list<RecurringPlan> $plans
     * @return array<int, array{reference:string, reason:string, at:?string}>
     */
    private static function lastFailuresFor(array $plans): array
    {
        $ids = [];
        foreach ($plans as $p) {
            if ((int) $p->failed_renewals_count >= 1) $ids[] = (int) $p->id;
        }
        if ($ids === []) return [];

        $prefix = DB::getPrefix();
        $in     = implode(',', array_map('intval', array_unique($ids)));

        // ROW_NUMBER rather than a LIMIT over the whole page: one plan with a
        // long decline history would otherwise eat the budget and leave the
        // rest of the page reading as if it had never failed.
        $rows = DB::raw(
            "SELECT id FROM (
                SELECT id, ROW_NUMBER() OVER (
                    PARTITION BY recurring_plan_id ORDER BY created_at DESC, id DESC
                ) AS rn
                FROM {$prefix}fundkit_donations
                WHERE recurring_plan_id IN ({$in}) AND status = 'failed'
             ) ranked WHERE rn = 1"
        )['rows'] ?? [];

        $donationIds = array_map(static fn ($r): int => (int) ($r->id ?? 0), $rows);
        if ($donationIds === []) return [];

        $out = [];
        foreach (Donation::query()->whereIn('id', $donationIds)->getAll() as $d) {
            $out[(int) $d->recurring_plan_id] = [
                'reference' => (string) $d->reference,
                'reason'    => (string) $d->failure_reason,
                'at'        => $d->created_at,
            ];
        }

        return $out;
    }

    /**
     * @param list<RecurringPlan> $plans
     * @return array<int, list<array{at:?string, source:string, origin:string, message:string}>>
     */
    private static function errorsFor(array $plans): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (RecurringPlan $p): int => (int) $p->id,
            $plans
        ))));
        if ($ids === []) return [];

        $prefix = DB::getPrefix();
        $in     = implode(',', array_map('intval', $ids));

        $rows = DB::raw(
            "SELECT id FROM (
                SELECT id, ROW_NUMBER() OVER (
                    PARTITION BY recurring_plan_id ORDER BY occurred_at DESC, id DESC
                ) AS rn
                FROM {$prefix}fundkit_events
                WHERE recurring_plan_id IN ({$in}) AND type LIKE %s
             ) ranked WHERE rn <= %d",
            [ErrorLog::PREFIX . '%', self::MAX_ERRORS]
        )['rows'] ?? [];

        $eventIds = array_map(static fn ($r): int => (int) ($r->id ?? 0), $rows);
        if ($eventIds === []) return [];

        $out = [];
        foreach (Event::query()->whereIn('id', $eventIds)->orderBy('occurred_at', 'DESC')->getAll() as $e) {
            $out[(int) $e->recurring_plan_id][] = self::errorRow($e);
        }

        return $out;
    }

    /**
     * Where the failure happened, in the words an admin uses for it.
     *
     * The source is an internal routing key. Read on a subscription, the
     * useful question it answers is which surface the action came from, since
     * that is what decides who to ask about it.
     *
     * @since 1.0.0
     */
    private static function originLabel(string $source): string
    {
        if (str_starts_with($source, 'gateway.') || str_starts_with($source, 'webhook.')) {
            $name = (string) preg_replace('/^(gateway|webhook)\./', '', $source);
            $name = (string) preg_replace('/\..*$/', '', $name);

            // A gateway's own name, which is not ours to translate.
            return $name !== '' ? ucfirst($name) : __('Payment provider', 'fundraising-toolkit');
        }

        return match ($source) {
            'portal.recurring' => __('Donor portal', 'fundraising-toolkit'),
            'admin.recurring'  => __('Admin', 'fundraising-toolkit'),
            'recurring'        => __('Scheduled run', 'fundraising-toolkit'),
            'command'          => __('WP-CLI', 'fundraising-toolkit'),
            default            => __('Site', 'fundraising-toolkit'),
        };
    }
}
