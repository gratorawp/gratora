<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Analytics\Event;
use FundKit\Recurring\RecurringPlan;
use WP_REST_Request;

/**
 * A cancel that cannot reach the gateway leaves the plan billing, which is
 * the state an admin most needs to find again afterwards. It reached the
 * screen and was recorded nowhere, so the subscription reported no problems
 * and its health stayed OK.
 */
final class AdminCancelRecordsUnreachableTest extends IntegrationTestCase
{
    private function plan(): RecurringPlan
    {
        $now = gmdate('Y-m-d H:i:s');

        $p = RecurringPlan::make();
        $p->donor_id                = 1;
        // A gateway the manager cannot resolve, which is what the exception is for.
        $p->gateway                 = 'no-such-gateway';
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

    private function cancel(int $id): int
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $req = new WP_REST_Request('POST', "/fundkit/v1/admin/recurring/{$id}/action");
        $req->set_body_params(['action' => 'cancel']);

        return rest_do_request($req)->get_status();
    }

    private function problems(int $planId): array
    {
        return Event::query()
            ->where('recurring_plan_id', $planId)
            ->whereLike('type', 'error.%')
            ->getAll();
    }

    public function test_the_failure_is_recorded_against_the_plan(): void
    {
        $plan = $this->plan();

        $this->cancel((int) $plan->id);

        $rows = $this->problems((int) $plan->id);

        $this->assertNotSame([], $rows, 'a cancel that could not reach the gateway has to leave a record');
        $payload = is_array($rows[0]->payload) ? $rows[0]->payload : [];
        $this->assertStringContainsString('gateway', strtolower((string) ($payload['message'] ?? '')));
    }

    public function test_the_plan_is_not_marked_cancelled(): void
    {
        $plan = $this->plan();

        $this->cancel((int) $plan->id);

        $this->assertSame(
            'active',
            RecurringPlan::query()->find('id', (int) $plan->id)->status,
            'local state must not say stopped while the gateway keeps billing'
        );
    }

    public function test_the_screen_is_told_it_is_a_gateway_problem_not_a_dead_plan(): void
    {
        $this->assertSame(503, $this->cancel((int) $this->plan()->id));
    }
}
