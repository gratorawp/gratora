<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Foundation\Plugin;
use Gratora\Recurring\GatewayUnreachable;
use Gratora\Recurring\RecurringPlan;
use Gratora\Recurring\RecurringPlanActions;
use Gratora\Recurring\RecurringPlanChange;

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

    public function test_change_interval_refuses_when_the_gateway_is_gone(): void
    {
        $plan = $this->orphanedPlan();

        try {
            $this->actions()->changeInterval($plan, 'yearly', RecurringPlanChange::byAdmin('change_interval', false));
            $this->fail('a cadence the processor never hears about must refuse');
        } catch (GatewayUnreachable) {
            $fresh = $this->reload($plan);
            $this->assertSame('month', (string) $fresh->interval_unit);
            $this->assertSame(1, (int) $fresh->interval_count);
        }
    }

    /**
     * The refusal is read by an admin deciding what to do next, and the only
     * thing it can tell them is what the processor is still doing. A resume
     * that refuses leaves the plan suspended and collecting nothing, so a
     * message about money still being taken sends them looking for a charge
     * that is not there.
     */
    public function test_a_refused_resume_does_not_claim_the_card_is_still_being_billed(): void
    {
        $plan = $this->orphanedPlan();

        try {
            $this->actions()->resume($plan, RecurringPlanChange::byAdmin('resume', false));
            $this->fail('resuming a plan whose processor is absent must refuse');
        } catch (GatewayUnreachable $e) {
            $this->assertStringNotContainsString('billing', $e->getMessage());
            $this->assertStringContainsString('stay paused at the processor', $e->getMessage());
        }
    }

    /** And the one where money really does keep moving still says so. */
    public function test_a_refused_pause_says_the_card_goes_on_being_charged(): void
    {
        $plan = $this->orphanedPlan();

        try {
            $this->actions()->pause(
                $plan,
                RecurringPlanActions::monthsFromNow(1),
                RecurringPlanChange::byAdmin('pause', false)
            );
            $this->fail('pausing a plan whose processor is absent must refuse');
        } catch (GatewayUnreachable $e) {
            $this->assertStringContainsString('go on being charged', $e->getMessage());
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
