<?php

declare(strict_types=1);

namespace Dono\Gateways;

use Dono\Recurring\RecurringPlan;

/**
 * A gateway whose donor can change the card behind a recurring plan.
 *
 * Separate from SubscriptionAware for the same reason SupportsPaymentRetry is:
 * being able to pause a subscription says nothing about being able to re-bank
 * it. Offline plans have no card at all, so they implement neither, and the
 * portal asks for this interface before offering the option rather than
 * showing a button that leads nowhere.
 *
 * A declined renewal is usually an expired card, which no amount of retrying
 * fixes; this is the flow that actually resolves dunning.
 *
 * @version 1.0.0
 */
interface SupportsPaymentMethodUpdate
{
    /**
     * Begin the change. The returned shape tells the portal whether to collect
     * the card in place or to send the donor to the processor.
     */
    public function startPaymentMethodUpdate(RecurringPlan $plan): PaymentMethodUpdate;

    /**
     * Put the newly collected method behind the plan.
     *
     * Only meaningful for PaymentMethodUpdate::INLINE. A redirect flow is
     * completed by the processor, and its webhook is what tells us.
     *
     * @param string $token The processor's id for the method the browser confirmed.
     */
    public function completePaymentMethodUpdate(RecurringPlan $plan, string $token): void;
}
