<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donors\DonorMetricsService;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use Dono\Recurring\RecurringPlan;

/**
 * The donor profile's "Recurring MRR" is rendered with the org's currency
 * symbol, so what it sums has to be in the org's currency.
 *
 * It summed the cadence-normalised amount_cents, which is in the donor's
 * currency. A 500.00 INR plan showed as 500,00 EUR, a hundred and ten times
 * what it is worth.
 */
final class DonorMrrBaseCurrencyTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        update_option('dono_currency_locale', [
            'default_currency'     => 'EUR',
            'supported_currencies' => ['EUR', 'INR', 'USD'],
        ]);
    }

    private function donorWithPlan(string $currency, int $cents, ?int $baseCents, string $unit = 'month', int $count = 1): int
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('mrr-' . uniqid() . '@example.com', ['first_name' => 'Mrr', 'last_name' => 'Test']);

        $now = gmdate('Y-m-d H:i:s');

        $plan = RecurringPlan::make();
        $plan->donor_id          = (int) $donor->id;
        $plan->gateway           = 'offline';
        $plan->gateway_subscription_id = 'sub_' . uniqid();
        $plan->amount_cents      = $cents;
        $plan->currency          = $currency;
        $plan->base_amount_cents = $baseCents;
        $plan->interval_unit     = $unit;
        $plan->interval_count    = $count;
        $plan->status            = 'active';
        $plan->started_at        = $now;
        $plan->created_at        = $now;
        $plan->updated_at        = $now;
        $plan->save();

        return (int) $donor->id;
    }

    private function lifetime(int $donorId): array
    {
        return Plugin::instance()->container->get(DonorMetricsService::class)
            ->profile($donorId)['lifetime'];
    }

    public function test_a_foreign_plan_counts_at_its_base_value(): void
    {
        // The live shape that exposed it: 500.00 INR worth 4.55 EUR.
        $donorId = $this->donorWithPlan('INR', 50000, 455);

        $lifetime = $this->lifetime($donorId);

        $this->assertSame(455, (int) $lifetime['mrr_cents'], 'the card shows what the plan is worth in EUR');
        $this->assertSame(0, (int) $lifetime['mrr_unconverted']);
    }

    public function test_a_base_currency_plan_needs_no_conversion(): void
    {
        $donorId = $this->donorWithPlan('EUR', 2500, null);

        $this->assertSame(2500, (int) $this->lifetime($donorId)['mrr_cents']);
    }

    public function test_an_unconvertible_plan_counts_as_nothing_and_says_so(): void
    {
        // No rate, so no known base value. Folding in its raw foreign cents is
        // exactly the bug; the total is short, and the card has to admit it.
        $donorId = $this->donorWithPlan('INR', 50000, null);

        $lifetime = $this->lifetime($donorId);

        $this->assertSame(0, (int) $lifetime['mrr_cents']);
        $this->assertSame(1, (int) $lifetime['mrr_unconverted']);
    }

    public function test_cadence_is_still_normalised_after_conversion(): void
    {
        // 120.00 EUR a year is 10.00 a month, converted first then divided.
        $donorId = $this->donorWithPlan('USD', 15000, 12000, 'year', 1);

        $this->assertSame(1000, (int) $this->lifetime($donorId)['mrr_cents']);
    }
}
