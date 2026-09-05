/**
 * The Currency panel draws its rate table only when there is more than one
 * currency to convert between, so a swallowed rate-load failure removed the
 * card entirely: the screen then read as a site with one currency, which is a
 * different fact about the org than "the rates could not be loaded".
 */

import { render } from 'preact';
import apiFetch from '@wordpress/api-fetch';

import { useFxRates } from '../../assets/admin/_shared/useFxRates';
import { SettingsGroup } from '../../assets/admin/settings/Settings';

const { waitFor } = require( './support/waitFor' );

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

jest.mock( '../../assets/admin/_shared/notify', () => ( {
    __esModule: true,
    notify:  { success: jest.fn(), error: jest.fn(), info: jest.fn() },
    default: { success: jest.fn(), error: jest.fn(), info: jest.fn() },
} ) );

function Host() {
    const fx = useFxRates();
    return (
        <SettingsGroup of={ fx }>
            <p>exchange rates</p>
        </SettingsGroup>
    );
}

let root = null;

function mount() {
    if ( root ) render( null, root );
    document.body.innerHTML = '<div id="root"></div>';
    root = document.getElementById( 'root' );
    render( <Host />, root );
}

beforeEach( () => {
    apiFetch.mockReset();
    document.body.innerHTML = '';
} );

it( 'says the rates could not be loaded instead of leaving the card out', async () => {
    apiFetch.mockImplementation( () => Promise.reject( new Error( 'rates unavailable' ) ) );

    mount();
    await waitFor( () => document.body.textContent.includes( 'rates unavailable' ) );

    expect( document.body.textContent ).not.toContain( 'exchange rates' );
    expect( [ ...document.querySelectorAll( 'button' ) ].map( ( b ) => b.textContent.trim() ) )
        .toContain( 'Retry' );
} );

it( 'asks again when told to', async () => {
    let fail = true;
    apiFetch.mockImplementation( () => (
        fail
            ? Promise.reject( new Error( 'rates unavailable' ) )
            : Promise.resolve( { base: 'EUR', rows: [], auto: true } )
    ) );

    mount();
    await waitFor( () => document.body.textContent.includes( 'rates unavailable' ) );

    fail = false;
    [ ...document.querySelectorAll( 'button' ) ]
        .find( ( b ) => b.textContent.trim() === 'Retry' )
        .click();

    await waitFor( () => document.body.textContent.includes( 'exchange rates' ) );
    expect( apiFetch ).toHaveBeenCalledTimes( 2 );
} );

it( 'shows the panel when the rates load', async () => {
    apiFetch.mockImplementation( () => Promise.resolve( { base: 'EUR', rows: [], auto: true } ) );

    mount();
    await waitFor( () => document.body.textContent.includes( 'exchange rates' ) );

    expect( document.body.textContent ).not.toContain( 'Retry' );
} );
