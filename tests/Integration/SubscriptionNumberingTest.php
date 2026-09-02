<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Foundation\Upgrade\NumberExistingSubscriptions;
use FundKit\Recurring\RecurringPlan;

/**
 * Subscriptions carry a number from the same series as donations, receipts and
 * refunds, rather than one derived from a row id.
 */
final class SubscriptionNumberingTest extends IntegrationTestCase
{
    private function plan( string $reference = '' ): RecurringPlan
    {
        $plan = RecurringPlan::make();
        $plan->reference               = $reference;
        $plan->donor_id                = 1;
        $plan->gateway                 = 'stripe';
        $plan->gateway_subscription_id = 'sub_' . bin2hex(random_bytes(3));
        $plan->amount_cents            = 2500;
        $plan->currency                = 'USD';
        $plan->interval_unit           = 'month';
        $plan->interval_count          = 1;
        $plan->status                  = 'active';
        $plan->started_at              = '2026-01-01 00:00:00';
        $plan->created_at              = '2026-01-01 00:00:00';
        $plan->updated_at              = '2026-01-01 00:00:00';
        $plan->save();

        return $plan;
    }

    public function test_a_minted_reference_uses_the_subscription_series(): void
    {
        $this->assertMatchesRegularExpression('/^SUB-/', RecurringPlan::mintReference());
    }

    public function test_the_series_advances(): void
    {
        $first  = RecurringPlan::mintReference();
        $second = RecurringPlan::mintReference();

        $this->assertNotSame($first, $second);
    }

    /** Test plans are numbered apart, exactly as test donations are. */
    public function test_test_mode_has_its_own_series(): void
    {
        $this->assertNotSame(
            RecurringPlan::mintReference(false),
            RecurringPlan::mintReference(true)
        );
    }

    public function test_a_stored_reference_is_what_is_shown(): void
    {
        $plan = $this->plan('SUB-2026-00042');

        $this->assertSame('SUB-2026-00042', $plan->reference());
    }

    /**
     * A row written before the column existed, or by a creation path that
     * forgets, still shows something rather than a blank.
     */
    public function test_an_unnumbered_plan_falls_back_to_its_id(): void
    {
        $plan = $this->plan('');

        $this->assertSame(sprintf('SUB-%04d', (int) $plan->id), $plan->reference());
    }

    public function test_the_backfill_numbers_old_plans(): void
    {
        $a = $this->plan('');
        $b = $this->plan('');

        $routine = new NumberExistingSubscriptions();
        for ($i = 0; $i < 5 && ! $routine->step(); $i++) {
            // Batched; loop until it reports it is finished.
        }

        foreach ([$a, $b] as $seeded) {
            $fresh = RecurringPlan::query()->find('id', (int) $seeded->id);
            $this->assertMatchesRegularExpression('/^SUB-/', (string) $fresh->reference);
        }
    }

    public function test_the_backfill_leaves_a_numbered_plan_alone(): void
    {
        $plan = $this->plan('SUB-2026-00007');

        $routine = new NumberExistingSubscriptions();
        for ($i = 0; $i < 5 && ! $routine->step(); $i++) {
            // As above.
        }

        $this->assertSame(
            'SUB-2026-00007',
            (string) RecurringPlan::query()->find('id', (int) $plan->id)->reference
        );
    }
}
