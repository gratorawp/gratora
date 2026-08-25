/**
 * The wizard persisted a supported-currency list the operator never chose.
 *
 * Same trap as the separators: it tested the stored list for emptiness, and the
 * server merges [ 'USD' ] into every currency-locale read, so the fallback only
 * ran when the hydrating GET had failed. A German charity that picks Germany
 * moves its base to EUR and finishes onboarding accepting USD, which makes a
 * single-currency install look multi-currency to the rate fetcher and starts a
 * daily third-party call it has no use for. USD is then unremovable from
 * Settings > Currency until a third currency is enabled first, because removing
 * the only entry is refused and the base chip is inert.
 */

const { chosenCurrencies } = require( '../../assets/admin/onboarding/Onboarding' );

// What SettingsService merges in when nobody has chosen anything.
const asServerReturnsIt = ( overrides = {} ) => ( {
    default_currency:     'USD',
    supported_currencies: [ 'USD' ],
    ...overrides,
} );

describe( 'the base currency the operator picked decides what is accepted', () => {
    test( 'an EU install accepts its own currency, not the shipped one', () => {
        expect( chosenCurrencies( asServerReturnsIt( { default_currency: 'EUR' } ) ) ).toEqual( [ 'EUR' ] );
    } );

    test( 'a US install is unchanged', () => {
        expect( chosenCurrencies( asServerReturnsIt() ) ).toEqual( [ 'USD' ] );
    } );
} );

describe( 'a list the operator actually chose is kept', () => {
    test( 'a real multi-currency set survives the wizard', () => {
        const currency = asServerReturnsIt( {
            default_currency:     'GBP',
            supported_currencies: [ 'GBP', 'EUR' ],
        } );

        expect( chosenCurrencies( currency ) ).toEqual( [ 'GBP', 'EUR' ] );
    } );

    test( 'USD kept alongside another currency is a choice, so it stays', () => {
        const currency = asServerReturnsIt( {
            default_currency:     'EUR',
            supported_currencies: [ 'USD', 'EUR' ],
        } );

        expect( chosenCurrencies( currency ) ).toEqual( [ 'USD', 'EUR' ] );
    } );

    test( 'a resumed wizard whose operator changes country keeps its base in the set', () => {
        const currency = asServerReturnsIt( {
            default_currency:     'EUR',
            supported_currencies: [ 'USD', 'GBP' ],
        } );

        expect( chosenCurrencies( currency ) ).toEqual( [ 'EUR', 'USD', 'GBP' ] );
    } );
} );

describe( 'the state the wizard can actually be in', () => {
    test( 'a failed hydrating GET leaves no list, and the base still lands', () => {
        expect( chosenCurrencies( { default_currency: 'EUR' } ) ).toEqual( [ 'EUR' ] );
    } );

    test( 'nothing at all still produces an accepted currency', () => {
        expect( chosenCurrencies( undefined ) ).toEqual( [ 'USD' ] );
    } );

    test( 'a lowercase stored code is the same currency', () => {
        const currency = { default_currency: 'eur', supported_currencies: [ 'eur', 'gbp' ] };

        expect( chosenCurrencies( currency ) ).toEqual( [ 'EUR', 'GBP' ] );
    } );
} );
