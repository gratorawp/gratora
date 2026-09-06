/**
 * Recalculate reported the count of currencies it could NOT convert as a
 * success line, "Still_unconvertible: 2 synced", about rows that are still
 * missing from every total. The other lines leaked raw keys.
 */

import { render } from 'preact';
import apiFetch from '@wordpress/api-fetch';

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

const MaintenanceTab = require( '../../assets/admin/tools/tabs/MaintenanceTab' ).default;

let root = null;

async function recalculate( response ) {
    apiFetch.mockImplementation( () => Promise.resolve( response ) );

    if ( root ) render( null, root );
    document.body.innerHTML = '<div id="root"></div>';
    root = document.getElementById( 'root' );

    render(
        <MaintenanceTab
            info={ { recalc_scopes: [ { value: 'all', label: 'Everything' } ] } }
            infoError={ false }
            active={ false }
            loadInfo={ () => {} }
            setNotice={ () => {} }
        />,
        root
    );

    [ ...document.querySelectorAll( 'button' ) ]
        .find( ( b ) => b.textContent.trim() === 'Recalculate' )
        .click();

    await waitFor( () => document.body.textContent.includes( 'synced' )
        || document.body.textContent.includes( 'missing from your totals' ) );
}

beforeEach( () => {
    apiFetch.mockReset();
    document.body.innerHTML = '';
} );

it( 'does not call an unconvertible donation synced', async () => {
    await recalculate( {
        ok: true, scope: 'all', done: true,
        counts: { donors: 3 },
        still_unconvertible: 2,
        unconvertible_currencies: [ 'JPY', 'NOK' ],
    } );

    const text = document.body.textContent;
    expect( text ).not.toContain( 'Still_unconvertible' );
    expect( text ).not.toContain( 'still_unconvertible' );
    expect( text ).toContain( 'missing from your totals' );
    expect( text ).toContain( 'JPY, NOK' );
} );

it( 'names what it recomputed instead of printing the key', async () => {
    await recalculate( {
        ok: true, scope: 'all', done: true,
        counts: { converted_donations: 12 },
        still_unconvertible: 0,
        unconvertible_currencies: [],
    } );

    const text = document.body.textContent;
    expect( text ).not.toContain( 'Converted_donations' );
    expect( text ).toContain( 'base currency' );
    expect( text ).toContain( '12 synced' );
} );

it( 'says nothing about currencies when everything converted', async () => {
    await recalculate( {
        ok: true, scope: 'all', done: true,
        counts: { donors: 1 },
        still_unconvertible: 0,
        unconvertible_currencies: [],
    } );

    expect( document.body.textContent ).not.toContain( 'missing from your totals' );
    expect( document.body.textContent ).toContain( 'Donors: 1 synced' );
} );
