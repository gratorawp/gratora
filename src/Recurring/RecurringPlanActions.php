<?php

declare(strict_types=1);

namespace Dono\Recurring;

use Dono\Analytics\EventRecorder;
use Dono\Currency\Currency;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\SubscriptionAware;
use Dono\Gateways\SupportsPaymentRetry;
use InvalidArgumentException;
use RuntimeException;

/**
 * Every change a plan can undergo, in one place.
 *
 * The donor portal, the admin screen and the command registry all route their
 * actions through here, so a plan cannot change without an event being written
 * for it.
 *
 * @since 1.0.0
 */
final class RecurringPlanActions
{
    /** A plan in one of these states accepts no further changes. */
    private const TERMINAL = ['cancelled', 'expired'];

    /** @since 1.0.0 */
    public function __construct(
        private GatewayManager $gateways,
        private RecurringCanceller $canceller,
        private EventRecorder $events,
    ) {
    }

    /**
     * Pause until an explicit date.
     *
     * The date is the argument, not a number of months: a caller that already
     * has one (the command registry takes `resumes_at` verbatim) must get that
     * date back, and rounding it into whole months turns a two-month pause into
     * three. Callers working in months use monthsFromNow().
     *
     * `resume_at` is always written, never just `next_payment_at`: PayPal's
     * suspend is indefinite and only RecurringResumer restarts it, and it keys
     * on `resume_at` alone. Without it a paused plan stops forever behind a
     * restart date the donor can see but nothing acts on.
     *
     * @since 1.0.0
     */
    public function pause(RecurringPlan $plan, string $resumesAt, RecurringPlanChange $change): void
    {
        $this->assertChangeable($plan);
        $this->assertGatewayReachable($plan, 'pause');

        $this->subscription($plan)?->pauseSubscription($plan, $resumesAt);

        $this->write($plan, [
            'status'          => 'paused',
            'next_payment_at' => $resumesAt,
            'resume_at'       => $resumesAt,
        ]);

        $change->detail += ['resumes_at' => $resumesAt];
        $this->finish($plan, $change, 'recurring.paused');
        do_action('dono.recurring.plan_paused', $plan, $resumesAt);
    }

    /**
     * A resume date the pause UI can express, clamped to what it offers.
     *
     * @since 1.0.0
     */
    public static function monthsFromNow(int $months): string
    {
        $months = max(1, min(12, $months));

        return gmdate('Y-m-d H:i:s', strtotime("+{$months} months"));
    }

    /** @since 1.0.0 */
    public function resume(RecurringPlan $plan, RecurringPlanChange $change): void
    {
        $this->assertChangeable($plan);
        $this->assertGatewayReachable($plan, 'resume');

        $this->subscription($plan)?->resumeSubscription($plan);

        // Clearing resume_at is the point: left set, the resumer lifts a pause
        // that is no longer in effect and the plan charges early.
        $this->write($plan, [
            'status'    => 'active',
            'resume_at' => null,
        ]);

        $this->finish($plan, $change, 'recurring.resumed');
        do_action('dono.recurring.plan_resumed', $plan);
    }

    /**
     * Skip exactly one cycle. The plan stays active on purpose: to the donor
     * this is a monthly donation missing one month, not a paused donation, and
     * the restart is driven by resume_at rather than by status.
     *
     * @since 1.0.0
     */
    public function skipNext(RecurringPlan $plan, RecurringPlanChange $change): void
    {
        $this->assertChangeable($plan);
        $this->assertGatewayReachable($plan, 'skip a payment on');

        if (! $plan->next_payment_at) {
            throw new InvalidArgumentException(esc_html__('This donation has no scheduled payment to skip.', 'dono-fundraising-platform'));
        }

        $unit   = in_array($plan->interval_unit, ['year', 'week'], true) ? $plan->interval_unit : 'month';
        $count  = max(1, (int) $plan->interval_count);
        $nextAt = gmdate('Y-m-d H:i:s', strtotime("+{$count} {$unit}", strtotime($plan->next_payment_at)));

        $this->subscription($plan)?->pauseSubscription($plan, $nextAt);

        $this->write($plan, [
            'next_payment_at' => $nextAt,
            'resume_at'       => $nextAt,
        ]);

        $change->detail = ['next_payment_at' => $nextAt];
        $this->finish($plan, $change, 'recurring.skipped');
        do_action('dono.recurring.plan_skipped', $plan);
    }

