<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Currency\FxBackfill;
use Dono\Currency\FxRates;
use Dono\Donations\Donation;

/**
 * Recording a donation never blocks on FX, so a currency with no configured
 * rate is stored with base_amount_cents null and sits outside every total.
 * Nothing put those rows right once a rate existed: the rate is captured per
 * donation at write time, so configuring the currency later changed nothing
 * that had already happened.
 */
final class FxBackfillTest extends IntegrationTestCase
{
    /** A donation as the create path leaves one when no rate was available. */
    private function unconverted(string $currency, int $cents = 1000): Donation
    {
        $d = Donation::make();
        $d->reference         = 'REF-' . uniqid();
        $d->status            = 'paid';
        $d->gateway           = 'offline';
        $d->kind              = 'donation';
        $d->amount_cents      = $cents;
        $d->currency          = $currency;
        $d->base_amount_cents = null;
        $d->base_currency     = null;
        $d->fx_rate           = null;
        $d->created_at        = gmdate('Y-m-d H:i:s');
        $d->save();

        return $d;
    }

    public function test_a_donation_is_converted_once_a_rate_exists(): void
    {
        // The suite configures USD base with a 1:1 rate for EUR.
        $d = $this->unconverted('EUR', 2500);

        $result = (new FxBackfill(new FxRates()))->run();

        $this->assertSame(1, $result['converted']);

        $reloaded = Donation::query()->find('id', (int) $d->id);
        $this->assertSame(2500, (int) $reloaded->base_amount_cents);
        $this->assertSame('USD', $reloaded->base_currency);
        $this->assertNotNull($reloaded->fx_rate);
    }

    public function test_a_currency_with_no_rate_is_left_alone_and_reported(): void
    {
        $d = $this->unconverted('BGN', 50000);

        $result = (new FxBackfill(new FxRates()))->run();

        $this->assertSame(0, $result['converted'], 'no rate means no conversion, never an invented one');
        $this->assertSame(1, $result['unconvertible']);
        $this->assertSame(['BGN'], $result['currencies']);

        $reloaded = Donation::query()->find('id', (int) $d->id);
        $this->assertNull($reloaded->base_amount_cents, 'the row is untouched so the money is not misreported');
    }

    public function test_pending_reports_what_is_outside_the_totals(): void
    {
        $this->unconverted('BGN', 50000);
        $this->unconverted('BGN', 2500);

        $pending = FxBackfill::pending();

        $this->assertCount(1, $pending, 'grouped by currency, which is the thing an admin fixes');
        $this->assertSame('BGN', $pending[0]['currency']);
        $this->assertSame(2, $pending[0]['count']);
        $this->assertSame(52500, $pending[0]['amount_cents']);
    }

    public function test_a_healthy_site_reports_nothing(): void
    {
        $this->assertSame([], FxBackfill::pending());
    }

    /**
     * Converting a donation changes every total it belongs to. Scope
     * "currency" used to convert and then rebuild nothing, so the money stayed
     * outside the totals while the screen reported success.
     */
    public function test_a_conversion_forces_the_aggregate_rebuild(): void
    {
        // Something for the rebuild to actually count: with no campaigns the
        // pass runs and reports zero, which looks the same as not running.
        $c = \Dono\Campaigns\Campaign::make();
        $c->title      = 'Rebuild target';
        $c->slug       = 'rebuild-target-' . uniqid();
        $c->status     = 'published';
        $c->currency   = 'USD';
        $c->goal_type  = 'amount';
        $c->created_at = gmdate('Y-m-d H:i:s');
        $c->updated_at = $c->created_at;
        $c->save();
        $this->unconverted('EUR', 2500);

        $req = new \WP_REST_Request('POST', '/dono/v1/admin/advanced/recalculate');
        $req->set_body_params(['scope' => 'currency']);
        $counts = (array) (rest_do_request($req)->get_data()['counts'] ?? []);

        $this->assertSame(1, $counts['converted_donations'] ?? 0);
        $this->assertGreaterThan(0, $counts['campaigns'] ?? 0, 'campaign totals must be rebuilt after a conversion');
    }

    public function test_currency_scope_with_nothing_to_convert_rebuilds_nothing(): void
    {
        $req = new \WP_REST_Request('POST', '/dono/v1/admin/advanced/recalculate');
        $req->set_body_params(['scope' => 'currency']);
        $counts = (array) (rest_do_request($req)->get_data()['counts'] ?? []);

        $this->assertSame(0, $counts['converted_donations'] ?? -1);
        $this->assertSame(0, $counts['campaigns'] ?? -1, 'no conversion means no needless full rebuild');
    }
}
