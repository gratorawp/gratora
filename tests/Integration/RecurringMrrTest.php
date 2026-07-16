<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Recurring\RecurringPlan;
use Dono\Recurring\RecurringPlanRepository;

final class RecurringMrrTest extends IntegrationTestCase
{
    private function plan(int $amount, int $base, string $fx, string $currency): void
    {
        $now = '2026-06-01 00:00:00';
        $p = RecurringPlan::make();
        $p->donor_id                = 1;
        $p->gateway                 = 'stripe';
        $p->gateway_subscription_id = 'sub_' . $currency . '_' . $amount;
        $p->amount_cents            = $amount;
        $p->currency                = $currency;
        $p->base_amount_cents       = $base;
        $p->fx_rate                 = $fx;
        $p->interval_unit           = 'month';
        $p->interval_count          = 1;
        $p->status                  = 'active';
        $p->is_test                 = false;
        $p->started_at              = $now;
        $p->created_at              = $now;
        $p->updated_at              = $now;
        $p->save();
    }

    public function test_mrr_normalizes_foreign_plans_to_base_currency(): void
    {
        $this->plan(1000, 1000, '1.00000000', 'USD'); // base plan: 1000 base
        $this->plan(2000, 1000, '0.50000000', 'EUR'); // 2000 EUR at 0.5 -> 1000 base

        $stats = (new RecurringPlanRepository())->recurringStats('2026-06-15');
        // 1000 + 1000 base = 2000, not the raw mixed 1000 + 2000 = 3000.
        $this->assertSame(2000, $stats['mrr_cents']);
    }
}
