<?php

declare(strict_types=1);

namespace Gratora\Gateways;

defined('ABSPATH') || exit;

use Gratora\Recurring\RecurringPlan;

/**
 * Change amount and cadence atomically: Stripe Prices and PayPal plans bind both, and separate
 * updates can create mismatched schedules or competing approvals.
 *
 * @since 1.0.0
 */
interface SupportsScheduleChange
{
    /**
     * Move the plan to this amount and cadence at the processor.
     *
     * Implementations do not write plan state. The returned schedule is what
     * the processor says it will do next, and the orchestrator writes that
     * rather than computing a date locally, because no local formula is right
     * for every processor.
     *
     * @throws \RuntimeException when the cadence is one this gateway cannot express.
     *
     * @since 1.0.0
     */
    public function updateSubscriptionSchedule(
        RecurringPlan $plan,
        int $amountCents,
        string $intervalUnit,
        int $intervalCount
    ): SubscriptionSchedule;
}
