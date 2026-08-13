<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Plugin;
use Dono\Recurring\GatewayUnreachable;
use Dono\Recurring\RecurringPlan;
use Dono\Recurring\RecurringPlanActions;
use Dono\Recurring\RecurringPlanChange;

/**
 * A plan may not be changed while its processor is absent.
 *
 * Stripe and PayPal register only while their credentials are stored, so a
 * disconnected gateway means "cannot reach the processor", not "this plan has
 * no processor". Writing the row on that reading tells the donor their donation
 * is paused, or at a new amount, while the card keeps being charged the old one.
 *
 * Cancel has refused this since the beginning. Pause, resume, skip-next and
 * change-amount moved money the same way and did not.
 */
final class RecurringGatewayReachableTest extends IntegrationTestCase
{
    private function actions(): RecurringPlanActions
    {
        return Plugin::instance()->container->get(RecurringPlanActions::class);
    }

    /** A plan on a gateway this site has no credentials for. */
    private function orphanedPlan(): RecurringPlan
    {
        $now = gmdate('Y-m-d H:i:s');
        $p = RecurringPlan::make();
        $p->donor_id       = 1;
        $p->gateway        = 'stripe';
        $p->gateway_subscription_id = 'sub_' . uniqid();
        $p->amount_cents   = 2500;
        $p->currency       = 'EUR';
        $p->interval_unit  = 'month';
        $p->interval_count = 1;
        $p->status         = 'active';
        $p->is_test        = false;
        $p->started_at     = $now;
        $p->next_payment_at = gmdate('Y-m-d H:i:s', time() + 86400);
        $p->created_at     = $now;
        $p->updated_at     = $now;
        $p->save();

        return $p;
    }

    private function reload(RecurringPlan $p): RecurringPlan
    {
        return RecurringPlan::query()->find('id', (int) $p->id);
    }

    public function test_pause_refuses_when_the_gateway_is_gone(): void
    {
        $plan = $this->orphanedPlan();

        try {
            $this->actions()->pause(
                $plan,
                RecurringPlanActions::monthsFromNow(1),
                RecurringPlanChange::byAdmin('pause', false)
            );
            $this->fail('pausing a plan whose processor is absent must refuse');
        } catch (GatewayUnreachable) {
            $this->assertSame('active', $this->reload($plan)->status, 'and must not write the row');
        }
    }

    public function test_resume_refuses_when_the_gateway_is_gone(): void
    {
        $plan = $this->orphanedPlan();

        $this->expectException(GatewayUnreachable::class);
        $this->actions()->resume($plan, RecurringPlanChange::byAdmin('resume', false));
    }

    public function test_skip_next_refuses_when_the_gateway_is_gone(): void
    {
        $plan = $this->orphanedPlan();
        $before = (string) $plan->next_payment_at;

        try {
            $this->actions()->skipNext($plan, RecurringPlanChange::byAdmin('skip_next', false));
            $this->fail('skipping a payment the processor will still take must refuse');
        } catch (GatewayUnreachable) {
            $this->assertSame($before, (string) $this->reload($plan)->next_payment_at);
        }
    }

    public function test_change_amount_refuses_when_the_gateway_is_gone(): void
    {
        $plan = $this->orphanedPlan();

        try {
            $this->actions()->changeAmount($plan, 5000, RecurringPlanChange::byAdmin('change_amount', false));
            $this->fail('a new amount the processor never hears about must refuse');
        } catch (GatewayUnreachable) {
            $this->assertSame(2500, (int) $this->reload($plan)->amount_cents);
        }
    }

    public function test_an_offline_plan_is_still_changeable(): void
    {
        // Offline is registered and simply has no subscriptions, so a local
        // write is the whole of it. The guard must not turn that into a refusal.
        $plan = $this->orphanedPlan();
        RecurringPlan::query()->where('id', (int) $plan->id)->update(['gateway' => 'offline']);

        $this->actions()->pause(
            $this->reload($plan),
            RecurringPlanActions::monthsFromNow(1),
            RecurringPlanChange::byAdmin('pause', false)
        );

        $this->assertSame('paused', $this->reload($plan)->status);
    }
}
