<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donors\Donor;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use Dono\Gateways\GatewayManager;
use Dono\Recurring\GatewayUnreachable;
use Dono\Recurring\RecurringPlan;

/**
 * A past_due plan is live: the gateway's own dunning recovers it and walks the
 * plan back to active, so an erasure that steps over past_due leaves a
 * subscription billing a donor who can no longer reach the portal to stop it,
 * and every renewal writes their name and email back through the webhook log.
 */
final class ErasurePastDuePlanTest extends IntegrationTestCase
{
    private function donors(): DonorService
    {
        return Plugin::instance()->container->get(DonorService::class);
    }

    private function donorWithPlan(string $gateway, string $subId, string $status): array
    {
        $donor = $this->donors()->findOrCreate('pastdue-' . uniqid() . '@example.test', [
            'first_name' => 'Casey',
            'last_name'  => 'Doe',
        ]);

        $plan = RecurringPlan::make();
        $plan->donor_id                = (int) $donor->id;
        $plan->gateway                 = $gateway;
        $plan->gateway_subscription_id = $subId;
        $plan->status                  = $status;
        $plan->amount_cents            = 2500;
        $plan->currency                = 'USD';
        $plan->interval_unit           = 'month';
        $plan->interval_count          = 1;
        $plan->started_at              = gmdate('Y-m-d H:i:s');
        $plan->created_at              = gmdate('Y-m-d H:i:s');
        $plan->updated_at              = gmdate('Y-m-d H:i:s');
        $plan->save();

        return [$donor, $plan];
    }

    private function deregister(string $id): void
    {
        $manager = Plugin::instance()->container->get(GatewayManager::class);
        $prop = new \ReflectionProperty($manager, 'gateways');
        $prop->setAccessible(true);
        $all = $prop->getValue($manager);
        unset($all[$id]);
        $prop->setValue($manager, $all);
    }

    public function test_a_past_due_plan_is_ended_by_erasure(): void
    {
        [$donor, $plan] = $this->donorWithPlan('offline', '', 'past_due');

        $this->donors()->redact($donor);

        $fresh = RecurringPlan::query()->where('id', (int) $plan->id)->get();
        $this->assertSame('cancelled', $fresh->status, 'a declining plan still bills once dunning recovers it');
        $this->assertNotNull($fresh->cancelled_at);
    }

    public function test_erasure_aborts_when_a_past_due_plan_cannot_be_stopped(): void
    {
        [$donor, $plan] = $this->donorWithPlan('stripe', 'sub_pastdue_123', 'past_due');
        $this->deregister('stripe');

        try {
            $this->donors()->redact($donor);
            $this->fail('erasure should not complete while a declining plan can still recover and bill');
        } catch (GatewayUnreachable $e) {
            // expected
        }

        $fresh = Donor::query()->where('id', (int) $donor->id)->get();
        $this->assertNull($fresh->redacted_at, 'nothing was erased');
        $this->assertNotSame('', (string) $fresh->email_encrypted, 'the donor is still reachable');

        $freshPlan = RecurringPlan::query()->where('id', (int) $plan->id)->get();
        $this->assertSame('past_due', $freshPlan->status);
    }
}
