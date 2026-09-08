<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Currency\FxBackfill;
use FundKit\Currency\FxRates;
use FundKit\Donations\Donation;
use FundKit\Foundation\Plugin;
use WP_REST_Request;

/**
 * The currency pass was the one pass the recalculate loop's paging never
 * reached: run to completion inside the request, no cursor written, and first
 * in the order, so it could never be deferred either. On a backlog big enough
 * to outlive the time limit the request died before recording anything, and the
 * next press began the identical walk. That is the forever loop the paging was
 * added to end.
 */
final class RecalculateCurrencyBudgetTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        delete_option('fundkit_recalculate_cursor');

        update_option('fundkit_currency_locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD', 'EUR'],
        ]);
        update_option('fundkit_fx_rates', ['base' => 'USD', 'rates' => ['EUR' => 1.1]]);
    }

    protected function tearDown(): void
    {
        delete_option('fundkit_recalculate_cursor');
        parent::tearDown();
    }

    private function unconverted(int $count, string $currency = 'EUR'): void
    {
        $now = gmdate('Y-m-d H:i:s');

        for ($i = 0; $i < $count; $i++) {
            $d = Donation::make();
            $d->donor_id          = 1;
            $d->reference         = 'FX-' . bin2hex(random_bytes(4));
            $d->amount_cents      = 1000;
            $d->base_amount_cents = null;
            $d->currency          = $currency;
            $d->status            = 'paid';
            $d->gateway           = 'offline';
            $d->is_test           = false;
            $d->paid_at           = $now;
            $d->created_at        = $now;
            $d->updated_at        = $now;
            $d->save();
        }
    }

    private function backfill(): FxBackfill
    {
        return new FxBackfill(Plugin::instance()->container->get(FxRates::class));
    }

    public function test_a_pass_out_of_budget_stops_and_says_where_it_got_to(): void
    {
        $this->unconverted(3);

        // A budget already spent: the pass still handles its first page, then
        // reports itself unfinished rather than walking the whole backlog.
        $out = $this->backfill()->run(0, microtime(true) - 1);

        $this->assertFalse($out['done']);
        $this->assertGreaterThan(0, $out['after'], 'and it says where to resume');
        $this->assertGreaterThan(0, $out['converted'], 'having made progress, so the caller cannot loop');
    }

    public function test_resuming_from_the_cursor_finishes_the_backlog(): void
    {
        $this->unconverted(3);

        $first = $this->backfill()->run(0, microtime(true) - 1);
        $again = $this->backfill()->run((int) $first['after'], INF);

        $this->assertTrue($again['done']);
        $this->assertSame(
            0,
            $this->stillPending(),
            'every donation is in the totals once the two passes are through'
        );
    }

    /**
     * A currency with no rate keeps base_amount_cents null, so the query hands
     * the same rows back on every call. Only the cursor moves past them: seeded
     * at zero, a budget-limited pass spends every request re-reading the same
     * stranded page and the donations behind it are never converted at all.
     */
    public function test_the_cursor_is_what_gets_past_a_currency_with_no_rate(): void
    {
        $this->unconverted(2, 'JPY');

        $all = $this->backfill()->run(0, INF);
        $this->assertSame(2, (int) $all['unconvertible'], 'both are stranded, and stay null');

        // Resumed past them. Without the cursor the same two come back on every
        // call, and a budget-limited pass spends each request re-reading them
        // while the donations behind them are never converted at all.
        $resumed = $this->backfill()->run((int) $all['after'], INF);

        $this->assertSame(0, (int) $resumed['unconvertible'], 'the pass moved past what it can never convert');
    }

    public function test_the_route_records_where_the_currency_pass_got_to(): void
    {
        $this->unconverted(3);

        $res = $this->recalculate();
        $this->assertSame(200, $res->get_status());

        // Whether one request finishes depends on the machine, so what is
        // asserted is that pressing again completes rather than repeating.
        for ($i = 0; $i < 5 && ! ($res->get_data()['done'] ?? false); $i++) {
            $res = $this->recalculate();
        }

        $this->assertTrue((bool) $res->get_data()['done']);
        $this->assertSame(0, $this->stillPending());
        $this->assertFalse(get_option('fundkit_recalculate_cursor'), 'a finished run leaves no cursor');
    }

    /** How many donations are still outside every total. */
    private function stillPending(): int
    {
        return (int) Donation::query()->whereNull('base_amount_cents')->count();
    }

    private function recalculate(): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/fundkit/v1/admin/tools/recalculate');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['scope' => 'currency']));

        return rest_do_request($req);
    }
}
