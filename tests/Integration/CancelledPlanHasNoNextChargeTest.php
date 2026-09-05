<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Foundation\Plugin;
use FundKit\Recurring\RecurringPlan;
use FundKit\Recurring\RecurringPlanRepository;

/**
 * Nothing will be charged again on a cancelled plan, so it has no next payment
 * and no resume owed. Left standing, the donor's portal kept showing a future
 * charge date on the donation they had just stopped, which reads as though the
 * cancellation did nothing.
 */
final class CancelledPlanHasNoNextChargeTest extends IntegrationTestCase
{
    private function plan(array $overrides = []): RecurringPlan
    {
        $now  = gmdate('Y-m-d H:i:s');
        $plan = RecurringPlan::make();
        $plan->donor_id                = 1;
        $plan->gateway                 = 'offline';
        $plan->gateway_subscription_id = 'sub_next_' . uniqid();
        $plan->amount_cents            = 2_000;
        $plan->currency                = 'USD';
        $plan->interval_unit           = 'month';
        $plan->interval_count          = 1;
        $plan->status                  = 'active';
        $plan->started_at              = $now;
        $plan->next_payment_at         = gmdate('Y-m-d H:i:s', strtotime('+1 month'));
        $plan->created_at              = $now;
        $plan->updated_at              = $now;
        foreach ($overrides as $k => $v) $plan->{$k} = $v;
        $plan->save();

        return $plan;
    }

    private function repo(): RecurringPlanRepository
    {
        return Plugin::instance()->container->get(RecurringPlanRepository::class);
    }

    private function reload(RecurringPlan $plan): RecurringPlan
    {
        return RecurringPlan::query()->where('id', (int) $plan->id)->get();
    }

    public function test_cancelling_clears_the_next_charge_date(): void
    {
        $plan = $this->plan();

        $this->repo()->markCancelled($plan, gmdate('Y-m-d H:i:s'), 'donor');

        $this->assertNull(
            $this->reload($plan)->next_payment_at,
            'the portal showed a future charge on a donation the donor had just stopped'
        );
    }

    public function test_it_clears_a_pending_resume_too(): void
    {
        $plan = $this->plan([
            'status'    => 'paused',
            'resume_at' => gmdate('Y-m-d H:i:s', strtotime('+2 months')),
        ]);

        $this->repo()->markCancelled($plan, gmdate('Y-m-d H:i:s'), 'donor');

        $this->assertNull($this->reload($plan)->resume_at);
    }

    /** The caller reads the model it handed in, so that has to agree with the row. */
    public function test_the_model_the_caller_holds_agrees_with_the_row(): void
    {
        $plan = $this->plan();

        $this->repo()->markCancelled($plan, gmdate('Y-m-d H:i:s'), 'donor');

        $this->assertNull($plan->next_payment_at);
        $this->assertSame('cancelled', (string) $plan->status);
    }

    /** An active plan keeps its date, which is the whole point of the column. */
    public function test_an_active_plan_keeps_its_next_charge(): void
    {
        $plan = $this->plan();

        $this->assertNotNull($this->reload($plan)->next_payment_at);
    }
}
