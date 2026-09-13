<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Currency\FxBackfill;
use Gratora\Currency\FxRates;
use Gratora\Foundation\Plugin;
use Gratora\Recurring\RecurringPlan;
use WP_REST_Request;

/**
 * The plan half of the currency pass had the budget the donation half was
 * given, and none of the paging that goes with it.
 *
 * It walked every plan whose base amount was null in one request, however many
 * that was, and had no cursor to come back to. On a backlog big enough to
 * outlive the time limit the request died having recorded nothing, and the
 * next press began the identical walk: the forever loop the paging exists to
 * end, in the pass the paging never reached.
 */
final class RecalculatePlanBudgetTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        delete_option('gratora_recalculate_cursor');

        update_option('gratora_currency_locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD', 'EUR'],
        ]);
        update_option('gratora_fx_rates', ['base' => 'USD', 'rates' => ['EUR' => 1.1]]);
    }

    protected function tearDown(): void
    {
        delete_option('gratora_recalculate_cursor');
        parent::tearDown();
    }

    private function backfill(): FxBackfill
    {
        return new FxBackfill(Plugin::instance()->container->get(FxRates::class));
    }

    private function unconvertedPlans(int $count, string $currency = 'EUR'): void
    {
        $now = gmdate('Y-m-d H:i:s');

        for ($i = 0; $i < $count; $i++) {
            $p = RecurringPlan::make();
            $p->donor_id                = 1;
            $p->gateway                 = 'stripe';
            $p->gateway_subscription_id = 'sub_' . bin2hex(random_bytes(4));
            $p->amount_cents            = 2000;
            $p->base_amount_cents       = null;
            $p->currency                = $currency;
            $p->interval_unit           = 'month';
            $p->interval_count          = 1;
            $p->status                  = 'active';
            $p->started_at              = $now;
            $p->created_at              = $now;
            $p->updated_at              = $now;
            $p->save();
        }
    }

    private function stillNull(): int
    {
        return (int) RecurringPlan::query()->whereNull('base_amount_cents')->count();
    }

    private function recalculate(): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/gratora/v1/admin/tools/recalculate');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['scope' => 'currency']));

        return rest_do_request($req);
    }

    public function test_a_plan_pass_out_of_budget_stops_and_says_where_it_got_to(): void
    {
        $this->unconvertedPlans(3);

        $out = $this->backfill()->run(0, microtime(true) - 1);

        $this->assertFalse($out['done']);
        $this->assertGreaterThan(0, (int) $out['after_plan'], 'and it says where to resume');
        $this->assertGreaterThan(0, (int) $out['plans'], 'having made progress, so the caller cannot loop');
    }

    public function test_resuming_from_the_plan_cursor_finishes_the_backlog(): void
    {
        $this->unconvertedPlans(3);

        $first = $this->backfill()->run(0, microtime(true) - 1);
        $again = $this->backfill()->run((int) $first['after'], INF, (int) $first['after_plan']);

        $this->assertTrue($again['done']);
        $this->assertSame(0, $this->stillNull(), 'every plan is in the figures once the two passes are through');
    }

    /**
     * A currency with no rate keeps base_amount_cents null, so the query hands
     * the same plans back on every call. Only the cursor moves past them.
     */
    public function test_the_plan_cursor_gets_past_a_currency_with_no_rate(): void
    {
        $this->unconvertedPlans(2, 'JPY');

        $all = $this->backfill()->run(0, INF);
        $this->assertSame(2, (int) $all['unconvertible'], 'both are stranded, and stay null');

        $resumed = $this->backfill()->run((int) $all['after'], INF, (int) $all['after_plan']);

        $this->assertSame(0, (int) $resumed['unconvertible'], 'the pass moved past what it can never convert');
    }

    /** A finished pass still reports done, so the caller stops asking. */
    public function test_a_plan_backlog_inside_the_budget_finishes_in_one_go(): void
    {
        $this->unconvertedPlans(3);

        $out = $this->backfill()->run(0, INF);

        $this->assertTrue($out['done']);
        $this->assertSame(3, (int) $out['plans']);
        $this->assertSame(0, $this->stillNull());
    }

    public function test_the_route_records_where_the_plan_pass_got_to(): void
    {
        $this->unconvertedPlans(3);

        $res = $this->recalculate();
        $this->assertSame(200, $res->get_status());

        for ($i = 0; $i < 5 && ! ( $res->get_data()['done'] ?? false ); $i++) {
            $res = $this->recalculate();
        }

        $this->assertTrue((bool) $res->get_data()['done']);
        $this->assertSame(0, $this->stillNull());
    }
}
