<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Recurring\RecurringPlan;
use WP_REST_Request;

/**
 * An org sets recurring up entirely in test mode, so the first plans it ever
 * creates are the ones this screen hides. A list that answers "none" while
 * holding three of them sends someone to debug a gateway that worked.
 */
final class RecurringTestVisibilityTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function plan(bool $isTest): RecurringPlan
    {
        static $n = 0;
        $n++;

        $p = RecurringPlan::make();
        $p->donor_id                = 1;
        $p->gateway                 = 'stripe';
        $p->gateway_subscription_id = 'sub_vis_' . ($isTest ? 't' : 'l') . $n;
        $p->gateway_customer_id     = 'cus_vis';
        $p->amount_cents            = 2500;
        $p->currency                = 'USD';
        $p->interval_unit           = 'week';
        $p->interval_count          = 1;
        $p->status                  = 'active';
        $p->is_test                 = $isTest;
        $p->started_at              = '2026-08-01 00:00:00';
        $p->next_payment_at         = '2026-08-18 00:00:00';
        $p->created_at              = '2026-08-01 00:00:00';
        $p->save();

        return $p;
    }

    private function index(array $params = []): \WP_REST_Response
    {
        $req = new WP_REST_Request('GET', '/dono/v1/admin/recurring');
        foreach ($params as $k => $v) {
            $req->set_param($k, $v);
        }

        return rest_do_request($req);
    }

    public function test_test_plans_are_hidden_but_counted(): void
    {
        $this->plan(true);
        $this->plan(true);

        $res = $this->index();

        $this->assertSame(200, $res->get_status());
        $this->assertSame([], (array) $res->get_data(), 'hidden from the list');

        // The count is what lets the screen offer to reveal them. Without it the
        // toggle either never appears or appears on every site that has none.
        $this->assertSame('2', $res->get_headers()['X-Dono-Test-Hidden'] ?? null);
    }

    public function test_asking_for_them_returns_them(): void
    {
        $this->plan(true);

        $res = $this->index(['include_test' => true]);

        $this->assertCount(1, (array) $res->get_data());

        // Nothing is hidden once the caller has opted in, so the number would
        // only ever be noise.
        $this->assertArrayNotHasKey('X-Dono-Test-Hidden', $res->get_headers());
    }

    /** @return array<string,mixed> */
    private function stats(array $params = []): array
    {
        $req = new WP_REST_Request('GET', '/dono/v1/admin/recurring/stats');
        foreach ($params as $k => $v) {
            $req->set_param($k, $v);
        }
        $res = rest_do_request($req);

        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));

        return (array) $res->get_data();
    }

    public function test_the_figures_leave_test_plans_out_by_default(): void
    {
        $this->plan(true);

        $stats = $this->stats();

        // The state a site sits in unless somebody asks otherwise, and the one
        // a figure gets quoted from.
        $this->assertSame(0, (int) $stats['active_count']);
        $this->assertSame(0, (int) $stats['mrr_cents']);
    }

    public function test_asking_for_test_plans_puts_them_in_the_figures(): void
    {
        $this->plan(true);

        $stats = $this->stats(['include_test' => true]);

        // Listing three plans above a Monthly recurring revenue of zero cannot
        // be read, and during setup the figures are the thing being checked:
        // there is no other way to find out whether MRR computes at all.
        $this->assertSame(1, (int) $stats['active_count']);
        $this->assertGreaterThan(0, (int) $stats['mrr_cents']);
    }

    public function test_a_live_plan_is_never_counted_as_hidden(): void
    {
        $this->plan(false);

        $res = $this->index();

        $this->assertCount(1, (array) $res->get_data());
        $this->assertSame('0', $res->get_headers()['X-Dono-Test-Hidden'] ?? null);
    }
}