    /**
     * Change what the card is charged from the next cycle on.
     *
     * @throws \Dono\Gateways\SubscriptionChangeNeedsApproval When the processor
     *         accepted the change but is waiting on the donor to approve it, in
     *         which case nothing local is written: the plan must not claim an
     *         amount the card is not being charged.
     *
     * @since 1.0.0
     */
    public function changeAmount(RecurringPlan $plan, int $amountCents, RecurringPlanChange $change): void
    {
        $this->assertChangeable($plan);
        $this->assertGatewayReachable($plan, 'change the amount of');

        if ($amountCents < 50) {
            throw new InvalidArgumentException(esc_html__('Amount is too low.', 'dono-fundraising-platform'));
        }
        if ($amountCents > 99999999) {
            throw new InvalidArgumentException(esc_html__('Amount is too high.', 'dono-fundraising-platform'));
        }
        // Storage is major units x 100, so a fractional amount in a zero-decimal
        // currency rounds at the gateway and the row keeps a figure nobody is
        // charging, on every renewal.
        if (Currency::minorUnits((string) $plan->currency) === 0 && $amountCents % 100 !== 0) {
            throw new InvalidArgumentException(esc_html__('This currency does not support fractional amounts.', 'dono-fundraising-platform'));
        }

        $was = (int) $plan->amount_cents;
        if ($was === $amountCents) {
            return;
        }

        $this->subscription($plan)?->updateSubscriptionAmount($plan, $amountCents);

        $this->write($plan, [
            'amount_cents' => $amountCents,
            // Every base-currency rollup reads this ahead of amount_cents, so a
            // stale value pins MRR to a figure that is no longer charged.
            'base_amount_cents' => $plan->fx_rate !== null
                ? (int) round($amountCents * (float) $plan->fx_rate)
                : null,
        ]);

        $change->detail = ['from_cents' => $was, 'to_cents' => $amountCents, 'currency' => (string) $plan->currency];
        $this->finish($plan, $change, 'recurring.amount_changed');
        do_action('dono.recurring.plan_amount_changed', $plan);
    }

    /**
     * Ask the gateway to collect the outstanding renewal now.
     *
     * Nothing local is written. The gateway's webhook is what turns a
     * collection into a donation and clears the failure count; recording
     * success here would book money on the strength of an API call that can
     * still fail minutes later.
     *
     * @since 1.0.0
     */
    public function retryPayment(RecurringPlan $plan, RecurringPlanChange $change): void
    {
        $this->assertChangeable($plan);

        $gateway = $this->gateways->get((string) $plan->gateway);
        if (! $gateway instanceof SupportsPaymentRetry) {
            throw new InvalidArgumentException(esc_html(sprintf(
                /* translators: %s: the payment gateway name, e.g. PayPal. */
                __('%s does not allow a renewal to be retried on demand. It retries on its own schedule; ask the donor to update their card from the donor portal.', 'dono-fundraising-platform'),
                ucfirst((string) $plan->gateway)
            )));
        }

        $gateway->retryPayment($plan);

        $this->finish($plan, $change, 'recurring.retry_requested');
    }

