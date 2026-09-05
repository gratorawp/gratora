/**
 * The delete dialog is the only place a refused delete can be read: the page
 * behind it is covered by the dialog's own scrim. A fund with sub-funds can
 * only ever be deactivated, so what it offers has to match what the server
 * will do.
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

const captured = { actions: null, fields: null };

jest.mock( '@wordpress/dataviews', () => ( {
    DataViews: ( props ) => {
        captured.actions = props.actions;
        captured.fields  = props.fields;
        return null;
    },
} ) );

const PARENT = {
    id: 1,
    code: 'programs',
    name: 'Programs',
    is_active: true,
    is_default: false,
    is_restricted: false,
    raised_cents: 0,
    goal_cents: null,
    deletable: false,
    has_children: true,
    reassign_pending: false,
};

const OTHER = { ...PARENT, id: 2, code: 'general', name: 'General', has_children: false };

let listPaths = [];
let deleteError = null;

function mount() {
    listPaths = [];
    apiFetch.mockImplementation( ( { path, method, parse } ) => {
        if ( method === 'DELETE' ) {
            return deleteError ? Promise.reject( deleteError ) : Promise.resolve( { action: 'deactivated' } );
        }
        if ( path.startsWith( '/fundkit/v1/admin/me/table-view' ) ) {
            return Promise.resolve( { sort: { field: 'type', direction: 'asc' } } );
        }
        if ( path.startsWith( '/fundkit/v1/admin/funds/stats' ) ) {
            return Promise.resolve( {} );
        }
        if ( parse === false ) {
            listPaths.push( path );
            return Promise.resolve( {
                json: async () => [ PARENT, OTHER ],
                headers: { get: () => '2' },
            } );
        }
        return Promise.resolve( [ PARENT, OTHER ] );
    } );

    const root = document.createElement( 'div' );
    document.body.appendChild( root );
    render( <List />, root );
    return root;
}

beforeEach( () => {
    captured.actions = null;
    captured.fields  = null;
    deleteError = null;
    apiFetch.mockReset();
    document.body.innerHTML = '';
} );

const settle = async () => {
    await new Promise( ( resolve ) => setTimeout( resolve, 40 ) );
};

async function openDeleteDialog() {
    mount();
    await waitFor( () => !! captured.actions );
    await settle();

    captured.actions.find( ( a ) => a.id === 'delete' ).callback( [ PARENT ] );
    await settle();

    const dialog = document.querySelector( '.fundkit-dialog' );
    expect( dialog ).not.toBeNull();
    return dialog;
}

it( 'shows a refused delete inside the dialog, not behind it', async () => {
    deleteError = { message: 'Reassign or remove the sub-funds first.' };

    const dialog = await openDeleteDialog();

    const confirm = [ ...dialog.querySelectorAll( '.fundkit-dialog__foot button' ) ].pop();
    confirm.click();
    await settle();

    const stillOpen = document.querySelector( '.fundkit-dialog' );
    expect( stillOpen ).not.toBeNull();
    expect( stillOpen.textContent ).toContain( 'Reassign or remove the sub-funds first.' );
} );

it( 'does not offer to reassign a fund that has sub-funds', async () => {
    const dialog = await openDeleteDialog();

    expect( dialog.querySelector( '#fundkit-fund-delete-reassign' ) ).toBeNull();
    expect( dialog.textContent ).toContain( 'sub-funds' );
} );

it( 'sorts the Type column by the column the server knows', async () => {
    mount();
    await waitFor( () => listPaths.length > 0 );
    await settle();

    expect( listPaths[ listPaths.length - 1 ] ).toContain( 'orderby=is_restricted' );
} );

it( 'does not offer a sort on a status the query cannot reach', async () => {
    mount();
    await waitFor( () => !! captured.fields );

    const status = captured.fields.find( ( f ) => f.id === 'status' );
    expect( status.enableSorting ).toBe( false );
} );
