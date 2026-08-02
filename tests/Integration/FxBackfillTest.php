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
}