    /**
     * Cancel through the canceller, which gates the local side effects on a
     * single winner so one cancellation email goes out even when the gateway's
     * own subscription.deleted webhook races this request. It records
     * recurring.cancelled itself, so this only adds who did it.
     *
     * @since 1.0.0
     */
    public function cancel(RecurringPlan $plan, ?string $reason, RecurringPlanChange $change): void
    {
        $this->assertChangeable($plan);

        $this->canceller->cancel($plan, $reason);

        $change->detail = ['reason' => $reason];
        if ($change->isByAdmin()) {
            $this->record($plan, $change, 'recurring.cancelled_by_admin');
        }
        do_action('dono.recurring.plan_changed', $plan, $change);
    }

    // ---------------------------------------------------------------- internals

    /** @since 1.0.0 */
    private function assertChangeable(RecurringPlan $plan): void
    {
        if (in_array((string) $plan->status, self::TERMINAL, true)) {
            throw new RuntimeException(esc_html__('This donation is no longer active.', 'dono-fundraising-platform'));
        }
    }

    /**
     * Null when the gateway has no subscriptions at all, as Offline does.
     *
     * @since 1.0.0
     */
    private function subscription(RecurringPlan $plan): ?SubscriptionAware
    {
        $gateway = $this->gateways->get((string) $plan->gateway);

        return $gateway instanceof SubscriptionAware ? $gateway : null;
    }

    /**
     * Refuse to change a plan whose processor is not there to be told.
     *
     * Offline is registered and simply has no subscriptions, so a local write
     * is the whole of it. A gateway that is absent entirely is a different
     * answer: Stripe and PayPal register only while their credentials are
     * stored, so a disconnected one means "cannot reach the processor", not
     * "this plan has no processor". Writing the row on that reading tells the
     * donor their donation is paused while the card keeps being charged.
     *
     * RecurringCanceller has guarded cancel this way from the start; these
     * three moved money the same way and did not.
     *
     * @throws GatewayUnreachable
     *
     * @since 1.0.0
     */
    private function assertGatewayReachable(RecurringPlan $plan, string $verb): void
    {
        if ($this->gateways->get((string) $plan->gateway) !== null) {
            return;
        }

        throw new GatewayUnreachable(esc_html(sprintf(
            'Cannot %1$s plan %2$d: the %3$s gateway is not available, so its subscription would keep billing.',
            $verb,
            (int) $plan->id,
            (string) $plan->gateway
        )));
    }

    /**
     * Column-scoped write. Saving the whole model would push back a snapshot
     * taken before the gateway call and silently undo any webhook that landed
     * in between.
     *
     * @param array<string,mixed> $columns
     *
     * @since 1.0.0
     */
    private function write(RecurringPlan $plan, array $columns): void
    {
        $columns['updated_at'] = gmdate('Y-m-d H:i:s');

        RecurringPlan::query()->where('id', (int) $plan->id)->update($columns);

        // Hooks and mail read the model, so keep the in-memory copy in step
        // with the row rather than leaving it stale.
        foreach ($columns as $column => $value) {
            $plan->$column = $value;
        }
    }

    /** @since 1.0.0 */
    private function finish(RecurringPlan $plan, RecurringPlanChange $change, string $eventType): void
    {
        $this->record($plan, $change, $eventType);

        // Carries the actor and the notify flag, which the plain per-action
        // hooks above cannot: those are a published signature.
        do_action('dono.recurring.plan_changed', $plan, $change);
    }

    /** @since 1.0.0 */
    private function record(RecurringPlan $plan, RecurringPlanChange $change, string $eventType): void
    {
        $this->events->record($eventType, [
            'donor_id'          => $plan->donor_id,
            'recurring_plan_id' => $plan->id,
            'form_id'           => $plan->form_id,
            'campaign_id'       => $plan->campaign_id,
            'amount_cents'      => $plan->amount_cents,
            'currency'          => $plan->currency,
            'user_id'           => $change->userId,
            'payload'           => [
                'gateway' => $plan->gateway,
                'by'      => $change->by,
            ] + $change->detail,
        ]);
    }
}
