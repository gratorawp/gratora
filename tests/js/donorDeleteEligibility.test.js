/**
 * Delete on a donor row is refused by the server for any donation row at all,
 * whatever its status. The stored counters on the row are paid money only, so
 * the screen cannot answer this itself: it has to carry the server's answer or
 * it offers an action that can only end in a refusal.
 */

import { render } from 'preact';

import apiFetch from '@wordpress/api-fetch';

import { DonorsApp } from '../../assets/admin/donors/index';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

const captured = { actions: null };

jest.mock( '@wordpress/dataviews', () => ( {
    DataViews: ( props ) => {
        captured.actions = props.actions;
        return null;
    },
} ) );

// Neither is reachable from the list, and both pull in @wordpress/components,
// which does not load under the preact alias.
jest.mock( '../../assets/admin/donors/DonorProfile', () => ( { __esModule: true, default: () => null } ) );
jest.mock( '../../assets/admin/donors/Insights', () => ( { __esModule: true, default: () => null } ) );

const settle = () => new Promise( ( r ) => setTimeout( r, 20 ) );

const rows = [
    // Left behind by an attempt that never completed: no paid donation, not a
    // test row, and the server keeps them anyway.
    { id: 1, name: 'Abandoned', email: 'a@example.test', donations_count: 0, is_test_only: false, deletable: false, redacted: false },
    { id: 2, name: 'Never gave', email: 'b@example.test', donations_count: 0, is_test_only: false, deletable: true, redacted: false },
];

beforeEach( () => {
    apiFetch.mockReset();
    apiFetch.mockImplementation( ( { path, parse } ) => {
        if ( parse === false ) {
            return Promise.resolve( { json: async () => rows, headers: { get: () => '2' } } );
        }
        if ( path.startsWith( '/dono/v1/admin/donors/stats' ) ) {
            return Promise.resolve( null );
        }
        return Promise.resolve( {} );
    } );
} );

async function mountList() {
    document.body.innerHTML = '<div id="root"></div>';
    render( <DonorsApp />, document.getElementById( 'root' ) );
    await settle();
    expect( captured.actions ).toBeTruthy();
}

test( 'a donor the server keeps is not offered Delete', async () => {
    await mountList();

    const del = captured.actions.find( ( a ) => a.id === 'delete' );

    expect( del.isEligible( rows[ 0 ] ) ).toBe( false );
    expect( del.isEligible( rows[ 1 ] ) ).toBe( true );
} );
