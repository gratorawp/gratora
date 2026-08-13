<?php

declare(strict_types=1);

namespace Dono\Gateways\Sandbox;

use Dono\Donations\Donation;
use Dono\Foundation\Time\Clock;
use Dono\Gateways\GatewayConfirmResult;
use Dono\Gateways\GatewayIntentResult;
use Dono\Gateways\PaymentGateway;
use Dono\Gateways\RefundResult;
use Dono\Gateways\SubscriptionAware;
use Dono\Gateways\SubscriptionCreator;
use Dono\Gateways\WebhookOutcome;
use Dono\Recurring\FrequencyMap;
use Dono\Recurring\RecurringPlan;
use Dono\Recurring\RecurringPlanRepository;
use DateTimeImmutable;
use WP_REST_Request;

/**
 * Simulated gateway for rehearsing the donation flow. Registered only when
 * org-wide test mode is on, so it never reaches production donors. Moves no
 * money: createIntent returns a synthetic id and confirm succeeds immediately.
 *
 * @since 1.0.0
 */
final class SandboxGateway implements PaymentGateway, SubscriptionAware, SubscriptionCreator
{
    /** @since 1.0.0 */
    public const SUB_PREFIX = 'sandbox_sub_';

    /**
     * One billing cycle, in minutes. A rehearsal nobody can watch is not a
     * rehearsal, so a sandbox cycle is minutes rather than the donor's real
     * cadence. interval_unit and interval_count still carry the cadence the
     * donor chose, so MRR and the "every week" label stay true; only the
     * moment of the next simulated charge is compressed.
     *
     * @since 1.0.0
     */
    public const CYCLE_MINUTES = 5;

    /** @since 1.0.0 */
    public function __construct(private Clock $clock, private RecurringPlanRepository $plans)
    {
    }

    /** @since 1.0.0 */
    public static function nextCycleAt(DateTimeImmutable $from, int $intervalCount): string
    {
        return $from->modify('+' . (max(1, $intervalCount) * self::CYCLE_MINUTES) . ' minutes')
            ->format('Y-m-d H:i:s');
    }

    /** @since 1.0.0 */
    public function id(): string
    {
        return 'sandbox';
    }

    /** @since 1.0.0 */
    public function label(): string
    {
        return __('Test donation', 'dono-fundraising-platform');
    }

    /** @since 1.0.0 */
    public function description(): string
    {
        return __('Simulated payment for testing. No real money moves and the form is in test mode.', 'dono-fundraising-platform');
    }

    /** @since 1.0.0 */
    public function frequencies(): array
    {
        return ['one_time', 'recurring'];
    }

    /** @since 1.0.0 */
    public function paymentMethods(): array
    {
        return ['test'];
    }

    /** @since 1.0.0 */
    public function countries(): array
    {
        return ['*'];
    }

    /** @since 1.0.0 */
    public function currencies(): array
    {
        return ['*'];
    }

    /** @since 1.0.0 */
    public function canCharge(): bool
    {
        return true;
    }

    /** @since 1.0.0 */
    public function createIntent(Donation $donation): GatewayIntentResult
    {
        return new GatewayIntentResult(
            intent_id: 'sandbox_' . $donation->reference,
            metadata: [
                'created_at' => $this->clock->now()->format('c'),
                'note'       => 'Sandbox test donation.',
            ],
            // No off-site step; the donation flow should confirm immediately
            // so test donations land as paid and exercise the same
            // post-confirm side effects (receipt, rollups, events) the real
            // gateways trigger via webhook.
            auto_confirm: true,
        );
    }

    /** @since 1.0.0 */
    public function confirm(Donation $donation, array $payload = []): GatewayConfirmResult
    {
        return new GatewayConfirmResult(
            success: true,
            gateway_txn_id: 'sandbox_txn_' . $donation->reference,
            payment_method: 'test',
            payment_method_brand: null,
            payment_method_last4: null,
            fee_cents: 0,
            metadata: ['confirmed_at' => $this->clock->now()->format('c')],
        );
    }

