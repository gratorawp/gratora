<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Commands\CommandContext;
use Dono\Foundation\Commands\CommandRegistry;
use Dono\Foundation\Plugin;
use Dono\Recurring\RecurringPlan;

/**
 * recurring.update_amount is the donor portal's change_amount through a
 * different door, and it had neither of the portal's two guards.
 *
 * Every base-currency aggregate reads base_amount_cents in preference to
 * amount_cents, so leaving the snapshot behind pins MRR to the pre-change
 * figure forever. And a fractional amount in a zero-decimal currency is not
 * refused by the gateway, it is rounded: 1.50 JPY bills as 2 while the plan row
 * keeps 1.50, on every renewal.
 */
final class RecurringUpdateAmountTest extends IntegrationTestCase
{
    private function registry(): CommandRegistry
    {
        return Plugin::instance()->container->get(CommandRegistry::class);
    }

    private function ctx(): CommandContext
    {
        $user = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user);

        return new CommandContext($user, 'rest', 'test-' . uniqid());
    }

    private function plan(string $currency, int $cents, ?int $baseCents, ?string $rate): RecurringPlan
    {
        $now = gmdate('Y-m-d H:i:s');

        $plan = RecurringPlan::make();
        $plan->donor_id          = 1;
        $plan->gateway           = 'offline';
        $plan->gateway_subscription_id = 'sub_ua_' . bin2hex(random_bytes(4));
        $plan->amount_cents      = $cents;
        $plan->currency          = $currency;
        $plan->base_amount_cents = $baseCents;
        $plan->fx_rate           = $rate;
        $plan->interval_unit     = 'month';
        $plan->interval_count    = 1;
        $plan->status            = 'active';
        $plan->started_at        = $now;
        $plan->created_at        = $now;
        $plan->updated_at        = $now;
        $plan->save();

        return $plan;
    }

    private function reload(int $id): RecurringPlan
    {
        return RecurringPlan::query()->where('id', $id)->get();
    }

    public function test_the_base_currency_snapshot_moves_with_the_amount(): void
    {
        // The live shape: USD 250.00 worth 217.68 in base at 0.87070091.
        $plan = $this->plan('USD', 25000, 21768, '0.87070091');

        $res = $this->registry()->dispatch('recurring.update_amount', [
            'plan_id'      => (int) $plan->id,
            'amount_cents' => 50000,
        ], $this->ctx());

        $this->assertTrue($res->ok, $res->error ?? '');

        $fresh = $this->reload((int) $plan->id);
        $this->assertSame(50000, (int) $fresh->amount_cents);
        $this->assertSame(
            43535,
            (int) $fresh->base_amount_cents,
            'doubling the plan doubles what every base-currency total reads'
        );
    }

    public function test_a_plan_with_no_rate_keeps_no_snapshot(): void
    {
        // No rate means no known base value; inventing one from the raw foreign
        // cents is the bug this guards against.
        $plan = $this->plan('INR', 50000, null, null);

        $this->registry()->dispatch('recurring.update_amount', [
            'plan_id'      => (int) $plan->id,
            'amount_cents' => 60000,
        ], $this->ctx());

        $fresh = $this->reload((int) $plan->id);
        $this->assertSame(60000, (int) $fresh->amount_cents);
        $this->assertNull($fresh->base_amount_cents);
    }

    public function test_a_fractional_amount_in_a_zero_decimal_currency_is_refused(): void
    {
        $plan = $this->plan('JPY', 100000, 100000, '1.00000000');

        $res = $this->registry()->dispatch('recurring.update_amount', [
            'plan_id'      => (int) $plan->id,
            'amount_cents' => 150,
        ], $this->ctx());

        $this->assertFalse($res->ok, 'the gateway would round this to 2 yen and never say so');

        $fresh = $this->reload((int) $plan->id);
        $this->assertSame(100000, (int) $fresh->amount_cents, 'and the plan is untouched');
    }

    public function test_a_whole_amount_in_a_zero_decimal_currency_is_allowed(): void
    {
        $plan = $this->plan('JPY', 100000, 100000, '1.00000000');

        $res = $this->registry()->dispatch('recurring.update_amount', [
            'plan_id'      => (int) $plan->id,
            'amount_cents' => 250000,
        ], $this->ctx());

        $this->assertTrue($res->ok, $res->error ?? '');
        $this->assertSame(250000, (int) $this->reload((int) $plan->id)->amount_cents);
    }
}
