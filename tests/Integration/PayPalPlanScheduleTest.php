<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Foundation\Plugin;
use Gratora\Gateways\PayPal\PayPalPlans;

/**
 * A PayPal plan id stands for an amount AND a cadence together, and the key it
 * was minted under holds both. Reading the amount and discarding the rest left
 * a revise that moved only the cadence invisible to every screen.
 */
final class PayPalPlanScheduleTest extends IntegrationTestCase
{
    private const OPTION = 'gratora_paypal_plans';

    private function seed(array $map): void
    {
        update_option(self::OPTION, $map, false);
    }

    private function plans(): PayPalPlans
    {
        return Plugin::instance()->container->get(PayPalPlans::class);
    }

    protected function tearDown(): void
    {
        delete_option(self::OPTION);
        parent::tearDown();
    }

    public function test_the_whole_schedule_comes_back(): void
    {
        $this->seed(['live_abc123_eur_2500_month_1' => 'P-MONTHLY']);

        $this->assertSame(
            ['amount_cents' => 2500, 'interval_unit' => 'month', 'interval_count' => 1],
            $this->plans()->scheduleForPlan('P-MONTHLY')
        );
    }

    public function test_two_plans_at_one_amount_are_told_apart_by_cadence(): void
    {
        $this->seed([
            'live_abc123_eur_2500_month_1' => 'P-MONTHLY',
            'live_abc123_eur_2500_year_1'  => 'P-YEARLY',
        ]);

        $monthly = $this->plans()->scheduleForPlan('P-MONTHLY');
        $yearly  = $this->plans()->scheduleForPlan('P-YEARLY');

        $this->assertSame(2500, $monthly['amount_cents']);
        $this->assertSame(2500, $yearly['amount_cents']);
        $this->assertSame('month', $monthly['interval_unit']);
        $this->assertSame('year', $yearly['interval_unit']);
    }

    public function test_a_multi_month_cadence_keeps_its_count(): void
    {
        $this->seed(['live_abc123_eur_5000_month_3' => 'P-QUARTERLY']);

        $this->assertSame(3, $this->plans()->scheduleForPlan('P-QUARTERLY')['interval_count']);
    }

    public function test_a_plan_this_site_did_not_mint_says_nothing(): void
    {
        $this->seed(['live_abc123_eur_2500_month_1' => 'P-MONTHLY']);

        $this->assertNull($this->plans()->scheduleForPlan('P-SOMEONE-ELSE'));
        $this->assertNull($this->plans()->scheduleForPlan(''));
    }

    /** The old reader stays correct, since one caller only wants the amount. */
    public function test_the_amount_reader_still_answers(): void
    {
        $this->seed(['live_abc123_eur_2500_month_1' => 'P-MONTHLY']);

        $this->assertSame(2500, $this->plans()->amountForPlan('P-MONTHLY'));
        $this->assertNull($this->plans()->amountForPlan('P-NOPE'));
    }
}
