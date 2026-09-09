<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Analytics\ErrorLog;
use Gratora\Donations\Donation;
use Gratora\Foundation\Plugin;
use Gratora\Gateways\GatewayManager;
use Gratora\Recurring\PlanRow;
use Gratora\Recurring\RecurringPlan;

/**
 * The subscriptions list shapes a page of plans at a time. Doing it row by row
 * cost a declined-renewal query and an error query each, on top of the donor
 * and campaign lookups, so a hundred-row page ran four hundred queries and the
 * screen took seconds to paint.
 */
final class PlanRowBatchTest extends IntegrationTestCase
{
    private function gateways(): GatewayManager
    {
        return Plugin::instance()->container->get(GatewayManager::class);
    }

    /** @return list<RecurringPlan> */
    private function seedPlans(int $count, string $tag): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $now  = gmdate('Y-m-d H:i:s');
            $plan = RecurringPlan::make();
            $plan->donor_id                = 1;
            $plan->gateway                 = 'offline';
            $plan->gateway_subscription_id = "sub_{$tag}_{$i}";
            $plan->amount_cents            = 1_000;
            $plan->currency                = 'USD';
            $plan->interval_unit           = 'month';
            $plan->interval_count          = 1;
            $plan->status                  = 'active';
            $plan->failed_renewals_count   = 1;
            $plan->started_at              = $now;
            $plan->created_at              = $now;
            $plan->updated_at              = $now;
            $plan->save();

            $this->seedFailedRenewal((int) $plan->id, "GRATORA-{$tag}-{$i}-OLD", '2026-01-01 00:00:00', 'Old decline');
            $this->seedFailedRenewal((int) $plan->id, "GRATORA-{$tag}-{$i}-NEW", '2026-02-01 00:00:00', 'Card expired');
            $this->seedPlanError((int) $plan->id, "cancel failed on {$tag}-{$i}");

            $out[] = $plan;
        }

        return $out;
    }

    private function seedFailedRenewal(int $planId, string $reference, string $at, string $reason): void
    {
        $d = Donation::make();
        $d->reference         = $reference;
        $d->donor_id          = 1;
        $d->recurring_plan_id = $planId;
        $d->amount_cents      = 1_000;
        $d->net_cents         = 1_000;
        $d->currency          = 'USD';
        $d->base_amount_cents = 1_000;
        $d->base_currency     = 'USD';
        $d->fx_rate           = '1.00000000';
        $d->gateway           = 'offline';
        $d->status            = 'failed';
        $d->failure_reason    = $reason;
        $d->is_test           = false;
        $d->created_at        = $at;
        $d->updated_at        = $at;
        $d->save();
    }

    private function seedPlanError(int $planId, string $message): void
    {
        Plugin::instance()->container->get(\Gratora\Analytics\EventRecorder::class)->record(
            ErrorLog::PREFIX . 'gateway.offline',
            ['recurring_plan_id' => $planId, 'payload' => ['message' => $message]]
        );
    }

    private function queriesToShape(array $plans): int
    {
        global $wpdb;
        $before = $wpdb->num_queries;
        PlanRow::commonMany($plans, $this->gateways());

        return $wpdb->num_queries - $before;
    }

    public function test_shaping_more_plans_does_not_cost_more_queries(): void
    {
        $small = $this->queriesToShape($this->seedPlans(2, 'small'));
        $large = $this->queriesToShape($this->seedPlans(8, 'large'));

        $this->assertSame(
            $small,
            $large,
            "shaping grew with the page: {$small} queries for 2 plans, {$large} for 8"
        );
    }

    public function test_each_plan_still_gets_its_own_latest_decline(): void
    {
        $plans  = $this->seedPlans(3, 'decline');
        $shaped = PlanRow::commonMany($plans, $this->gateways());

        foreach ($plans as $i => $plan) {
            $failure = $shaped[(int) $plan->id]['last_failure'] ?? null;

            $this->assertNotNull($failure, 'a plan with a declined renewal reported none');
            $this->assertSame("GRATORA-decline-{$i}-NEW", $failure['reference'], 'the older decline won');
            $this->assertSame('Card expired', $failure['reason']);
        }
    }

    public function test_each_plan_still_gets_its_own_errors(): void
    {
        $plans  = $this->seedPlans(3, 'errs');
        $shaped = PlanRow::commonMany($plans, $this->gateways());

        foreach ($plans as $i => $plan) {
            $errors = $shaped[(int) $plan->id]['errors'] ?? [];

            $this->assertCount(1, $errors, 'errors landed on the wrong plan');
            $this->assertSame("cancel failed on errs-{$i}", $errors[0]['message']);
        }
    }

    /** A plan that never failed asks for nothing and is told nothing. */
    public function test_a_healthy_plan_reports_no_failure(): void
    {
        $plan = $this->seedPlans(1, 'healthy')[0];
        $plan->failed_renewals_count = 0;
        $plan->save();

        $shaped = PlanRow::commonMany([$plan], $this->gateways());

        $this->assertNull($shaped[(int) $plan->id]['last_failure']);
    }

    public function test_the_single_plan_shape_matches_the_batched_one(): void
    {
        $plan = $this->seedPlans(1, 'agree')[0];

        $this->assertSame(
            PlanRow::common($plan, $this->gateways()),
            PlanRow::commonMany([$plan], $this->gateways())[(int) $plan->id]
        );
    }
}
