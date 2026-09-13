<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Currency\FxBackfill;
use Gratora\Currency\FxRates;
use Gratora\Foundation\Plugin;
use Gratora\Recurring\RecurringPlan;
use Gratora\Vendor\Queryable\DB;

/**
 * The currency backfill gives a plan a base amount. It must give it nothing
 * else.
 *
 * It loads a chunk of plans, then works through them one at a time asking the
 * rate service for each. Anything a gateway commits while that is running was
 * written back over from the copy loaded before it: a cancellation became
 * active again, taking its money back into MRR and out of churn for good,
 * because no webhook is coming twice for a subscription already ended.
 *
 * The repository states the rule for this table twenty lines from the figures
 * it feeds, and uses a targeted update for exactly this reason.
 *
 * The race is staged rather than raced: the rate service reads its option once
 * per plan, so the second read is the moment between the first plan being
 * written and the second, and the concurrent write goes in there.
 */
final class BackfillKeepsWhatItDidNotConvertTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        remove_all_filters('option_' . FxRates::OPTION);
        delete_option(FxRates::OPTION);
        parent::tearDown();
    }

    private function ratesAre(string $base, array $rates): void
    {
        update_option(FxRates::OPTION, ['base' => $base, 'rates' => $rates]);
    }

    private function plan(string $currency, array $overrides = []): RecurringPlan
    {
        $now = gmdate('Y-m-d H:i:s');

        $p = RecurringPlan::make();
        $p->donor_id                = 4242;
        $p->gateway                 = 'stripe';
        $p->gateway_subscription_id = 'sub_' . uniqid();
        $p->amount_cents            = 2000;
        $p->currency                = $currency;
        $p->base_amount_cents       = null;
        $p->interval_unit           = 'month';
        $p->interval_count          = 1;
        $p->status                  = 'active';
        $p->payments_count          = 1;
        $p->total_paid_cents        = 2000;
        $p->started_at              = $now;
        $p->created_at              = $now;
        $p->updated_at              = $now;
        $p->save();

        if ($overrides !== []) {
            RecurringPlan::query()->where('id', (int) $p->id)->update($overrides);
        }

        return $p;
    }

    /** Runs $then between the first plan being written and the second. */
    private function whenTheSecondPlanIsReached(callable $then): void
    {
        $reads = 0;
        add_filter('option_' . FxRates::OPTION, static function ($value) use (&$reads, $then) {
            $reads++;
            if ($reads === 2) {
                $then();
            }
            return $value;
        });
    }

    private function backfill(): FxBackfill
    {
        return new FxBackfill(Plugin::instance()->container->get(FxRates::class));
    }

    public function test_a_cancellation_that_lands_mid_pass_survives_it(): void
    {
        $this->ratesAre('USD', ['USD' => 1.0, 'EUR' => 0.5]);

        $first  = $this->plan('EUR');
        $second = $this->plan('EUR');

        $this->whenTheSecondPlanIsReached(static function () use ($second): void {
            // What a gateway webhook commits: the plan is over.
            DB::table('gratora_recurring_plans')
                ->where('id', (int) $second->id)
                ->update([
                    'status'          => 'cancelled',
                    'cancelled_at'    => gmdate('Y-m-d H:i:s'),
                    'next_payment_at' => null,
                ]);
        });

        $this->backfill()->run();

        $after = RecurringPlan::query()->find('id', (int) $second->id);

        $this->assertSame('cancelled', (string) $after->status, 'the cancellation stands');
        $this->assertNotNull($after->cancelled_at);
        $this->assertNotNull($after->base_amount_cents, 'and it still got its base amount');
        $this->assertSame((int) $first->id, (int) $first->id);
    }

    /** The counters a renewal bumps are not the backfill's to rewrite either. */
    public function test_a_renewal_that_lands_mid_pass_survives_it(): void
    {
        $this->ratesAre('USD', ['USD' => 1.0, 'EUR' => 0.5]);

        $this->plan('EUR');
        $second = $this->plan('EUR');

        $this->whenTheSecondPlanIsReached(static function () use ($second): void {
            DB::table('gratora_recurring_plans')
                ->where('id', (int) $second->id)
                ->update(['payments_count' => 9, 'total_paid_cents' => 18000]);
        });

        $this->backfill()->run();

        $after = RecurringPlan::query()->find('id', (int) $second->id);

        $this->assertSame(9, (int) $after->payments_count);
        $this->assertSame(18000, (int) $after->total_paid_cents);
    }

    /** And the thing it is for still happens. */
    public function test_it_still_converts(): void
    {
        $this->ratesAre('USD', ['USD' => 1.0, 'EUR' => 0.5]);

        $plan = $this->plan('EUR');

        $result = $this->backfill()->run();

        $after = RecurringPlan::query()->find('id', (int) $plan->id);

        $this->assertSame(1, (int) $result['plans']);
        // 2000 minor units at EUR 0.5 to the base is 4000.
        $this->assertSame(4000, (int) $after->base_amount_cents);
        $this->assertNotNull($after->fx_rate);
    }
}
