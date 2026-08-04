<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use Dono\Gateways\GatewayManager;
use Dono\Recurring\GatewayUnreachable;
use Dono\Recurring\RecurringCanceller;
use Dono\Recurring\RecurringPlan;

/**
 * A cancellation that never reached the processor must not report success.
 *
 * Gateways register only while their credentials are stored, so a disconnected
 * Stripe makes the gateway absent. Reading that as "this plan has no processor"
 * flips the plan to cancelled, emails the donor to say so, and leaves the card
 * being charged every month with the renewals no longer even logged.
 */
final class RecurringCancelSafetyTest extends IntegrationTestCase
{
    private function canceller(): RecurringCanceller
    {
        return Plugin::instance()->container->get(RecurringCanceller::class);
    }

    private function plan(string $gateway, string $subscriptionId): RecurringPlan
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('plan-' . uniqid() . '@example.test');

        $p = RecurringPlan::make();
        $p->donor_id                = (int) $donor->id;
        $p->gateway                 = $gateway;
        $p->gateway_subscription_id = $subscriptionId;
        $p->status                  = 'active';
        $p->amount_cents            = 2500;
        $p->currency                = 'USD';
        $p->interval_unit           = 'month';
        $p->interval_count          = 1;
        $p->started_at              = gmdate('Y-m-d H:i:s');
        $p->created_at              = gmdate('Y-m-d H:i:s');
        $p->updated_at              = gmdate('Y-m-d H:i:s');
        $p->save();

        return $p;
    }

    /** Remove a gateway the way losing its credentials does. */
    private function deregister(string $id): void
    {
        $manager = Plugin::instance()->container->get(GatewayManager::class);

        $prop = new \ReflectionProperty($manager, 'gateways');
        $prop->setAccessible(true);
        $all = $prop->getValue($manager);
        unset($all[$id]);
        $prop->setValue($manager, $all);
    }

    public function test_a_plan_at_an_unreachable_gateway_is_not_marked_cancelled(): void
    {
        $plan = $this->plan('stripe', 'sub_live_123');
        $this->deregister('stripe');

        try {
            $this->canceller()->cancel($plan, 'donor asked');
            $this->fail('cancelling a plan we cannot reach should not report success');
        } catch (GatewayUnreachable $e) {
            // expected
        }

        $fresh = RecurringPlan::query()->where('id', (int) $plan->id)->get();
        $this->assertSame('active', $fresh->status, 'the plan still bills, so it must still read as active');
        $this->assertNull($fresh->cancelled_at);
    }

    public function test_an_offline_plan_still_cancels(): void
    {
        // No subscription id means it never reached a processor, so the local
        // state is the whole of it.
        $plan = $this->plan('offline', '');

        $this->assertTrue($this->canceller()->cancel($plan, 'donor asked'));

        $fresh = RecurringPlan::query()->where('id', (int) $plan->id)->get();
        $this->assertSame('cancelled', $fresh->status);
    }

    public function test_the_donor_is_not_told_a_cancellation_happened_when_it_did_not(): void
    {
        $mails = $this->captureMails();
        $before = count($mails);

        $plan = $this->plan('stripe', 'sub_live_456');
        $this->deregister('stripe');

        try {
            $this->canceller()->cancel($plan, 'donor asked');
        } catch (GatewayUnreachable $e) {
            // expected
        }

        $this->assertCount($before, $mails, 'no "your donation has been cancelled" email for a cancellation that did not happen');
    }
}