    /** @since 1.0.0 */
    public function handleWebhook(WP_REST_Request $request): WebhookOutcome
    {
        return WebhookOutcome::notSupported($this->id());
    }

    /** @since 1.0.0 */
    public function refund(Donation $donation, int $amountCents, ?string $reason = null): RefundResult
    {
        return new RefundResult(
            success:           true,
            gateway_refund_id: 'sandbox_refund_' . $donation->reference . '_' . bin2hex(random_bytes(4)),
            amount_cents:      $amountCents,
            metadata:          ['reason' => $reason],
        );
    }

    /**
     * @throws \RuntimeException never in practice: nothing here can fail, but
     *                           the contract allows it and callers guard.
     *
     * @since 1.0.0
     */
    public function createSubscription(Donation $donation): RecurringPlan
    {
        $subId = self::SUB_PREFIX . (int) $donation->id;

        // Idempotent by contract: the caller guards on recurring_plan_id, but a
        // retried request must not leave two plans behind one donation.
        $existing = $this->plans->findBySubscriptionId($this->id(), $subId);
        if ($existing !== null) {
            return $existing;
        }

        $now    = $this->clock->now();
        $nowStr = $now->format('Y-m-d H:i:s');
        [$unit, $count] = FrequencyMap::toStripe((string) $donation->frequency);

        $plan = RecurringPlan::make();
        $plan->donor_id           = (int) $donation->donor_id;
        $plan->form_id            = $donation->form_id;
        $plan->campaign_id        = $donation->campaign_id;
        $plan->fund_id            = $donation->fund_id;
        $plan->fundraiser_id      = $donation->fundraiser_id;
        $plan->fundraiser_team_id = $donation->fundraiser_team_id;
        $plan->gateway            = $this->id();
        $plan->gateway_subscription_id = $subId;
        // No customer object exists here, and inventing one would make the
        // plan look like it has a stored payment method.
        $plan->gateway_customer_id = null;
        $plan->amount_cents       = (int) $donation->amount_cents;
        $plan->currency           = (string) $donation->currency;
        $plan->base_amount_cents  = $donation->base_amount_cents;
        $plan->fx_rate            = $donation->fx_rate;
        $plan->interval_unit      = $unit;
        $plan->interval_count     = $count;
        $plan->status             = 'active';
        // TestMode decides this on the donation; a sandbox plan inherits it
        // rather than asserting it, so the two rows can never disagree.
        $plan->is_test            = (bool) $donation->is_test;
        $plan->started_at         = $nowStr;
        $plan->next_payment_at    = self::nextCycleAt($now, $count);
        $plan->payments_count     = 1;
        $plan->total_paid_cents   = (int) $donation->amount_cents;
        $plan->last_payment_at    = $nowStr;
        $plan->created_at         = $nowStr;
        $plan->updated_at         = $nowStr;
        $plan->save();

        Donation::query()
            ->where('id', (int) $donation->id)
            ->update(['recurring_plan_id' => (int) $plan->id]);
        $donation->recurring_plan_id = (int) $plan->id;

        return $plan;
    }

    /**
     * The sandbox holds no state of its own, so the lifecycle methods are
     * deliberately empty: RecurringPlanActions writes the plan row either way,
     * and a local double that pretended to call an API would only be able to
     * pretend to fail.
     *
     * @since 1.0.0
     */
    public function cancelSubscription(RecurringPlan $plan, ?string $reason = null): void
    {
    }

    /** @since 1.0.0 */
    public function pauseSubscription(RecurringPlan $plan, ?string $resumesAt = null): void
    {
    }

    /** @since 1.0.0 */
    public function resumeSubscription(RecurringPlan $plan): void
    {
    }

    /** @since 1.0.0 */
    public function updateSubscriptionAmount(RecurringPlan $plan, int $amountCents): void
    {
    }
}
