<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Foundation\Plugin;
use Gratora\Recurring\PlanChangeRefused;
use Gratora\Recurring\RecurringPlan;
use Gratora\Recurring\RecurringPlanActions;
use Gratora\Recurring\RecurringPlanChange;

/**
 * PayPal records a subscription when the donor approves it and starts it when
 * it says so, which can be never. Until then there is no schedule: nothing to
 * pause, nothing to skip, no next charge to re-price.
 *
 * Both sides gated on whether the plan had ended, and a plan that has not
 * begun is not one that has ended, so the whole menu was offered and every
 * click went out to the processor. Cancelling is the exception and has to
 * stay: the donor approved it, so it is already against their card.
 */
final class AnUnstartedSubscriptionTest extends IntegrationTestCase
{
    private function actions(): RecurringPlanActions
    {
        return Plugin::instance()->container->get(RecurringPlanActions::class);
    }

    private function change(string $action): RecurringPlanChange
    {
        return new RecurringPlanChange($action, RecurringPlanChange::BY_ADMIN);
    }

    private function plan(string $status = 'pending'): RecurringPlan
    {
        $now = gmdate('Y-m-d H:i:s');

        $p = RecurringPlan::make();
        $p->donor_id                = 1;
        $p->gateway                 = 'offline';
        $p->gateway_subscription_id = 'sub_' . bin2hex(random_bytes(4));
        $p->amount_cents            = 2000;
        $p->currency                = 'USD';
        $p->interval_unit           = 'month';
        $p->interval_count          = 1;
        $p->status                  = $status;
        $p->next_payment_at         = gmdate('Y-m-d H:i:s', time() + 86400);
        $p->started_at              = $now;
        $p->created_at              = $now;
        $p->updated_at              = $now;
        $p->save();

        return $p;
    }

    public function test_it_cannot_be_paused(): void
    {
        $this->expectException(PlanChangeRefused::class);

        $this->actions()->pause($this->plan(), gmdate('Y-m-d', time() + 2592000), $this->change('pause'));
    }

    public function test_its_next_charge_cannot_be_skipped(): void
    {
        $this->expectException(PlanChangeRefused::class);

        $this->actions()->skipNext($this->plan(), $this->change('skip_next'));
    }

    public function test_it_cannot_be_repriced(): void
    {
        $this->expectException(PlanChangeRefused::class);

        $this->actions()->changeAmount($this->plan(), 5000, $this->change('change_amount'));
    }

    public function test_its_schedule_cannot_be_changed(): void
    {
        $this->expectException(PlanChangeRefused::class);

        $this->actions()->changeInterval($this->plan(), 'yearly', $this->change('change_interval'));
    }

    /** The refusal names the processor and what does become possible. */
    public function test_the_refusal_says_what_can_be_done_instead(): void
    {
        try {
            $this->actions()->pause($this->plan(), gmdate('Y-m-d', time() + 2592000), $this->change('pause'));
            $this->fail('expected a refusal');
        } catch (PlanChangeRefused $e) {
            $this->assertStringContainsString('Offline', $e->getMessage(), 'names the processor, not the slug');
            $this->assertStringContainsString('cancelled', $e->getMessage());
        }
    }

    /**
     * The one thing that must work: the donor has approved it, so the money is
     * already promised and ending it is what stops that.
     */
    public function test_it_can_still_be_cancelled(): void
    {
        $this->makeOfflinePayable();

        $plan = $this->plan();
        $this->actions()->cancel($plan, 'donor asked', $this->change('cancel'));

        $this->assertSame(
            'cancelled',
            (string) RecurringPlan::query()->where('id', (int) $plan->id)->get()->status
        );
    }

    /** A started plan is untouched by any of this. */
    public function test_a_started_plan_can_still_be_paused(): void
    {
        $this->makeOfflinePayable();

        $plan = $this->plan('active');
        $this->actions()->pause($plan, gmdate('Y-m-d', time() + 2592000), $this->change('pause'));

        $this->assertSame(
            'paused',
            (string) RecurringPlan::query()->where('id', (int) $plan->id)->get()->status
        );
    }
}
