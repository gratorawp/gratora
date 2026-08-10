<?php

declare(strict_types=1);

namespace Dono\Gateways;

use Dono\Recurring\RecurringPlan;
use RuntimeException;

/**
 * Optional capability: a gateway that manages its own subscription lifecycle.
 * Implementations throw `RuntimeException` on API errors.
 *
 * @since 1.0.0
 */
interface SubscriptionAware
{
    /**
     * Must be idempotent: cancelling an already-cancelled subscription
     * must not throw.
     *
     * @throws RuntimeException on a non-recoverable gateway error.
     *
     * @since 1.0.0
     */
    public function cancelSubscription(RecurringPlan $plan, ?string $reason = null): void;

    /**
     * `$resumesAt` is UTC ISO 8601, or null to pause indefinitely.
     *
     * @throws RuntimeException on a non-recoverable gateway error.
     *
     * @since 1.0.0
     */
    public function pauseSubscription(RecurringPlan $plan, ?string $resumesAt = null): void;

    /**
     * No-op if the subscription is already active.
     *
     * @throws RuntimeException on a non-recoverable gateway error.
     *
     * @since 1.0.0
     */
    public function resumeSubscription(RecurringPlan $plan): void;

    /**
     * @throws RuntimeException on a non-recoverable gateway error.
     *
     * @since 1.0.0
     */
    public function updateSubscriptionAmount(RecurringPlan $plan, int $amountCents): void;
}
