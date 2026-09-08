<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Foundation\Plugin;
use FundKit\Recurring\RecurringPlan;
use FundKit\Recurring\RecurringPlanActions;
use FundKit\Recurring\RecurringPlanChange;
use FundKit\Recurring\RecurringResumer;
use InvalidArgumentException;

/**
 * WordPress removes STRICT_TRANS_TABLES, so MySQL stores a datetime it cannot
 * parse as '0000-00-00 00:00:00' rather than refusing it. That is not null and
 * it is already past, so the daily resumer matched the plan and lifted the
 * pause: the three-month pause the org authorised lasted under a day and the
 * donor's card was charged on the next cycle.
 */
final class PauseResumeDateTest extends IntegrationTestCase
{
    private function actions(): RecurringPlanActions
    {
        return Plugin::instance()->container->get(RecurringPlanActions::class);
    }

    private function plan(): RecurringPlan
    {
        $now  = gmdate('Y-m-d H:i:s');
        $plan = RecurringPlan::make();
        $plan->donor_id                = 1;
        $plan->gateway                 = 'offline';
        $plan->gateway_subscription_id = 'sub_pause_' . uniqid();
        $plan->amount_cents            = 2_000;
        $plan->currency                = 'USD';
        $plan->interval_unit           = 'month';
        $plan->interval_count          = 1;
        $plan->status                  = 'active';
        $plan->started_at              = $now;
        $plan->next_payment_at         = gmdate('Y-m-d H:i:s', strtotime('+1 month'));
        $plan->created_at              = $now;
        $plan->updated_at              = $now;
        $plan->save();

        return $plan;
    }

    private function pause(RecurringPlan $plan, string $resumesAt): void
    {
        $this->actions()->pause($plan, $resumesAt, RecurringPlanChange::byAdmin('pause', false));
    }

    private function stored(RecurringPlan $plan): RecurringPlan
    {
        return RecurringPlan::query()->where('id', (int) $plan->id)->get();
    }

    public function test_a_date_nothing_can_parse_is_refused(): void
    {
        $plan = $this->plan();

        $this->expectException(InvalidArgumentException::class);
        $this->pause($plan, 'in 3 months please');
    }

    public function test_a_refused_date_leaves_the_plan_billing_rather_than_half_paused(): void
    {
        $plan = $this->plan();

        try {
            $this->pause($plan, 'sometime next spring');
        } catch (InvalidArgumentException $e) {
            // expected
        }

        $after = $this->stored($plan);
        $this->assertSame('active', (string) $after->status);
        $this->assertNull($after->resume_at);
    }

    /** The whole failure, end to end: the resumer must not see the paused plan. */
    public function test_an_authorised_pause_is_not_lifted_the_next_day(): void
    {
        $plan = $this->plan();
        $this->pause($plan, RecurringPlanActions::monthsFromNow(3));

        Plugin::instance()->container->get(RecurringResumer::class)->run();

        $this->assertSame('paused', (string) $this->stored($plan)->status, 'the pause was lifted on the next daily run');
    }

    public function test_a_date_only_string_is_accepted(): void
    {
        $plan = $this->plan();
        $day  = gmdate('Y-m-d', strtotime('+45 days'));

        $this->pause($plan, $day);

        $after = $this->stored($plan);
        $this->assertSame('paused', (string) $after->status);
        $this->assertSame($day . ' 00:00:00', (string) $after->resume_at);
    }

    public function test_a_date_already_past_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->pause($this->plan(), gmdate('Y-m-d H:i:s', strtotime('-1 day')));
    }

    public function test_a_date_years_out_is_capped_at_a_year(): void
    {
        $plan = $this->plan();
        $this->pause($plan, gmdate('Y-m-d H:i:s', strtotime('+5 years')));

        $resumeAt = strtotime((string) $this->stored($plan)->resume_at);

        $this->assertLessThanOrEqual(strtotime('+12 months') + 60, $resumeAt);
        $this->assertGreaterThan(strtotime('+11 months'), $resumeAt);
    }

    public function test_the_stored_dates_are_a_real_datetime(): void
    {
        $plan = $this->plan();
        $this->pause($plan, RecurringPlanActions::monthsFromNow(2));

        $after = $this->stored($plan);

        foreach (['resume_at', 'next_payment_at'] as $field) {
            $this->assertNotSame('0000-00-00 00:00:00', (string) $after->{$field});
            $this->assertNotFalse(strtotime((string) $after->{$field}), "{$field} is unreadable");
        }
    }
}
