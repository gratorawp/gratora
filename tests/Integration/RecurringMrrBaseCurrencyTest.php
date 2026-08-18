<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Helpers\Money;
use Dono\Foundation\Plugin;
use Dono\Recurring\RecurringPlan;
use Dono\Recurring\RecurringPlanRepository;

/**
 * MRR must be summed in the base currency only.
 *
 * The repository used to COALESCE base_amount_cents to amount_cents, which is
 * exactly what DonationQueries documents as "would corrupt every base-currency
 * total". A JPY 10,000/mo plan reported MRR as if 10,000 were euros: 186x too
 * high. It is reachable because the Give importer never sets a plan's base.
 */
final class RecurringMrrBaseCurrencyTest extends IntegrationTestCase
{
    private int $campaignId = 4242;

    /**
     * Money::defaultCurrency() caches statically for the process, so whatever a
     * sibling test resolved first wins. These fixtures turn on plan currency vs
     * base currency, so read the real value rather than assume one.
     */
    private function baseCurrency(): string
    {
        return Money::defaultCurrency();
    }

    private function foreignCurrency(): string
    {
        return $this->baseCurrency() === 'JPY' ? 'KRW' : 'JPY';
    }

    private function plan(array $overrides = []): RecurringPlan
    {
        $now = gmdate('Y-m-d H:i:s');
        $p = RecurringPlan::make();
        $p->donor_id          = 1;
        $p->campaign_id       = $this->campaignId;
        $p->gateway           = 'stripe';
        $p->gateway_subscription_id = 'sub_' . bin2hex(random_bytes(5));
        $p->amount_cents      = $overrides['amount_cents'] ?? 1000;
        $p->currency          = $overrides['currency'] ?? $this->baseCurrency();
        $p->base_amount_cents = $overrides['base_amount_cents'] ?? null;
        $p->interval_unit     = $overrides['interval_unit'] ?? 'month';
        $p->interval_count    = 1;
        $p->status            = 'active';
        $p->is_test           = false;
        $p->started_at        = $now;
        $p->created_at        = $now;
        $p->updated_at        = $now;
        $p->save();
        return $p;
    }

    private function repo(): RecurringPlanRepository
    {
        return Plugin::instance()->container->get(RecurringPlanRepository::class);
    }

    /** The headline: an unconverted foreign plan must not inflate MRR. */
    public function test_a_plan_with_no_base_snapshot_contributes_nothing(): void
    {
        // JPY 10,000/mo stored as 1000000, never converted to the base currency.
        $this->plan(['amount_cents' => 1000000, 'currency' => $this->foreignCurrency(), 'base_amount_cents' => null]);

        $result = $this->repo()->liveForCampaign($this->campaignId);

        $this->assertSame(1, $result['count']);
        $this->assertSame(0, $result['mrr_cents'], 'an unknown base value counts as zero, not as raw yen');
    }

    /** A plan already in the base currency needs no snapshot and no FX rate. */
    public function test_a_base_currency_plan_converts_without_a_snapshot(): void
    {
        $this->plan(['amount_cents' => 2000, 'currency' => $this->baseCurrency(), 'base_amount_cents' => null]);

        $result = $this->repo()->liveForCampaign($this->campaignId);

        $this->assertSame(2000, $result['mrr_cents'], 'its own amount is the base amount');
        $this->assertSame(0, $result['unconverted'], 'nothing is missing');
    }

    /** But the caller must be able to tell the figure is partial. */
    public function test_the_unconverted_plans_are_counted_separately(): void
    {
        $this->plan(['amount_cents' => 1000000, 'currency' => $this->foreignCurrency(), 'base_amount_cents' => null]);
        $this->plan(['amount_cents' => 2000, 'base_amount_cents' => 2000]);

        $result = $this->repo()->liveForCampaign($this->campaignId);

        $this->assertSame(2, $result['count']);
        $this->assertSame(2000, $result['mrr_cents'], 'only the converted plan contributes');
        $this->assertSame(1, $result['unconverted'], 'and the other is reported as missing');
    }

    public function test_converted_plans_sum_in_base_currency(): void
    {
        // 5000 in a foreign currency that converted to 4000 base.
        $this->plan(['amount_cents' => 5000, 'currency' => $this->foreignCurrency(), 'base_amount_cents' => 4000]);
        $this->plan(['amount_cents' => 1000, 'base_amount_cents' => 1000]);

        $result = $this->repo()->liveForCampaign($this->campaignId);

        $this->assertSame(5000, $result['mrr_cents'], 'base values, not the raw foreign amounts');
        $this->assertSame(0, $result['unconverted']);
    }

    /** A yearly plan is a twelfth of its amount per month, still in base. */
    public function test_interval_normalisation_uses_the_base_value(): void
    {
        $this->plan(['amount_cents' => 120000, 'base_amount_cents' => 120000, 'interval_unit' => 'year']);

        $this->assertSame(10000, $this->repo()->liveForCampaign($this->campaignId)['mrr_cents']);
    }

    /** recurringStats shares the expression, so it must agree. */
    public function test_the_stats_rollup_agrees_with_the_campaign_figure(): void
    {
        $this->plan(['amount_cents' => 1000000, 'currency' => $this->foreignCurrency(), 'base_amount_cents' => null]);
        $this->plan(['amount_cents' => 3000, 'base_amount_cents' => 3000]);

        $stats = $this->repo()->recurringStats(gmdate('Y-m-d'));

        $this->assertSame(3000, (int) $stats['mrr_cents'], 'the unconverted plan is excluded here too');
        $this->assertSame(1, (int) $stats['unconverted']);
    }
}
