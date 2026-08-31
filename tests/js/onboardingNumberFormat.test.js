/**
 * The wizard derives digit separators from the country the operator picks, and
 * that code could never fire.
 *
 * It tested for an EMPTY stored value, and there is no such thing: the server
 * merges its currency-locale defaults into every settings read, so decimal_sep
 * always arrived as '.'. Every German, French, Dutch, Spanish, Italian and
 * Nordic install therefore finished onboarding with en-US separators and
 * printed 1,234.56 on its donation form, its receipts, its tax statements and
 * every admin screen, with nothing saying so and four fields to hand-fix under
 * Settings > Currency. It lands at N=0, before the first donation.
 */

const { chosenFormat, formatForCurrency } = require( '../../assets/admin/onboarding/Onboarding' );

// What SettingsService merges in when nobody has chosen anything.
const asServerReturnsIt = ( overrides = {} ) => ( {
    format: { decimal_sep: '.', thousand_sep: ',', symbol_position: 'before', ...overrides },
} );

describe( 'the currency the operator picked decides the separators', () => {
    test( 'an EU install gets EU separators, not the shipped defaults', () => {
        const currency = asServerReturnsIt();

        expect( chosenFormat( currency, 'decimal_sep', ',' ) ).toBe( ',' );
        expect( chosenFormat( currency, 'thousand_sep', '.' ) ).toBe( '.' );
        expect( chosenFormat( currency, 'symbol_position', 'after' ) ).toBe( 'after' );
    } );

    test( 'a US install still gets US separators', () => {
        const currency = asServerReturnsIt();

        expect( chosenFormat( currency, 'decimal_sep', '.' ) ).toBe( '.' );
        expect( chosenFormat( currency, 'thousand_sep', ',' ) ).toBe( ',' );
    } );
} );

describe( 'a value the operator actually chose is kept', () => {
    test( 'a separator they set in Settings survives the wizard', () => {
        // A French charity that already set a space for thousands.
        const currency = asServerReturnsIt( { thousand_sep: ' ', decimal_sep: ',' } );

        expect( chosenFormat( currency, 'thousand_sep', '.' ) ).toBe( ' ' );
        expect( chosenFormat( currency, 'decimal_sep', '.' ) ).toBe( ',' );
    } );

    test( 'a symbol position they chose survives too', () => {
        const currency = asServerReturnsIt( { symbol_position: 'after' } );

        expect( chosenFormat( currency, 'symbol_position', 'before' ) ).toBe( 'after' );
    } );

    test( 'and a missing group falls back to the derived value', () => {
        expect( chosenFormat( undefined, 'decimal_sep', ',' ) ).toBe( ',' );
        expect( chosenFormat( {}, 'thousand_sep', '.' ) ).toBe( '.' );
    } );
} );


/**
 * The wizard derived this from the country, on a hardcoded list of nations that
 * write money the American way. An organisation outside that list which chose
 * USD was written 1.234,56 $ regardless, because its country decided the format
 * and its currency was never consulted.
 */
describe( 'the format follows the currency, not the country', () => {
    const PRESETS = {
        USD: { decimal_places: 2, decimal_sep: '.', thousand_sep: ',', symbol_position: 'before' },
        EUR: { decimal_places: 2, decimal_sep: ',', thousand_sep: '.', symbol_position: 'after' },
        JPY: { decimal_places: 0, decimal_sep: '.', thousand_sep: ',', symbol_position: 'before' },
    };

    beforeEach( () => {
        global.window = { fundkit: { currency_formats: PRESETS } };
    } );

    afterEach( () => {
        delete global.window;
    } );

    test( 'choosing dollars writes dollars, wherever the organisation is', () => {
        expect( formatForCurrency( 'USD' ) ).toEqual( {
            decimal: '.', thousand: ',', symbolPosition: 'before', places: 2,
        } );
    } );

    test( 'choosing euros writes euros', () => {
        expect( formatForCurrency( 'EUR' ) ).toEqual( {
            decimal: ',', thousand: '.', symbolPosition: 'after', places: 2,
        } );
    } );

    test( 'a currency with no minor unit asks for no decimal places', () => {
        expect( formatForCurrency( 'JPY' ).places ).toBe( 0 );
    } );

    test( 'the lookup does not care about case or stray space', () => {
        expect( formatForCurrency( ' eur ' ) ).toEqual( formatForCurrency( 'EUR' ) );
    } );

    test( 'a currency with no preset falls back rather than guessing', () => {
        expect( formatForCurrency( 'XYZ' ) ).toEqual( {
            decimal: '.', thousand: ',', symbolPosition: 'before', places: 2,
        } );
    } );

    test( 'and so does a build with no presets on the page', () => {
        global.window = {};
        expect( formatForCurrency( 'EUR' ).symbolPosition ).toBe( 'before' );
    } );
} );

describe( 'zero decimal places is a choice, not an absence', () => {
    test( 'a yen org that set none keeps none', () => {
        // Falsy, so the previous truthiness test read this as unset and
        // replaced it with the derived value.
        const currency = { format: { decimal_places: 0 } };

        expect( chosenFormat( currency, 'decimal_places', 2 ) ).toBe( 0 );
    } );

    test( 'and the shipped 2 still defers to the derived value', () => {
        const currency = { format: { decimal_places: 2 } };

        expect( chosenFormat( currency, 'decimal_places', 0 ) ).toBe( 0 );
    } );
} );
