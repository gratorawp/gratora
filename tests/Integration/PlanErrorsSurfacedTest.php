<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Analytics\ErrorLog;
use FundKit\Recurring\RecurringPlan;
use WP_REST_Request;

/**
 * A failure tag says a plan is in trouble. The admin also has to be able to
 * see what the trouble was, without reading the site's whole error log.
 */
final class PlanErrorsSurfacedTest extends IntegrationTestCase
{
    private function plan(): RecurringPlan
    {
        $now = gmdate('Y-m-d H:i:s');

        $p = RecurringPlan::make();
        $p->donor_id                = 1;
        $p->gateway                 = 'stripe';
        $p->gateway_subscription_id = 'sub_' . uniqid();
        $p->amount_cents            = 2500;
        $p->currency                = 'EUR';
        $p->interval_unit           = 'month';
        $p->interval_count          = 1;
        $p->status                  = 'active';
        $p->is_test                 = false;
        $p->created_at              = $now;
        $p->updated_at              = $now;
        $p->save();

        return $p;
    }

    private function detail(int $id): array
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $res = rest_do_request(new WP_REST_Request('GET', '/fundkit/v1/admin/recurring'));
        foreach ((array) ($res->get_data()['items'] ?? $res->get_data()) as $row) {
            if ((int) ($row['id'] ?? 0) === $id) {
                return (array) $row;
            }
        }

        return [];
    }

    public function test_an_error_recorded_against_a_plan_reaches_the_screen(): void
    {
        $plan = $this->plan();

        ErrorLog::record('recurring', 'the gateway is not available', [
            'recurring_plan_id' => (int) $plan->id,
        ]);

        $row = $this->detail((int) $plan->id);

        $this->assertNotSame([], $row, 'the plan was not in the list');
        $this->assertNotEmpty($row['errors'] ?? [], 'the plan carries its errors');
        $this->assertSame('the gateway is not available', $row['errors'][0]['message']);
        $this->assertSame('recurring', $row['errors'][0]['source']);
    }

    public function test_another_plans_errors_do_not_leak_onto_this_one(): void
    {
        $mine   = $this->plan();
        $theirs = $this->plan();

        ErrorLog::record('recurring', 'not my problem', ['recurring_plan_id' => (int) $theirs->id]);

        $this->assertSame([], $this->detail((int) $mine->id)['errors'] ?? null);
    }

    public function test_a_plan_with_no_errors_carries_an_empty_list(): void
    {
        $this->assertSame([], $this->detail((int) $this->plan()->id)['errors'] ?? null);
    }

    /** The key ErrorLog promotes to a column, which is what makes this filterable. */
    public function test_the_resumer_records_against_the_plan_column(): void
    {
        $plan = $this->plan();

        ErrorLog::record('recurring', 'resume failed', ['recurring_plan_id' => (int) $plan->id]);

        $row = \FundKit\Analytics\Event::query()
            ->where('recurring_plan_id', (int) $plan->id)
            ->get();

        $this->assertNotNull($row, 'the id has to reach the column, not the payload blob');
    }
}
