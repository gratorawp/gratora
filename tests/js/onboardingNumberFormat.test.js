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

const { chosenFormat } = require( '../../assets/admin/onboarding/Onboarding' );

// What SettingsService merges in when nobody has chosen anything.
const asServerReturnsIt = ( overrides = {} ) => ( {
    format: { decimal_sep: '.', thousand_sep: ',', symbol_position: 'before', ...overrides },
} );

describe( 'a country the operator picked decides the separators', () => {
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
