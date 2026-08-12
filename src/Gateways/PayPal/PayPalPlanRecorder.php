<?php

declare(strict_types=1);

namespace Dono\Gateways\PayPal;

use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Foundation\Time\Clock;
use Dono\Recurring\FrequencyMap;
use Dono\Recurring\RecurringPlan;

/**
 * Writes the local plan row for a PayPal subscription.
 *
 * PayPal charges the moment the donor approves, so by the time anything reaches
 * here the money is already gone. The plan row is what lets Dono record that
 * payment, show the donor their plan, and cancel it later, and every cancel
 * path reads gateway_subscription_id off this row: without it the donor is
 * billed monthly forever with no way for the site to stop it.
 *
 * The webhook handlers call this as well as the donor's browser, so a closed
 * tab or a dropped connection does not lose the row.
 *
 * @since 1.0.0
 */
final class PayPalPlanRecorder
{
    /** @since 1.0.0 */
    public function __construct(
        private DonationRepository $donations,
        private Clock $clock,
    ) {
    }

    /**
     * Record the plan for a subscription PayPal has confirmed.
     *
     * Idempotent: a second delivery for a subscription already recorded returns
     * the existing row rather than a duplicate.
     *
     * @param  array<string,mixed> $sub a PayPal subscription resource
     * @throws PayPalPlanRefused when it does not answer for a donation awaiting it
     *
     * @since 1.0.0
     */
    public function record(array $sub): RecurringPlan
    {
        $subId = trim((string) ($sub['id'] ?? ''));
        if ($subId === '') {
            throw new PayPalPlanRefused('dono_paypal_bad_subscription', esc_html__('Missing subscription id.', 'dono-fundraising-platform'));
        }

        $reference = trim((string) ($sub['custom_id'] ?? ''));
        $donation  = $reference !== '' ? $this->donations->findByReference($reference) : null;
        if (! $donation instanceof Donation) {
            throw new PayPalPlanRefused('dono_paypal_subscription_mismatch',
                esc_html__('That subscription does not belong to this donation.', 'dono-fundraising-platform'),
                403
            );
        }

        if ((string) $donation->gateway !== 'paypal' || ! FrequencyMap::isRecurring((string) $donation->frequency)) {
            throw new PayPalPlanRefused('dono_paypal_not_recurring', esc_html__('That donation is not recurring.', 'dono-fundraising-platform'));
        }

        // Already recorded. Same subscription is the ordinary double delivery;
        // a different one means two subscriptions exist for one donation, and
        // binding the second would leave the first billing unrecorded, so it is
        // refused loudly rather than silently accepted.
        if ($donation->recurring_plan_id) {
            $existing = RecurringPlan::query()->where('id', (int) $donation->recurring_plan_id)->get();
            if ($existing instanceof RecurringPlan) {
                if ((string) $existing->gateway_subscription_id === $subId) {
                    return $existing;
                }
                throw new PayPalPlanRefused('dono_paypal_subscription_conflict',
                    esc_html__('This donation already has a different PayPal subscription.', 'dono-fundraising-platform'),
                    409
                );
            }
        }

        // custom_id proves which donation the subscription is for. It says
        // nothing about the money, and the browser chooses the plan: the SDK is
        // handed a plan id for this donation's amount and can just as easily
        // create the subscription on a cheaper one. Nothing else compares them.
        $meta         = (array) ($donation->gateway_metadata ?? []);
        $expectedPlan = (string) ($meta['paypal_plan_id'] ?? '');
        if ($expectedPlan === '' || (string) ($sub['plan_id'] ?? '') !== $expectedPlan) {
            throw new PayPalPlanRefused('dono_paypal_subscription_plan_mismatch',
                esc_html__('That subscription is not for this donation amount.', 'dono-fundraising-platform'),
                403
            );
        }

        $status = (string) ($sub['status'] ?? '');
        if (! in_array($status, ['ACTIVE', 'APPROVED', 'APPROVAL_PENDING'], true)) {
            throw new PayPalPlanRefused('dono_paypal_subscription_status',
                sprintf(
                    /* translators: %s: PayPal subscription status */
                    __('PayPal reports this subscription as %s.', 'dono-fundraising-platform'),
                    $status
                )
            );
        }

        return $this->write($donation, $subId, $sub, $status);
    }

    /**
     * @param array<string,mixed> $sub
     *
     * @since 1.0.0
     */
    private function write(Donation $donation, string $subId, array $sub, string $status): RecurringPlan
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        [$unit, $count] = FrequencyMap::toStripe((string) $donation->frequency);

        $plan = RecurringPlan::make();
        $plan->donor_id           = (int) $donation->donor_id;
        $plan->form_id            = $donation->form_id;
        $plan->campaign_id        = $donation->campaign_id;
        $plan->fund_id            = $donation->fund_id;
        $plan->fundraiser_id      = $donation->fundraiser_id;
        $plan->fundraiser_team_id = $donation->fundraiser_team_id;
        $plan->gateway            = 'paypal';
        $plan->gateway_subscription_id = $subId;
        $plan->gateway_customer_id     = (string) ($sub['subscriber']['payer_id'] ?? '') ?: null;
        $plan->amount_cents       = (int) $donation->amount_cents;
        $plan->currency           = (string) $donation->currency;
        $plan->base_amount_cents  = $donation->base_amount_cents;
        $plan->fx_rate            = $donation->fx_rate;
        $plan->interval_unit      = $unit;
        $plan->interval_count     = $count;
        // Payment counters stay at zero: the opening sale webhook records them,
        // so the plan is never credited for money that has not landed.
        $plan->status             = $status === 'ACTIVE' ? 'active' : 'pending';
        $plan->is_test            = (bool) $donation->is_test;
        $plan->started_at         = $now;
        $plan->next_payment_at    = (string) ($sub['billing_info']['next_billing_time'] ?? '') ?: null;
        $plan->created_at         = $now;
        $plan->updated_at         = $now;
        $plan->save();

        $donation->recurring_plan_id = (int) $plan->id;
        $donation->gateway_intent_id = $subId;
        $donation->save();

        return $plan;
    }
}
