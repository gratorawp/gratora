/**
 * Three settings fields that told the admin something the rest of the product
 * does not agree with: a preview drawn from the picker's label table rather
 * than the map every renderer prints from, an exchange-rate card headed with a
 * base currency nobody has saved yet, and a VAT field that disappears the
 * moment the country changes, leaving the number printing on every receipt
 * with nothing on screen to clear it.
 */

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );
jest.mock( '@wordpress/api-fetch', () => jest.fn( () => new Promise( () => {} ) ) );

import { render } from 'preact';

import CurrencyPanel, { previewSymbol } from '../../assets/admin/settings/panels/CurrencyPanel';
import OrganizationPanel from '../../assets/admin/settings/panels/OrganizationPanel';
import { formatAmount, CURRENCY_SYMBOLS } from '../../assets/_shared/money';

function mount( node ) {
    document.body.innerHTML = '<div id="root"></div>';
    render( node, document.getElementById( 'root' ) );
}

afterEach( () => { document.body.innerHTML = ''; delete window.gratora; } );

describe( 'the currency preview', () => {
    it( 'writes money the way the renderers write it', () => {
        for ( const code of [ 'CHF', 'MXN', 'USD', 'EUR' ] ) {
            expect( previewSymbol( code ) ).toBe( CURRENCY_SYMBOLS[ code ] );
        }
    } );

    it( 'agrees with what a donor is shown', () => {
        window.gratora = { number_format: { decimalPlaces: 2, decimalSep: '.', thousandSep: "'", symbolPosition: 'before' } };

        expect( formatAmount( 123456, 'CHF' ) ).toContain( previewSymbol( 'CHF' ) );
    } );
} );

describe( 'the exchange rate card', () => {
    const s = {
        value:  ( k, f ) => ( k === 'default_currency' ? 'EUR' : f ),
        record: { base_currency_locked: false, supported_currencies: [ 'USD', 'EUR' ] },
        edit:   () => {},
        setValue: () => () => {},
        isDirty: true,
    };

    const fx = {
        base: 'USD',
        frame: 'USD',
        loading: false,
        fetching: false,
        auto: true,
        isDirty: false,
        rows: [
            { code: 'USD', is_base: true, rate: 1, auto_rate: 1, is_manual: false },
            { code: 'EUR', is_base: false, rate: 0.9, auto_rate: 0.9, is_manual: false },
        ],
        setRate: () => {},
        setAuto: () => {},
        save: () => {},
        refresh: () => {},
    };

    it( 'is headed with the base the numbers are actually in', () => {
        mount( <CurrencyPanel s={ s } fx={ fx } /> );

        const card = document.querySelector( '.gratora-fx' ) || document.body;

        expect( card.textContent ).toContain( '1 USD' );
        expect( card.textContent ).not.toContain( '1 EUR equals' );
    } );
} );

describe( 'the organisation panel', () => {
    it( 'still offers the VAT field after the org moves out of the EU', () => {
        const record = { country: 'GB', vat_id: 'DE123456789' };
        const s = {
            record,
            edit: () => {},
            isDirty: false,
            value: ( k, f = '' ) => record[ k ] ?? f,
            setValue: () => () => {},
            bind: ( k ) => ( { value: record[ k ] ?? '', onChange: () => {} } ),
        };

        mount( <OrganizationPanel s={ s } /> );

        const vat = document.querySelector( 'input[placeholder="EU VAT ID"]' );

        expect( vat ).not.toBeNull();
        expect( vat.value ).toBe( 'DE123456789' );
    } );
} );
