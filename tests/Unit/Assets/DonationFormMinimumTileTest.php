<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * The form must never render an amount it refuses. Converted presets are
 * nice-rounded before they are shown, and that rounding can land below the
 * exact conversion of the minimum, so the two have to be reconciled: at SEK
 * 10.5 a $25 preset renders as 260 kr while the exact bar is 262.50 kr.
 *
 * Rates move daily, so this runs the shipped module over the whole plausible
 * range rather than the handful of currencies that happen to be there today.
 */
final class DonationFormMinimumTileTest extends TestCase
{
    use RunsFormModule;

    public function test_no_rate_makes_the_form_refuse_the_tile_it_renders(): void
    {
        $out = $this->runModule('util/fx.js', <<<'JS'
            const failures = [];
            // Steps of 0.01 across every rate a real currency has held.
            for ( let r = 5; r <= 200; r++ ) {
                const rate = r / 100;
                const fx = { base: 'USD', rates: { USD: 1, XYZ: rate } };
                const tile = mod.displayPreset( fx, 2500, 'USD', 'XYZ' );
                const bar  = mod.convertMinimum( fx, 2500, 'USD', 'XYZ' );
                if ( tile < bar ) failures.push( { rate, tile, bar } );
            }
            emit( { failures: failures.slice( 0, 5 ), count: failures.length } );
        JS);

        $this->assertSame(
            0,
            $out['count'],
            'rates where the rendered tile is below the bar: ' . json_encode($out['failures'])
        );
    }

    public function test_the_bar_matches_the_tile_at_the_rates_that_broke_it(): void
    {
        $out = $this->runModule('util/fx.js', <<<'JS'
            const at = ( code, rate ) => {
                const fx = { base: 'USD', rates: { USD: 1, [ code ]: rate } };
                return {
                    tile: mod.displayPreset( fx, 2500, 'USD', code ),
                    bar:  mod.convertMinimum( fx, 2500, 'USD', code ),
                };
            };
            emit( { SEK: at( 'SEK', 10.5 ), DKK: at( 'DKK', 6.9 ), ZAR: at( 'ZAR', 18.5 ) } );
        JS);

        // The same figures the server bar is pinned to in
        // tests/Integration/DonationMinimumTileParityTest.php.
        $this->assertSame(['tile' => 26000, 'bar' => 26000], $out['SEK']);
        $this->assertSame(['tile' => 17000, 'bar' => 17000], $out['DKK']);
        $this->assertSame(['tile' => 46000, 'bar' => 46000], $out['ZAR']);
    }

    /**
     * Giving the tile its rounding must not give away the whole bar: where the
     * tile rounds up, the exact conversion still holds.
     */
    public function test_a_rate_that_rounds_the_tile_up_keeps_the_exact_bar(): void
    {
        $out = $this->runModule('util/fx.js', <<<'JS'
            const fx = { base: 'USD', rates: { USD: 1, GBP: 0.79, JPY: 149.93 } };
            emit( {
                gbpTile: mod.displayPreset( fx, 2500, 'USD', 'GBP' ),
                gbpBar:  mod.convertMinimum( fx, 2500, 'USD', 'GBP' ),
                // Zero-decimal: the bar has to be a whole yen or the message
                // quotes a figure the box cannot hold.
                jpyBar:  mod.convertMinimum( fx, 2500, 'USD', 'JPY' ),
            } );
        JS);

        $this->assertSame(2000, $out['gbpTile']);
        $this->assertSame(1975, $out['gbpBar']);
        $this->assertSame(374900, $out['jpyBar']);
    }

    /**
     * The symptom as a donor meets it: pick the smallest tile on the form,
     * press the button, and be told to give more than the form offers.
     */
    public function test_the_amount_step_accepts_the_tile_the_donor_tapped(): void
    {
        $out = $this->runModule('state/store.js', <<<'JS'
            const fx = { base: 'USD', rates: { USD: 1, SEK: 10.5 } };
            const fxUtil = await import( './util/fx.mjs' );
            const tile = fxUtil.displayPreset( fx, 2500, 'USD', 'SEK' );
            const state = {
                steps: [], preamble: [],
                currency: 'SEK', presetCurrency: 'USD', fx,
                minAmountCents: 2500,
                i18n: { validation: { minAmount: 'Minimum donation is %s.' } },
                values: { amount_cents: tile, cover_fees: false },
                errors: {},
            };
            const under = { ...state, values: { ...state.values, amount_cents: tile - 1 } };
            emit( {
                tile,
                onTile: mod.validateStep( { type: 'amount' }, state ).amount_cents || null,
                under:  mod.validateStep( { type: 'amount' }, under ).amount_cents || null,
            } );
        JS);

        $this->assertSame(26000, $out['tile']);
        $this->assertNull($out['onTile'], 'the form offered this amount, so it cannot refuse it');
        $this->assertNotNull($out['under'], 'the minimum still holds below the tile');
    }

    public function test_an_unconvertible_currency_still_yields_rather_than_guessing(): void
    {
        $out = $this->runModule('util/fx.js', <<<'JS'
            const fx = { base: 'USD', rates: { USD: 1 } };
            emit( { missing: mod.convertMinimum( fx, 2500, 'USD', 'SEK' ) } );
        JS);

        $this->assertNull($out['missing']);
    }
}
