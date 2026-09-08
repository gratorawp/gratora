/**
 * Every other screen's KPI builder emits "-" placeholders when stats are null,
 * so the strip stays labelled. This one returned [], so a failed fetch deleted
 * MRR, active count, needs-attention and churn with nothing on screen saying
 * anything was missing; aria-busy stayed on forever, and the only notice was a
 * toast that dismissed itself after seven seconds.
 */

import { render } from 'preact';
import apiFetch from '@wordpress/api-fetch';

import List from '../../assets/admin/subscriptions/List';

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
jest.mock( '@wordpress/dataviews', () => ( { DataViews: () => null } ) );

const settle = async () => {
    await new Promise( ( resolve ) => setTimeout( resolve, 60 ) );
};

async function mount( statsResult ) {
    apiFetch.mockImplementation( ( { path, parse } ) => {
        if ( path.startsWith( '/fundkit/v1/admin/recurring/stats' ) ) return statsResult();
        if ( path.startsWith( '/fundkit/v1/admin/me/table-view' ) ) return Promise.resolve( {} );
        if ( parse === false ) {
            return Promise.resolve( { json: async () => [], headers: { get: () => '0' } } );
        }

        return Promise.resolve( [] );
    } );

    const root = document.createElement( 'div' );
    document.body.appendChild( root );
    render( <List />, root );
    await settle();

    return document.body;
}

beforeEach( () => {
    apiFetch.mockReset();
    document.body.innerHTML = '';
} );

it( 'says the totals are missing rather than showing an empty strip', async () => {
    const host = await mount( () => Promise.reject( new Error( 'gateway timeout' ) ) );

    expect( host.textContent ).toContain( 'could not be loaded' );
    expect( host.textContent ).toContain( 'gateway timeout' );
} );

it( 'offers a way back without a page reload', async () => {
    const host = await mount( () => Promise.reject( new Error( 'gateway timeout' ) ) );

    const retry = [ ...host.querySelectorAll( 'button' ) ].find( ( b ) => /Try again/.test( b.textContent ) );

    expect( retry ).toBeTruthy();
} );

/** aria-busy stayed on forever, so a reader was told it was still loading. */
it( 'does not leave the strip claiming to be busy', async () => {
    const host = await mount( () => Promise.reject( new Error( 'gateway timeout' ) ) );

    host.querySelectorAll( '[aria-busy="true"]' ).forEach( ( el ) => {
        expect( el.getAttribute( 'aria-busy' ) ).not.toBe( 'true' );
    } );
} );

it( 'shows the strip when the totals do load', async () => {
    const host = await mount( () => Promise.resolve( { mrr_cents: 1000, active: 2 } ) );

    expect( host.textContent ).not.toContain( 'could not be loaded' );
} );
