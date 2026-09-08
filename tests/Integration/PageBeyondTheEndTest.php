<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Analytics\ErrorLog;
use FundKit\Analytics\Event;
use FundKit\Donors\DonorService;
use FundKit\Foundation\Plugin;
use FundKit\Recurring\RecurringPlan;
use WP_REST_Request;

/**
 * ($page - 1) * $perPage leaves the integer range on a large page. Under
 * strict_types the int-typed offset() throws, and where the value is cast back
 * it wraps to a large negative and MySQL refuses the statement. Either way the
 * screen dies rather than showing an empty page.
 */
final class PageBeyondTheEndTest extends IntegrationTestCase
{
    private const HUGE = '100000000000000000';

    private function get(string $route, array $params): \WP_REST_Response
    {
        $req = new WP_REST_Request('GET', $route);
        $req->set_query_params($params);

        return rest_do_request($req);
    }

    public function test_a_donor_timeline_past_the_end_is_an_empty_page(): void
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('page-' . uniqid() . '@example.test', ['first_name' => 'Ada']);

        // Without a row the timeline short-circuits before it computes an
        // offset, so the overflow is never reached and the test proves nothing.
        $e = Event::make();
        $e->type       = 'donor.updated';
        $e->donor_id   = (int) $donor->id;
        $e->occurred_at = gmdate('Y-m-d H:i:s');
        $e->save();

        $res = $this->get('/fundkit/v1/admin/donors/' . (int) $donor->id . '/events', [
            'page' => self::HUGE, 'per_page' => 100,
        ]);

        $this->assertSame(200, $res->get_status());
    }

    public function test_the_tools_log_past_the_end_is_an_empty_page(): void
    {
        ErrorLog::record('gateway.intent', 'PayPal has no live credentials.');

        $res = $this->get('/fundkit/v1/admin/tools/log', ['page' => self::HUGE, 'per_page' => 100]);

        $this->assertSame(200, $res->get_status());
        $this->assertSame([], (array) ($res->get_data()['items'] ?? null));
        $this->assertGreaterThan(0, (int) ($res->get_data()['total'] ?? 0), 'and the count is still the real one');
    }

    public function test_the_plan_list_past_the_end_is_an_empty_page(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $p = RecurringPlan::make();
        $p->gateway        = 'offline';
        $p->status         = 'active';
        $p->amount_cents   = 1000;
        $p->currency       = 'USD';
        $p->interval_unit  = 'month';
        $p->interval_count = 1;
        $p->is_test        = false;
        $p->started_at     = $now;
        $p->created_at     = $now;
        $p->updated_at     = $now;
        $p->save();

        $res = $this->get('/fundkit/v1/admin/recurring', ['page' => self::HUGE, 'per_page' => 100]);

        $this->assertSame(200, $res->get_status());
    }
}
