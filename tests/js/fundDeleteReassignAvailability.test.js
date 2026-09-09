/**
 * "There is no other active fund to reassign to" is stated as fact. On a slow
 * connection, or after the picker's own request failed, it was simply wrong: an
 * admin believed it and deactivated a fund they could have reassigned, and a
 * failed request said nothing at all.
 */

import { render } from 'preact';
import apiFetch from '@wordpress/api-fetch';

import List from '../../assets/admin/funds/List';

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

const captured = { actions: null };

jest.mock( '@wordpress/dataviews', () => ( {
    DataViews: ( props ) => {
        captured.actions = props.actions;
        return null;
    },
} ) );

/** Referenced and childless: the row whose dialog offers reassign-or-deactivate. */
const TARGET = {
    id: 1, code: 'programs', name: 'Programs',
    is_active: true, is_default: false, is_restricted: false,
    raised_cents: 0, goal_cents: null,
    deletable: false, has_children: false, reassign_pending: false,
};

const settle = async () => {
    await new Promise( ( resolve ) => setTimeout( resolve, 40 ) );
};

/** @param pickerList how the picker's own paged request settles */
async function openDialog( pickerList ) {
    apiFetch.mockImplementation( ( { path, method, parse } ) => {
        if ( method === 'DELETE' ) return Promise.resolve( { action: 'deactivated' } );
        if ( path.startsWith( '/gratora/v1/admin/me/table-view' ) ) return Promise.resolve( {} );
        if ( path.startsWith( '/gratora/v1/admin/funds/stats' ) ) return Promise.resolve( {} );
        if ( parse === false ) {
            return Promise.resolve( { json: async () => [ TARGET ], headers: { get: () => '1' } } );
        }

        return pickerList();
    } );

    const root = document.createElement( 'div' );
    document.body.appendChild( root );
    render( <List />, root );

    await waitFor( () => !! captured.actions );
    await settle();

    captured.actions.find( ( a ) => a.id === 'delete' ).callback( [ TARGET ] );
    await settle();

    return document.querySelector( '.gratora-dialog' );
}

beforeEach( () => {
    captured.actions = null;
    apiFetch.mockReset();
    document.body.innerHTML = '';
} );

it( 'does not claim there is no other fund while the list is still loading', async () => {
    const dialog = await openDialog( () => new Promise( () => {} ) );

    expect( dialog.textContent ).not.toContain( 'no other active fund' );
    expect( dialog.textContent ).toContain( 'Still loading' );
} );

it( 'says the list could not be loaded rather than that none exist', async () => {
    const dialog = await openDialog( () => Promise.reject( new Error( 'network' ) ) );

    expect( dialog.textContent ).not.toContain( 'no other active fund' );
    expect( dialog.textContent ).toContain( 'could not be loaded' );
    expect( dialog.textContent ).toContain( 'Try again' );
} );

/** And when the list really is empty, the original sentence is the true one. */
it( 'still says so when the org genuinely has no other fund', async () => {
    const dialog = await openDialog( () => Promise.resolve( [ TARGET ] ) );

    expect( dialog.textContent ).toContain( 'no other active fund' );
} );
