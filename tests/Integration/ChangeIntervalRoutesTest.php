<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Recurring\RecurringPlan;
use WP_REST_Request;

/**
 * Both surfaces reach the same orchestration. Only the HTTP shape differs, so
 * what is worth pinning is that the admin route and the donor's route agree
 * about what a valid schedule is.
 */
final class ChangeIntervalRoutesTest extends IntegrationTestCase
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

    private function adminAction(int $id, array $body): int
    {
        return $this->adminResponse($id, $body)->get_status();
    }

    private function adminResponse(int $id, array $body): \WP_REST_Response
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $req = new WP_REST_Request('POST', "/gratora/v1/admin/recurring/{$id}/action");
        $req->set_body_params($body);

        return rest_do_request($req);
    }

    /** Whatever the gateway state, the code says whether the name was known. */
    private function errorCode(\WP_REST_Response $res): string
    {
        $data = $res->get_data();

        return (string) ($data['code'] ?? '');
    }

    /**
     * Asserted on the error code, not the status: whether an unregistered
     * gateway answers 503 or the orchestration answers 422 depends on what
     * else the suite has registered, and neither says the name was unknown.
     */
    public function test_the_admin_route_knows_the_action(): void
    {
        $res = $this->adminResponse((int) $this->plan()->id, [
            'action'    => 'change_interval',
            'frequency' => 'yearly',
        ]);

        $this->assertNotSame(404, $res->get_status(), 'the route exists');
        $this->assertNotSame('gratora_invalid_action', $this->errorCode($res), 'and the action name is known');
    }

    public function test_a_schedule_this_site_cannot_name_is_refused(): void
    {
        $this->assertSame(422, $this->adminAction((int) $this->plan()->id, [
            'action'    => 'change_interval',
            'frequency' => 'every_37_days',
        ]));
    }

    public function test_an_absent_frequency_is_refused_rather_than_defaulted(): void
    {
        $this->assertSame(422, $this->adminAction((int) $this->plan()->id, [
            'action' => 'change_interval',
        ]));
    }

    public function test_the_plan_payload_carries_the_capability_and_the_options(): void
    {
        $plan = $this->plan();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        foreach ((array) rest_do_request(new WP_REST_Request('GET', '/gratora/v1/admin/recurring'))->get_data() as $row) {
            if ((int) ($row['id'] ?? 0) !== (int) $plan->id) {
                continue;
            }

            $this->assertArrayHasKey('can_change_interval', $row);
            $this->assertSame('monthly', $row['frequency']);
            $this->assertContains('quarterly', $row['frequency_options']);

            return;
        }

        $this->fail('the plan was not in the list');
    }
}
