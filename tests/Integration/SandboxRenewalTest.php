<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Foundation\Plugin;
use Dono\Gateways\Sandbox\SandboxRenewer;
use Dono\Recurring\RecurringPlan;

/**
 * A sandbox plan has to actually renew, and has to stop.
 *
 * The real gateways renew because their own scheduler charges the card and
 * posts a webhook. The sandbox has neither, so without this sweep a plan sits
 * at one payment and the thing worth rehearsing before launch never happens.
 *
 * The renewal runs the same calls a Stripe renewal runs, so the receipts,
 * emails and rollups hanging off them fire unchanged.
 */
final class SandboxRenewalTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        update_option('dono_gateway_config', [
            'test_mode' => true,
            'sandbox'   => ['enabled' => true],
        ]);
    }

    private function renewer(): SandboxRenewer
    {
        return Plugin::instance()->container->get(SandboxRenewer::class);
    }

    /** @param array<string,mixed> $attrs */
    private function plan(array $attrs = []): RecurringPlan
    {
        $now = gmdate('Y-m-d H:i:s');
        $p = RecurringPlan::make();
        $p->donor_id       = 1;
        $p->gateway        = $attrs['gateway'] ?? 'sandbox';
        $p->gateway_subscription_id = 'sandbox_sub_' . uniqid();
        $p->amount_cents   = 2500;
        $p->currency       = 'EUR';
        $p->interval_unit  = 'week';
        $p->interval_count = 1;
        $p->status         = $attrs['status'] ?? 'active';
        $p->is_test        = true;
        $p->started_at     = $now;
        $p->next_payment_at   = $attrs['next_payment_at'] ?? gmdate('Y-m-d H:i:s', time() - 3600);
        $p->payments_count    = $attrs['payments_count'] ?? 1;
        $p->total_paid_cents  = 2500;
        $p->created_at     = $now;
        $p->updated_at     = $now;
        $p->save();

        return $p;
    }

    private function reload(RecurringPlan $p): RecurringPlan
    {
        return RecurringPlan::query()->find('id', (int) $p->id);
    }

    public function test_a_due_plan_renews_into_a_new_paid_donation(): void
    {
        $plan = $this->plan();

        $this->renewer()->run();

        $renewal = Donation::query()
            ->where('recurring_plan_id', (int) $plan->id)
            ->get();

        $this->assertNotNull($renewal, 'a due sandbox plan must produce a renewal donation');
        $this->assertSame('sandbox', $renewal->gateway);
        $this->assertTrue((bool) $renewal->is_test, 'a rehearsal renewal is still a rehearsal');
        $this->assertSame('sandbox_renewal_' . (int) $plan->id . '_2', $renewal->gateway_intent_id);

        $after = $this->reload($plan);
        $this->assertSame(2, (int) $after->payments_count);
        $this->assertSame(5000, (int) $after->total_paid_cents);
        $this->assertGreaterThan(gmdate('Y-m-d H:i:s'), (string) $after->next_payment_at, 'the next cycle moves forward');
    }

    public function test_a_renewal_fires_the_same_events_a_real_one_does(): void
    {
        // The point of rehearsing is that everything hanging off a renewal
        // runs: the receipt, the donor rollup, the renewal email. They hang off
        // these hooks, so a sandbox renewal that fired neither would look right
        // in the list and exercise nothing.
        $completed = 0;
        $renewed   = 0;
        add_action('dono.donation.completed', static function () use (&$completed): void { $completed++; });
        add_action('dono.recurring.renewed',  static function () use (&$renewed): void { $renewed++; });

        $this->plan();
        $this->renewer()->run();

        $this->assertSame(1, $completed, 'a renewal completes a donation');
        $this->assertSame(1, $renewed, 'and announces itself as a renewal');
    }

    public function test_a_plan_that_is_not_due_is_left_alone(): void
    {
        $plan = $this->plan(['next_payment_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);

        $this->renewer()->run();

        $this->assertSame(1, (int) $this->reload($plan)->payments_count);
    }

    public function test_another_gateways_plan_is_never_touched(): void
    {
        // The sweep matches on gateway, so a Stripe plan whose webhook is late
        // must not be renewed locally out of nowhere.
        $plan = $this->plan(['gateway' => 'stripe']);

        $this->renewer()->run();

        $this->assertSame(1, (int) $this->reload($plan)->payments_count);
    }

    public function test_the_rehearsal_ends_at_the_cap(): void
    {
        $plan = $this->plan(['payments_count' => SandboxRenewer::MAX_CYCLES]);

        $this->renewer()->run();

        $after = $this->reload($plan);
        $this->assertSame('expired', $after->status, 'a rehearsal that ran its course stops');
        $this->assertNull($after->next_payment_at, 'and leaves the sweep');
        $this->assertNotNull($after->cancellation_reason);
    }

    public function test_switching_test_mode_off_ends_the_rehearsal(): void
    {
        $plan = $this->plan();

        // The sandbox gateway deregisters when test mode goes off, and a plan
        // whose gateway is gone cannot be cancelled through the normal path.
        update_option('dono_gateway_config', ['test_mode' => false]);

        $this->renewer()->run();

        $after = $this->reload($plan);
        $this->assertSame('expired', $after->status);
        $this->assertNull($after->next_payment_at);
    }

    public function test_a_second_sweep_does_not_double_charge_the_same_cycle(): void
    {
        $plan = $this->plan();

        $this->renewer()->run();
        $countAfterFirst = Donation::query()->where('recurring_plan_id', (int) $plan->id)->count();

        // next_payment_at has moved, so the plan is no longer due. Running
        // again must be a no-op rather than a second donation for one cycle.
        $this->renewer()->run();

        $this->assertSame(
            $countAfterFirst,
            Donation::query()->where('recurring_plan_id', (int) $plan->id)->count()
        );
    }
}
