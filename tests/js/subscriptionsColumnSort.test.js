/**
 * The Amount and Lifetime headers on the subscriptions list carry a sort arrow.
 * The server sorts on column names and falls back to next_payment_at without
 * saying so, so a header whose field id is not a column turns the arrow over
 * rows that never moved.
 */

import { render } from 'preact';

import apiFetch from '@wordpress/api-fetch';

import List from '../../assets/admin/subscriptions/List';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

jest.mock( '../../assets/admin/_shared/notify', () => ( {
    __esModule: true,
    default: { success: jest.fn(), error: jest.fn(), info: jest.fn() },
} ) );

const captured = { view: null, fields: null, onChangeView: null };

jest.mock( '@wordpress/dataviews', () => ( {
    DataViews: ( props ) => {
        captured.view         = props.view;
        captured.fields       = props.fields;
        captured.onChangeView = props.onChangeView;
        return null;
    },
} ) );

const { settle } = require( './support/waitFor' );

const plans = [
    {
        id: 1, gateway: 'stripe', amount_cents: 1000, currency: 'USD',
        interval_unit: 'month', interval_count: 1, status: 'active',
        payments_count: 2, total_paid_cents: 2000, failed_renewals_count: 0,
        next_payment_at: '2026-09-01 00:00:00',
        donor: { id: 9, name: 'A' }, campaign: null,
    },
];

const listPaths = [];

function seedApi() {
    apiFetch.mockImplementation( ( { path, parse } ) => {
        if ( parse === false ) {
            listPaths.push( path );
            return Promise.resolve( {
                json:    async () => plans,
                headers: { get: () => '0' },
            } );
        }
        if ( path.startsWith( '/giveflow/v1/admin/recurring/unlinked' ) ) {
            return Promise.resolve( { total: 0, items: [], window_days: 7, can_retry: false } );
        }
        if ( path.startsWith( '/giveflow/v1/admin/recurring/stats' ) ) {
            return Promise.resolve( null );
        }
        return Promise.resolve( {} );
    } );
}

async function mountList() {
    listPaths.length = 0;
    document.body.innerHTML = '<div id="root"></div>';
    render( <List />, document.getElementById( 'root' ) );
    await settle();
    expect( captured.onChangeView ).toBeTruthy();
}

/**
 * Clicks a column header: DataViews reports the new sort back through the view.
 *
 * Waits for the request the change causes rather than for a fixed moment: a
 * sleep long enough on an idle machine is not long enough under parallel
 * workers, and the test then reads the request from before the change.
 */
async function sortBy( field ) {
    const before = listPaths.length;
    captured.onChangeView( { ...captured.view, page: 1, sort: { field, direction: 'desc' } } );

    for ( let i = 0; i < 100 && listPaths.length === before; i++ ) {
        await new Promise( ( r ) => setTimeout( r, 10 ) );
    }

    expect( listPaths.length ).toBeGreaterThan( before );

    return listPaths[ listPaths.length - 1 ];
}

beforeEach( () => {
    apiFetch.mockReset();
    seedApi();
} );

test( 'sorting by Amount asks the server for the amount column', async () => {
    await mountList();

    expect( captured.fields.find( ( f ) => f.id === 'amount' ).enableSorting ).toBe( true );
    expect( await sortBy( 'amount' ) ).toContain( 'orderby=amount_cents' );
} );

test( 'sorting by Lifetime asks the server for the lifetime total column', async () => {
    await mountList();

    expect( captured.fields.find( ( f ) => f.id === 'lifetime' ).enableSorting ).toBe( true );
    expect( await sortBy( 'lifetime' ) ).toContain( 'orderby=total_paid_cents' );
} );

test( 'a column that is already a column is asked for unchanged', async () => {
    await mountList();

    expect( await sortBy( 'started_at' ) ).toContain( 'orderby=started_at' );
} );
