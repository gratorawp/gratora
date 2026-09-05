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
            'last_failure'            => self::lastFailure($p),
            'errors'                  => self::errors($p),
            'campaign_id'             => $p->campaign_id !== null ? (int) $p->campaign_id : null,
            'is_test'                 => (bool) $p->is_test,
            // PayPal owns its own retry schedule and exposes no endpoint for it,
            // so the action is offered per gateway rather than per status.
            'can_retry'               => $gateway instanceof SupportsPaymentRetry,
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

        return array_values(array_map(static function ($e): array {
            $payload = is_array($e->payload) ? $e->payload : [];
            $source  = (string) substr((string) $e->type, strlen(ErrorLog::PREFIX));

            return [
                'at' => $e->occurred_at,
                // Kept for support, who read these against the log.
                'source'  => $source,
                'origin'  => self::originLabel($source),
                'message' => (string) ($payload['message'] ?? ''),
            ];
        }, $rows));
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
