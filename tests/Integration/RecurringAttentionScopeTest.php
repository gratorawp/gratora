<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Recurring\RecurringPlan;
use WP_REST_Request;

/**
 * The Needs attention card counts plans still running; the Health filter under
 * it is deliberately wider and reaches plans that have since ended. Two numbers
 * for one word is fine as long as the screen says both, so a click on the
 * filter does not answer with a figure the card never showed.
 */
final class RecurringAttentionScopeTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        foreach (range(1, 2) as $n)  $this->plan('past_due', 'live-' . $n);
        foreach (range(1, 10) as $n) $this->plan('cancelled', 'ended-' . $n);
    }

    private function plan(string $status, string $tag): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $p   = RecurringPlan::make();
        $p->donor_id       = 1;
        $p->gateway        = 'offline';
        $p->gateway_subscription_id = 'sub-' . $tag;
        $p->status         = $status;
        $p->amount_cents   = 1000;
        $p->currency       = 'USD';
        $p->interval_unit  = 'month';
        $p->interval_count = 1;
        $p->failed_renewals_count = 3;
        $p->is_test        = false;
        $p->created_at     = $now;
        $p->updated_at     = $now;
        $p->save();
    }

    public function test_the_card_publishes_both_counts(): void
    {
        $stats = (array) rest_do_request(new WP_REST_Request('GET', '/fundkit/v1/admin/recurring/stats'))->get_data();

        $this->assertSame(2, (int) ($stats['failing_count'] ?? -1), 'plans still running');
        $this->assertSame(12, (int) ($stats['failing_ever_count'] ?? -1), 'plans that ever failed');
    }

    /** The wider number is the one the filter answers with. */
    public function test_the_filter_returns_what_the_wider_count_promised(): void
    {
        $req = new WP_REST_Request('GET', '/fundkit/v1/admin/recurring');
        $req->set_param('failing', 1);

        $res = rest_do_request($req);
        $this->assertSame(200, $res->get_status());
        $this->assertSame('12', (string) ($res->get_headers()['X-WP-Total'] ?? ''));
    }
}
