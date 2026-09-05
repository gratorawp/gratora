<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Gateways\SubscriptionSchedule;
use FundKit\Gateways\SupportsScheduleChange;

/**
 * Changing how often a donor is charged is a capability, not something every
 * processor has: a mandate is often created against a fixed cadence and only
 * the amount is revisable. Gateways that cannot are simply not offered it.
 */
final class ScheduleChangeSeamTest extends IntegrationTestCase
{
    /**
     * Asserted on the classes, not on the gateway manager: the manager answers
     * null for an unconfigured processor, and a loop over nulls asserts nothing.
     */
    public function test_the_processors_that_can_declare_it(): void
    {
        $can = [
            \FundKit\Gateways\Stripe\StripeGateway::class,
            \FundKit\Gateways\PayPal\PayPalGateway::class,
            \FundKit\Gateways\Sandbox\SandboxGateway::class,
        ];

        foreach ($can as $class) {
            $this->assertTrue(
                is_subclass_of($class, SupportsScheduleChange::class),
                "{$class} mints its price or plan from the cadence, so it can change one"
            );
        }
    }

    /** Every one of them still answers the amount-only path callers already use. */
    public function test_they_keep_the_amount_only_method(): void
    {
        foreach ([
            \FundKit\Gateways\Stripe\StripeGateway::class,
            \FundKit\Gateways\PayPal\PayPalGateway::class,
            \FundKit\Gateways\Sandbox\SandboxGateway::class,
        ] as $class) {
            $this->assertTrue(method_exists($class, 'updateSubscriptionAmount'), $class);
        }
    }

    public function test_a_schedule_says_nothing_rather_than_guessing_a_date(): void
    {
        $unknown = SubscriptionSchedule::unknown();

        $this->assertNull($unknown->nextPaymentAt, 'null is not the same as now');
        $this->assertNull($unknown->currentPeriodEnd);
    }

    /** The processor's own period end, in UTC, which is what the column holds. */
    public function test_a_stripe_answer_is_read_as_utc(): void
    {
        $schedule = SubscriptionSchedule::fromStripe(['current_period_end' => 1788307200]);

        $this->assertSame(gmdate('Y-m-d H:i:s', 1788307200), $schedule->nextPaymentAt);
        $this->assertSame($schedule->nextPaymentAt, $schedule->currentPeriodEnd);
    }

    /** Newer API versions moved the field onto the item; both are read. */
    public function test_a_period_end_on_the_item_is_still_found(): void
    {
        $schedule = SubscriptionSchedule::fromStripe([
            'items' => ['data' => [['current_period_end' => 1788307200]]],
        ]);

        $this->assertSame(gmdate('Y-m-d H:i:s', 1788307200), $schedule->nextPaymentAt);
    }

    public function test_a_subscription_that_said_nothing_is_not_invented(): void
    {
        $this->assertNull(SubscriptionSchedule::fromStripe([])->nextPaymentAt);
        $this->assertNull(SubscriptionSchedule::fromStripe(['current_period_end' => 'soon'])->nextPaymentAt);
    }
}
