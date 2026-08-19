/**
 * The donor reads one donation twice: once in the portal, once on the receipt
 * PHP renders. The org's "decimal places" preference is a display choice about
 * whole amounts in its own currency, so applying it any wider restates the
 * amount, and the two readings of the same money stop agreeing.
 *
 * The expectations here are the ones tests/Unit/Foundation/MoneyDisplayPrecisionTest
 * and MoneyZeroDecimalBaseTest assert against Money::format.
 */

import { formatAmount, formatAmountCompact } from '../../assets/_shared/money';

function org( currency, decimalPlaces, symbol ) {
    window.dono = {
        default_currency: currency,
        number_format: {
            decimalPlaces,
            decimalSep:     '.',
            thousandSep:    ',',
            symbolPosition: 'before',
            symbol,
        },
    };
}

afterEach( () => { delete window.dono; } );

describe( 'an org that asked for no cents', () => {
    beforeEach( () => org( 'USD', 0, '$' ) );

    test( 'cents that were charged are never rounded away', () => {
        expect( formatAmount( 2550, 'USD' ) ).toBe( '$25.50' );
        expect( formatAmount( 2654, 'USD' ) ).toBe( '$26.54' );
        expect( formatAmount( 1023487, 'USD' ) ).toBe( '$10,234.87' );
    } );

    test( 'cents there are none of are still dropped', () => {
        expect( formatAmount( 2500, 'USD' ) ).toBe( '$25' );
    } );

    test( 'a currency the org merely accepts keeps its own places', () => {
        expect( formatAmount( 2654, 'EUR' ) ).toBe( '€26.54' );
        expect( formatAmount( 2500, 'EUR' ) ).toBe( '€25.00' );
    } );
} );

describe( 'an org based in a currency with no minor unit', () => {
    beforeEach( () => org( 'JPY', 2, '¥' ) );

    test( 'the base currency never gains places it does not have', () => {
        expect( formatAmount( 100000, 'JPY' ) ).toBe( '¥1,000' );
        expect( formatAmount( 5000, 'JPY' ) ).toBe( '¥50' );
    } );

    test( 'every yen figure on the screen carries the same precision', () => {
        expect( formatAmount( 123456, 'JPY' ) ).toBe( '¥1,235' );
    } );

    test( 'a two-decimal currency it accepts is unaffected', () => {
        expect( formatAmount( 2654, 'USD' ) ).toBe( '$26.54' );
    } );
} );

describe( 'the compact form', () => {
    beforeEach( () => org( 'USD', 2, '$' ) );

    test( 'drops decimals on a whole amount and keeps them otherwise', () => {
        expect( formatAmountCompact( 2500, 'USD' ) ).toBe( '$25' );
        expect( formatAmountCompact( 2550, 'USD' ) ).toBe( '$25.50' );
    } );

    test( 'a negative amount reads as one', () => {
        expect( formatAmount( -2550, 'USD' ) ).toBe( '$-25.50' );
    } );
} );
