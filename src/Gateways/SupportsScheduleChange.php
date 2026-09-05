<?php

declare(strict_types=1);

namespace FundKit\Gateways;

defined('ABSPATH') || exit;

use FundKit\Recurring\RecurringPlan;

/**
 * A gateway that can change how often a plan is charged, not only how much.
 *
 * Separate from SubscriptionAware because most processors cannot: a mandate is
 * often created against a fixed cadence and only the amount is revisable. A
 * gateway that does not implement this simply is not offered the action.
 *
 * The amount and the cadence are one argument list on purpose. At both
 * processors that support it, the two together ARE the object being swapped:
 * a Stripe Price is immutable and carries both, and a PayPal plan id is minted
 * from both. Changing them in two calls would leave the mandate live at a
 * mismatched pair in between, and on PayPal would put two approval links in
 * front of the donor that can be approved in either order.
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
