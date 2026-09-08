<?php

declare(strict_types=1);

namespace FundKit\Recurring;

use FundKit\Analytics\EventRecorder;
use FundKit\Currency\Currency;
use FundKit\Gateways\GatewayManager;
use FundKit\Gateways\SubscriptionAware;
use FundKit\Gateways\SupportsPaymentRetry;
use FundKit\Gateways\SupportsScheduleChange;
use InvalidArgumentException;

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
        $resumesAt = self::resumeDate($resumesAt);

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
        do_action('fundkit.recurring.plan_paused', $plan, $resumesAt);
    }

    /**
     * A date the plan can actually be restarted on.
     *
     * WordPress removes STRICT_TRANS_TABLES, so MySQL stores a datetime it
     * cannot parse as '0000-00-00 00:00:00' rather than refusing it. That is
     * not null and it is already past, so RecurringResumer matched the plan on
     * its next daily run and lifted the pause: a three-month pause the org
     * authorised lasted under a day and the donor was charged on the next
     * cycle, with next_payment_at left sorting to the top of the admin list as
     * a broken date.
     *
     * @throws InvalidArgumentException
     *
     * @since 1.0.0
     */
    public static function resumeDate(string $resumesAt): string
    {
        $at = strtotime($resumesAt);
        if ($at === false) {
            throw new InvalidArgumentException(
                esc_html__('That is not a date this donation can restart on.', 'fundraising-toolkit')
            );
        }

        $now = time();
        if ($at <= $now) {
            throw new InvalidArgumentException(
                esc_html__('A donation can only be paused until a date in the future.', 'fundraising-toolkit')
            );
        }

        // The ceiling the pause UI offers, so no caller can set a pause that
        // nothing is ever going to lift.
        return gmdate('Y-m-d H:i:s', min($at, (int) strtotime('+12 months', $now)));
    }

    /**
     * Clamp resume dates to supported pause durations.
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
        // After the reachability check, not before: an active plan on an absent
        // gateway must still answer GatewayUnreachable.
        $this->assertGatewayReachable($plan, 'resume');

        if ((string) $plan->status !== 'paused') {
            throw new PlanChangeRefused(esc_html__('This donation is not paused.', 'fundraising-toolkit'));
        }

        $this->subscription($plan)?->resumeSubscription($plan);

        // Clearing resume_at is the point: left set, the resumer lifts a pause
        // that is no longer in effect and the plan charges early.
        $this->write($plan, [
            'status'    => self::resumedStatus($plan),
            'resume_at' => null,
        ]);

        $this->finish($plan, $change, 'recurring.resumed');
        do_action('fundkit.recurring.plan_resumed', $plan);
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
            throw new InvalidArgumentException(esc_html__('This donation has no scheduled payment to skip.', 'fundraising-toolkit'));
        }

        // The stored date is the base of the arithmetic and the result goes
        // straight into resume_at, which is the one column the resumer reads. A
        // row whose next_payment_at will not parse gives strtotime false, and
        // "+1 month" from false is a month from the epoch: a resume date fifty
        // years in the past, which the resumer acts on immediately, so a skip
        // silently becomes no skip at all.
        $from = strtotime((string) $plan->next_payment_at);
        if ($from === false) {
            throw new InvalidArgumentException(
                esc_html__('This donation has no scheduled payment to skip.', 'fundraising-toolkit')
            );
        }

        $unit   = in_array($plan->interval_unit, ['year', 'week'], true) ? $plan->interval_unit : 'month';
        $count  = max(1, (int) $plan->interval_count);

        // Through the same guard pause() uses, so one cycle forward can never
        // land in the past or beyond the ceiling the UI offers.
        $nextAt = self::resumeDate(gmdate('Y-m-d H:i:s', strtotime("+{$count} {$unit}", $from)));

        $this->subscription($plan)?->pauseSubscription($plan, $nextAt);

        $this->write($plan, [
            'next_payment_at' => $nextAt,
            'resume_at'       => $nextAt,
        ]);

        $change->detail = ['next_payment_at' => $nextAt];
        $this->finish($plan, $change, 'recurring.skipped');
        do_action('fundkit.recurring.plan_skipped', $plan);
    }

    /**
     * Change what the card is charged from the next cycle on.
     *
     * @throws \FundKit\Gateways\SubscriptionChangeNeedsApproval When the processor
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
            throw new InvalidArgumentException(esc_html__('Amount is too low.', 'fundraising-toolkit'));
        }
        if ($amountCents > 99999999) {
            throw new InvalidArgumentException(esc_html__('Amount is too high.', 'fundraising-toolkit'));
        }
        // Storage is major units x 100, so a fractional amount in a zero-decimal
        // currency rounds at the gateway and the row keeps a figure nobody is
        // charging, on every renewal.
        if (Currency::minorUnits((string) $plan->currency) === 0 && $amountCents % 100 !== 0) {
            throw new InvalidArgumentException(esc_html__('This currency does not support fractional amounts.', 'fundraising-toolkit'));
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
        do_action('fundkit.recurring.plan_amount_changed', $plan);
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
                __('%s does not allow a renewal to be retried on demand. It retries on its own schedule; ask the donor to update their card from the donor portal.', 'fundraising-toolkit'),
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
        do_action('fundkit.recurring.plan_changed', $plan, $change);
    }


    /** @since 1.0.0 */
    /**
     * Move the plan onto another cadence at the processor, then write what the
     * processor said it will charge next.
     *
     * Takes a frequency rather than an interval pair on purpose: a processor
     * accepts any pair, and this product can only name five of them. A plan put
     * on a cadence outside that list has no label on any screen.
     *
     * @throws InvalidArgumentException when the frequency is one this product cannot name.
     * @throws PlanChangeRefused when the gateway cannot change a cadence at all.
     *
     * @since 1.0.0
     */
    public function changeInterval(RecurringPlan $plan, string $frequency, RecurringPlanChange $change): void
    {
        // Input first: a schedule this site cannot name is wrong whatever the
        // processor is doing, and answering with a gateway error would send an
        // admin looking at the wrong thing.
        if (! in_array($frequency, FrequencyMap::recurringFrequencies(), true)) {
            throw new InvalidArgumentException(esc_html__('That is not a schedule this site offers.', 'fundraising-toolkit'));
        }

        $this->assertChangeable($plan);
        $this->assertGatewayReachable($plan, 'change the schedule of');

        [$unit, $count] = FrequencyMap::toStripe($frequency);

        $wasUnit  = (string) $plan->interval_unit;
        $wasCount = (int) $plan->interval_count;
        if ($wasUnit === $unit && $wasCount === $count) {
            return;
        }

        $gateway = $this->gateways->get((string) $plan->gateway);
        if (! $gateway instanceof SupportsScheduleChange) {
            throw new PlanChangeRefused(esc_html__('This payment provider cannot change how often a donation is taken. Cancel it and start a new one.', 'fundraising-toolkit'));
        }

        $schedule = $gateway->updateSubscriptionSchedule($plan, (int) $plan->amount_cents, $unit, $count);

        $columns = [
            'interval_unit'  => $unit,
            'interval_count' => $count,
        ];

        // Only what the processor actually said, and only where the local date
        // is not standing in for something else. A paused plan's next payment
        // is paired with resume_at, and overwriting it here would restart a
        // donor the org had agreed to pause.
        if ($schedule->nextPaymentAt !== null && $plan->resume_at === null && $plan->status !== 'paused') {
            $columns['next_payment_at'] = $schedule->nextPaymentAt;
        }

        $this->write($plan, $columns);

        $change->detail = [
            'from' => FrequencyMap::fromInterval($wasUnit, $wasCount) ?? "{$wasCount} {$wasUnit}",
            'to'   => $frequency,
        ];
        $this->finish($plan, $change, 'recurring.interval_changed');
        do_action('fundkit.recurring.plan_interval_changed', $plan);
    }

    /**
     * The status a lifted pause returns to.
     *
     * A pause does not collect the renewal a gateway declined, so a plan still
     * carrying one is not active again: the donor screen's banner, the Past due
     * filter and the profile's own count all key on the status, and
     * recordPayment clears the counter on the next success.
     *
     * @since 1.0.0
     */
    public static function resumedStatus(RecurringPlan $plan): string
    {
        return (int) $plan->failed_renewals_count > 0 ? 'past_due' : 'active';
    }

    private function assertChangeable(RecurringPlan $plan): void
    {
        if (in_array((string) $plan->status, self::TERMINAL, true)) {
            throw new PlanChangeRefused(esc_html__('This donation is no longer active.', 'fundraising-toolkit'));
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
            'Cannot %1$s subscription %2$s (%3$s, plan #%4$d): the gateway is not available, so it would keep billing.',
            $verb,
            (string) ($plan->gateway_subscription_id ?: 'unlinked'),
            (string) $plan->gateway,
            (int) $plan->id
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
        do_action('fundkit.recurring.plan_changed', $plan, $change);
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
