<?php

declare(strict_types=1);

namespace Dono\Gateways;

use Dono\Recurring\RecurringPlan;

/**
 * A gateway that can be told to collect a failed renewal again, now.
 *
 * Deliberately separate from SubscriptionAware, because being able to pause or
 * cancel a subscription says nothing about being able to re-charge one. Stripe
 * exposes the open invoice and will attempt it on demand; PayPal owns its own
 * retry schedule and offers no endpoint to force one, so it implements
 * SubscriptionAware and not this. The admin UI asks for this interface before
 * offering the action, so a plan that cannot be retried never shows a button
 * that would do nothing.
 *
 * @version 1.0.0
 */
interface SupportsPaymentRetry
{
    /**
     * Attempt the outstanding renewal immediately.
     *
     * Implementations do not write plan state: the gateway's own webhook is
     * what confirms a collection, and treating the API's optimistic response
     * as payment would record money that never arrived.
     *
     * @throws PaymentRetryUnavailable When there is nothing outstanding to collect.
     * @throws \RuntimeException       When the gateway refused the attempt.
     */
    public function retryPayment(RecurringPlan $plan): void;
}
