<?php

declare(strict_types=1);

namespace Dono\Gateways;

use Dono\Donations\Donation;
use Dono\Recurring\RecurringPlan;
use RuntimeException;

/**
 * Optional capability: a gateway that charges synchronously and so has no
 * webhook to create the plan in.
 *
 * SubscriptionAware manages a plan that already exists. Nothing creates one:
 * Stripe and PayPal each build the row inside their own webhook handling,
 * which a gateway confirming in the same request never reaches. Without this
 * seam a recurring donation on such a gateway is money taken against a
 * schedule nothing will ever collect.
 *
 * Called once, immediately after DonationService::confirm().
 *
 * @since 1.0.0
 */
interface SubscriptionCreator
{
    /**
     * Must be idempotent: a donation already carrying `recurring_plan_id`
     * returns that plan rather than inserting a second one.
     *
     * @throws RuntimeException when no plan could be created.
     *
     * @since 1.0.0
     */
    public function createSubscription(Donation $donation): RecurringPlan;
}
