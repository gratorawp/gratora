<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Assets;

use Dono\Foundation\Helpers\Money;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Every figure on the public form goes through one formatter: the preset
 * tiles, the fee line, the total, the submit button and the gateway's own pay
 * screen. It has to name the currency the donor is actually charged in, and it
 * has to show the amount that will actually be taken.
 *
 * Mirrors Money::format, which the receipt for the same donation is rendered
 * with, so the two cannot disagree about one donation.
 */
final class DonationFormMoneyDisplayTest extends TestCase
{
    use RunsFormModule;

    public function test_a_currency_outside_the_symbol_table_never_wears_the_form_s_symbol(): void
    {
        $out = $this->runModule('util/format.js', <<<'JS'
            mod.setActiveNumberFormat(
                { decimalPlaces: 2, decimalSep: '.', thousandSep: ',', symbolPosition: 'before', symbol: '$' },
                'USD'
            );
            emit( {
                ron: mod.formatAmount( 46000, 'RON' ),
                bgn: mod.formatAmount( 25000, 'BGN' ),
                // Not a currency anyone offers: it must read as itself rather
                // than borrow the dollar sign.
                unknown: mod.formatAmount( 46000, 'XTS' ),
                own: mod.formatAmount( 10000, 'USD' ),
            } );
        JS);

        $this->assertSame('RON460.00', $out['ron']);
        $this->assertSame('BGN250.00', $out['bgn']);
        $this->assertSame('XTS460.00', $out['unknown']);
        $this->assertSame('$100.00', $out['own']);
    }

    /**
     * The dangerous direction: a weak base currency whose symbol IS in the
     * table lends it to a strong one, so 500 RON reads as 500 forint.
     */
    public function test_a_form_in_another_currency_does_not_lend_its_symbol_either(): void
    {
        $out = $this->runModule('util/format.js', <<<'JS'
            mod.setActiveNumberFormat(
                { decimalPlaces: 2, decimalSep: '.', thousandSep: ',', symbolPosition: 'before', symbol: 'Ft' },
                'HUF'
            );
            emit( { ron: mod.formatAmount( 50000, 'RON' ), own: mod.formatAmount( 50000, 'HUF' ) } );
        JS);

        $this->assertSame('RON500.00', $out['ron']);
        $this->assertSame('Ft500.00', $out['own']);
    }

    /**
     * "Decimal places: 0" is a display preference. Applying it to an amount
     * that has cents shows the donor one figure and charges another: a $25.50
     * donation with a $1.04 covered fee reads "$26" on the button and takes
     * $26.54.
     */
    public function test_the_no_cents_setting_never_hides_cents_that_are_charged(): void
    {
        $out = $this->runModule('util/format.js', <<<'JS'
            mod.setActiveNumberFormat(
                { decimalPlaces: 0, decimalSep: '.', thousandSep: ',', symbolPosition: 'before', symbol: '$' },
                'USD'
            );
            emit( {
                withCents: mod.formatAmount( 2654, 'USD' ),
                fee:       mod.formatAmount( 104, 'USD' ),
                whole:     mod.formatAmount( 2500, 'USD' ),
                // The preference describes the currency this form is in only.
                foreign:   mod.formatAmount( 2654, 'EUR' ),
            } );
        JS);

        $this->assertSame('$26.54', $out['withCents']);
        $this->assertSame('$1.04', $out['fee']);
        $this->assertSame('$25', $out['whole'], 'a whole amount may still honour the preference');
        $this->assertSame('€26.54', $out['foreign']);
    }

    /**
     * A form may be authored in a currency the org does not keep its books in,
     * and the number format the server hands it is the one it resolved for that
     * currency. The public form has no window.dono to fall back on, so reading
     * the preference against a default instead would apply it to whichever
     * currency the donor happened to switch to and withhold it from the one the
     * form is actually in.
     */
    public function test_the_no_cents_preference_follows_the_currency_the_form_is_authored_in(): void
    {
        $out = $this->runModule('util/format.js', <<<'JS'
            mod.setActiveNumberFormat(
                { decimalPlaces: 0, decimalSep: '.', thousandSep: ',', symbolPosition: 'before', symbol: '€' },
                'EUR'
            );
            emit( { authored: mod.formatAmount( 2500, 'EUR' ), switchedTo: mod.formatAmount( 2500, 'USD' ) } );
        JS);

        $this->assertSame('€25', $out['authored'], 'the preference reaches the currency it was resolved for');
        $this->assertSame(
            '$25.00',
            $out['switchedTo'],
            'and not a currency the donor switched to, whose minor units it says nothing about'
        );
    }

    /**
     * The form and the receipt for the same donation are rendered by different
     * code, and every currency an org can pick has to reach both of them under
     * the same name.
     *
     * The codes are the server's own table read by reflection, joined with the
     * whole admin currency picker, so a symbol added to one side alone fails
     * here whichever side it is added to.
     */
    public function test_the_form_and_the_receipt_name_currencies_the_same_way(): void
    {
        $symbols = (array) (new ReflectionClass(Money::class))->getConstant('SYMBOLS');
        $this->assertNotEmpty($symbols, 'the server table is what drives this test');

        $out = $this->runModule('util/format.js', str_replace(
            '__SERVER_CODES__',
            (string) json_encode(array_keys($symbols)),
            <<<'JS'
            const { CURRENCIES } = await import( '@dono/ui/utils/currency' );
            mod.setActiveNumberFormat(
                { decimalPlaces: 2, decimalSep: '.', thousandSep: ',', symbolPosition: 'before', symbol: '' },
                ''
            );
            const codes = new Set( [ ...__SERVER_CODES__, ...CURRENCIES.map( ( c ) => c.code ) ] );
            const out = {};
            // The symbol is whatever precedes the number.
            for ( const code of codes ) out[ code ] = mod.formatAmount( 100, code ).replace( /[\d.,]+$/, '' );
            emit( out );
        JS
        ));

        $this->assertGreaterThan(
            count($symbols),
            count($out),
            'the picker offers codes the server table has no symbol for, and those are the risky ones'
        );

        foreach ($symbols as $code => $symbol) {
            $this->assertArrayHasKey($code, $out, $code . ' has a server symbol the form never renders');
            $this->assertSame($symbol, $out[$code], $code . ' is named differently on the form');
        }

        foreach ($out as $code => $symbol) {
            $this->assertSame(Money::symbolFor($code), $symbol, $code . ' is named differently on the form');
        }
    }

    public function test_zero_decimal_currencies_still_render_without_cents(): void
    {
        $out = $this->runModule('util/format.js', <<<'JS'
            mod.setActiveNumberFormat(
                { decimalPlaces: 2, decimalSep: '.', thousandSep: ',', symbolPosition: 'before', symbol: '$' },
                'USD'
            );
            emit( { jpy: mod.formatAmount( 374900, 'JPY' ), gbp: mod.formatAmount( 1975, 'GBP' ) } );
        JS);

        $this->assertSame('¥3,749', $out['jpy']);
        $this->assertSame('£19.75', $out['gbp']);
    }
}
