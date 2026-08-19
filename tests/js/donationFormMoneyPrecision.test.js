/**
 * The public form has no window.dono: its number format is seeded from the
 * server config for the currency the form was authored in. That preference is
 * about how whole amounts in that currency look, and the form is where the
 * donor agrees to a figure, so it may neither hide minor units that are being
 * charged nor invent ones the currency does not have.
 *
 * Its own file: setActiveNumberFormat installs a process-wide override in
 * @dono/ui, which the host-surface formatter reads too.
 */

import { setActiveNumberFormat, formatAmount } from '../../assets/donation-form/util/format';

function authoredIn( currency, decimalPlaces, symbol ) {
    setActiveNumberFormat( {
        decimalPlaces,
        decimalSep:     '.',
        thousandSep:    ',',
        symbolPosition: 'before',
        symbol,
    }, currency );
}

test( 'a form authored in yen prints no hundredths of a yen', () => {
    authoredIn( 'JPY', 2, '¥' );

    expect( formatAmount( 100000, 'JPY' ) ).toBe( '¥1,000' );
    expect( formatAmount( 5000, 'JPY' ) ).toBe( '¥50' );
} );

test( 'a yen figure with a remainder rounds, the way the receipt for it does', () => {
    authoredIn( 'JPY', 2, '¥' );

    expect( formatAmount( 123456, 'JPY' ) ).toBe( '¥1,235' );
} );

test( 'an org that asked for no cents still shows the cents it charges', () => {
    authoredIn( 'USD', 0, '$' );

    expect( formatAmount( 2550, 'USD' ) ).toBe( '$25.50' );
    expect( formatAmount( 2500, 'USD' ) ).toBe( '$25' );
} );

test( 'a currency the donor switched to keeps its own places', () => {
    authoredIn( 'USD', 0, '$' );

    expect( formatAmount( 2550, 'EUR' ) ).toBe( '€25.50' );
    expect( formatAmount( 100000, 'JPY' ) ).toBe( '¥1,000' );
} );
