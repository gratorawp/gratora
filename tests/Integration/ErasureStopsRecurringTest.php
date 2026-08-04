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
 * Erasing a donor must not leave their money moving.
 *
 * A plan that survives erasure keeps billing, and every renewal writes the
 * donor's name and email back into the webhook log, undoing the erasure it was
 * meant to complete. The donor cannot stop it either: erasure deletes their
 * magic-link tokens and the portal rejects a redacted donor on every screen.
 */
final class ErasureStopsRecurringTest extends IntegrationTestCase
{
    private function donors(): DonorService
    {
        return Plugin::instance()->container->get(DonorService::class);
    }

    private function donorWithPlan(string $gateway, string $subId, string $status = 'active'): array
    {
        $donor = $this->donors()->findOrCreate('erase-' . uniqid() . '@example.test', [
            'first_name' => 'Casey',
            'last_name'  => 'Doe',
        ]);

        $p = RecurringPlan::make();
        $p->donor_id                = (int) $donor->id;
        $p->gateway                 = $gateway;
        $p->gateway_subscription_id = $subId;
        $p->status                  = $status;
        $p->amount_cents            = 2500;
        $p->currency                = 'USD';
        $p->interval_unit           = 'month';
        $p->interval_count          = 1;
        $p->started_at              = gmdate('Y-m-d H:i:s');
        $p->created_at              = gmdate('Y-m-d H:i:s');
        $p->updated_at              = gmdate('Y-m-d H:i:s');
        $p->save();

        return [$donor, $p];
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

    public function test_erasing_a_donor_ends_their_active_plan(): void
    {
        [$donor, $plan] = $this->donorWithPlan('offline', '');

        $this->donors()->redact($donor);

        $fresh = RecurringPlan::query()->where('id', (int) $plan->id)->get();
        $this->assertSame('cancelled', $fresh->status, 'an erased donor is not left with a live mandate');
    }

    public function test_a_paused_plan_is_ended_too(): void
    {
        // Paused is not stopped: it resumes on its own date.
        [$donor, $plan] = $this->donorWithPlan('offline', '', 'paused');

        $this->donors()->redact($donor);

        $fresh = RecurringPlan::query()->where('id', (int) $plan->id)->get();
        $this->assertSame('cancelled', $fresh->status);
    }

    public function test_erasure_aborts_when_the_plan_cannot_be_stopped(): void
    {
        // Completing the erasure here would destroy the email and the tokens
        // that are the only way to reach the donor about a subscription still
        // charging them.
        [$donor, $plan] = $this->donorWithPlan('stripe', 'sub_live_789');
        $this->deregister('stripe');

        try {
            $this->donors()->redact($donor);
            $this->fail('erasure should not complete while a plan is still billing');
        } catch (GatewayUnreachable $e) {
            // expected
        }

        $fresh = Donor::query()->where('id', (int) $donor->id)->get();
        $this->assertNull($fresh->redacted_at, 'nothing was erased');
        $this->assertNotSame('', (string) $fresh->email_encrypted, 'the email survives, so the donor is still reachable');

        $freshPlan = RecurringPlan::query()->where('id', (int) $plan->id)->get();
        $this->assertSame('active', $freshPlan->status);
    }

    public function test_a_donor_with_no_plan_erases_as_before(): void
    {
        $donor = $this->donors()->findOrCreate('plain-' . uniqid() . '@example.test');

        $this->donors()->redact($donor);

        $fresh = Donor::query()->where('id', (int) $donor->id)->get();
        $this->assertNotNull($fresh->redacted_at);
    }

    public function test_an_already_cancelled_plan_does_not_block_erasure(): void
    {
        [$donor] = $this->donorWithPlan('stripe', 'sub_old', 'cancelled');
        $this->deregister('stripe');

        $this->donors()->redact($donor);

        $fresh = Donor::query()->where('id', (int) $donor->id)->get();
        $this->assertNotNull($fresh->redacted_at, 'a dead plan is not a reason to refuse');
    }
}
