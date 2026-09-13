<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Recurring\RecurringPlan;
use Gratora\Recurring\RecurringPlanRepository;

/**
 * Churn this month, over everyone who was there to cancel.
 *
 * The denominator was the plans collecting cleanly, plus the cancellations.
 * A paused plan and one the gateway is still chasing are subscribers too, and
 * leaving them out while counting their cancellations in the numerator made
 * the rate read high: on a site with eight past due and four paused, three
 * cancellations came out at 10.3% instead of 7.3%.
 */
final class ChurnCountsEveryoneWhoCouldLeaveTest extends IntegrationTestCase
{
    private const TODAY = '2026-06-15 12:00:00';

    private function plan(string $status, ?string $cancelledAt = null): void
    {
        $started = '2026-01-01 00:00:00';

        $p = RecurringPlan::make();
        $p->donor_id                = 1;
        $p->gateway                 = 'stripe';
        $p->gateway_subscription_id = 'sub_' . bin2hex(random_bytes(5));
        $p->amount_cents            = 1000;
        $p->currency                = 'USD';
        $p->base_amount_cents       = 1000;
        $p->fx_rate                 = '1.00000000';
        $p->interval_unit           = 'month';
        $p->interval_count          = 1;
        $p->status                  = $status;
        $p->cancelled_at            = $cancelledAt;
        $p->is_test                 = false;
        $p->started_at              = $started;
        $p->created_at              = $started;
        $p->updated_at              = $started;
        $p->save();
    }

    /** @return array<string,mixed> */
    private function stats(): array
    {
        return (new RecurringPlanRepository())->recurringStats(self::TODAY);
    }

    public function test_a_paused_plan_is_someone_who_did_not_leave(): void
    {
        $this->plan('active');
        $this->plan('paused');
        $this->plan('cancelled', '2026-06-02 00:00:00');

        // One cancellation out of three subscribers, not one out of two.
        $this->assertSame(33.3, $this->stats()['churn_pct']);
    }

    public function test_a_plan_the_gateway_is_still_chasing_counts_too(): void
    {
        $this->plan('active');
        $this->plan('past_due');
        $this->plan('cancelled', '2026-06-02 00:00:00');

        $this->assertSame(33.3, $this->stats()['churn_pct']);
    }

    /** The shape of the live site: eight chasing, four paused, three gone. */
    public function test_the_rate_is_over_every_plan_the_gateway_may_collect_on(): void
    {
        for ($i = 0; $i < 26; $i++) $this->plan('active');
        for ($i = 0; $i < 8; $i++)  $this->plan('past_due');
        for ($i = 0; $i < 4; $i++)  $this->plan('paused');
        for ($i = 0; $i < 3; $i++)  $this->plan('cancelled', '2026-06-02 00:00:00');

        $stats = $this->stats();

        $this->assertSame(3, $stats['churned_this_month']);
        // 3 of 41, not 3 of 29.
        $this->assertSame(7.3, $stats['churn_pct']);
    }

    /** A cancellation from a month already closed is not this month's churn. */
    public function test_an_older_cancellation_is_in_neither_half(): void
    {
        $this->plan('active');
        $this->plan('cancelled', '2026-04-02 00:00:00');

        $stats = $this->stats();

        $this->assertSame(0, $stats['churned_this_month']);
        $this->assertSame(0.0, $stats['churn_pct']);
    }

    /** Nobody to leave, no rate to report. */
    public function test_a_site_with_no_plans_reports_nothing(): void
    {
        $this->assertSame(0.0, $this->stats()['churn_pct']);
    }
}
