/**
 * A bulk redact or delete fired with Promise.all: the first rejection abandoned
 * the rest of the reporting, so a part-done batch showed nothing at all and the
 * admin could not tell which donors had been processed.
 */

import { render } from 'preact';
import apiFetch from '@wordpress/api-fetch';

const { waitFor } = require( './support/waitFor' );

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

const told = { success: [], error: [] };

jest.mock( '../../assets/admin/_shared/notify', () => ( {
    __esModule: true,
    notify:  { success: jest.fn(), error: jest.fn(), info: jest.fn() },
    default: {
        success: ( m ) => told.success.push( m ),
        error:   ( m ) => told.error.push( m ),
        info:    () => {},
    },
} ) );

const captured = { actions: null, confirm: null };

jest.mock( '@wordpress/dataviews', () => ( {
    DataViews: ( props ) => {
        captured.actions = props.actions;
        return null;
    },
} ) );

jest.mock( '../../assets/admin/_shared/components/ConfirmDialog', () => ( {
    __esModule: true,
    default: ( { confirm } ) => {
        if ( confirm ) captured.confirm = confirm;
        return null;
    },
} ) );

jest.mock( '../../assets/admin/donors/DonorProfile', () => ( { __esModule: true, default: () => null } ) );
jest.mock( '../../assets/admin/donors/Insights', () => ( { __esModule: true, default: () => null } ) );

const { DonorsApp } = require( '../../assets/admin/donors/index' );

const ROWS = [
    { id: 1, name: 'One', email: 'one@example.test', donations_count: 0, deletable: true, redacted: false },
    { id: 2, name: 'Two', email: 'two@example.test', donations_count: 0, deletable: true, redacted: false },
    { id: 3, name: 'Three', email: 'three@example.test', donations_count: 0, deletable: true, redacted: false },
];

let root = null;
let refuse = [];

async function run( actionId ) {
    told.success = [];
    told.error = [];
    captured.confirm = null;

    apiFetch.mockImplementation( ( { path, parse, method } ) => {
        if ( method && refuse.some( ( id ) => path.includes( `/donors/${ id }` ) ) ) {
            return Promise.reject( new Error( 'nope' ) );
        }
        if ( parse === false ) {
            return Promise.resolve( { json: async () => ROWS, headers: { get: () => '3' } } );
        }
        return Promise.resolve( {} );
    } );

    if ( root ) render( null, root );
    document.body.innerHTML = '<div id="root"></div>';
    root = document.getElementById( 'root' );
    render( <DonorsApp />, root );

    await waitFor( () => !! captured.actions );
    captured.actions.find( ( a ) => a.id === actionId ).callback( ROWS );
    await new Promise( ( r ) => setTimeout( r, 20 ) );

    expect( captured.confirm ).not.toBeNull();
    await captured.confirm.onConfirm();
    await new Promise( ( r ) => setTimeout( r, 20 ) );
}

beforeEach( () => {
    apiFetch.mockReset();
    document.body.innerHTML = '';
    refuse = [];
} );

it( 'says how many were deleted when some of them fail', async () => {
    refuse = [ 2 ];

    await run( 'delete' );

    expect( told.success.join( ' ' ) ).toContain( '2 donors deleted' );
    expect( told.error.join( ' ' ) ).toContain( '1 donor could not be deleted' );
} );

it( 'says so when every one of them fails', async () => {
    refuse = [ 1, 2, 3 ];

    await run( 'delete' );

    expect( told.success ).toEqual( [] );
    expect( told.error.join( ' ' ) ).toContain( '3 donors could not be deleted' );
} );

it( 'reports a redact batch the same way', async () => {
    refuse = [ 3 ];

    await run( 'redact' );

    expect( told.success.join( ' ' ) ).toContain( '2 donors redacted' );
    expect( told.error.join( ' ' ) ).toContain( '1 donor could not be redacted' );
} );

it( 'says nothing about failures when there are none', async () => {
    await run( 'delete' );

    expect( told.error ).toEqual( [] );
    expect( told.success.join( ' ' ) ).toContain( '3 donors deleted' );
} );
