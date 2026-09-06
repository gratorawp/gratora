<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Foundation\Plugin;
use FundKit\Recurring\RecurringPlan;
use FundKit\Recurring\RecurringPlanActions;
use FundKit\Recurring\RecurringPlanChange;
use InvalidArgumentException;
use FundKit\Vendor\Queryable\DB;

/**
 * skipNext writes resume_at, which is the one column the resumer keys on, and
 * it computed that date from the stored next_payment_at with no check at all.
 * pause() was given a guard for exactly this; its sibling was not.
 *
 * A row whose next_payment_at will not parse gives strtotime false, and one
 * month from false is one month from the epoch: a resume date fifty years in
 * the past, which the resumer acts on at once, so the skip the donor asked for
 * silently becomes no skip.
 */
final class SkipNextDateIsValidatedTest extends IntegrationTestCase
{
    private function actions(): RecurringPlanActions
    {
        return Plugin::instance()->container->get(RecurringPlanActions::class);
    }

    private function plan(string $nextPaymentAt): RecurringPlan
    {
        $now = gmdate('Y-m-d H:i:s');

        $p = RecurringPlan::make();
        $p->donor_id                = 1;
        $p->gateway                 = 'offline';
        $p->gateway_subscription_id = 'off_' . uniqid();
        $p->amount_cents            = 2500;
        $p->currency                = 'USD';
        $p->interval_unit           = 'month';
        $p->interval_count          = 1;
        $p->status                  = 'active';
        $p->is_test                 = false;
        $p->started_at              = $now;
        $p->next_payment_at         = $nextPaymentAt;
        $p->created_at              = $now;
        $p->updated_at              = $now;
        $p->save();

        return $p;
    }

    private function change(): RecurringPlanChange
    {
        return RecurringPlanChange::byAdmin('skip_next', false);
    }

    public function test_a_skip_moves_the_next_payment_a_cycle_forward(): void
    {
        $plan = $this->plan(gmdate('Y-m-d H:i:s', time() + 86400));

        $this->actions()->skipNext($plan, $this->change());

        $fresh = RecurringPlan::query()->find('id', (int) $plan->id);
        $this->assertGreaterThan(time(), (int) strtotime((string) $fresh->resume_at));
    }

    /**
     * A stored date nothing can read is not a date to do arithmetic on. Written
     * past the model, because the model is what now refuses it.
     */
    public function test_a_date_that_cannot_be_read_is_refused_rather_than_computed(): void
    {
        $plan = $this->plan(gmdate('Y-m-d H:i:s', time() + 86400));

        DB::table('fundkit_recurring_plans')
            ->where('id', (int) $plan->id)
            ->update(['next_payment_at' => 'not-a-date']);

        $fresh = RecurringPlan::query()->find('id', (int) $plan->id);

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->actions()->skipNext($fresh, $this->change());
        } finally {
            $after = RecurringPlan::query()->find('id', (int) $plan->id);
            $this->assertNull(
                $after->resume_at,
                'a resume date in the past would make the resumer undo the skip at once'
            );
        }
    }
}
