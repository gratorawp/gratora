/**
 * A fund is switched off when a restricted appeal closes, and appeals close in
 * groups. Every action on this table took `[ item ]`, so a selection of twelve
 * deactivated one and left eleven collecting.
 *
 * Delete stays single on purpose: each fund needs its own answer about where
 * the donations already recorded against it should go.
 */

import { render } from 'preact';
import apiFetch from '@wordpress/api-fetch';

import List from '../../assets/admin/funds/List';

const { waitFor, settle } = require( './support/waitFor' );

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

const fund = ( id, over = {} ) => ( {
    id,
    name: `Fund ${ id }`,
    is_default: false,
    is_active: true,
    reassign_pending: false,
    ...over,
} );

const FUNDS = [ fund( 1 ), fund( 2 ), fund( 3 ) ];

let posted = [];

function mount( rows = FUNDS ) {
    posted = [];
    apiFetch.mockImplementation( ( { path, method, data, parse } ) => {
        if ( method === 'POST' && /funds\/\d+$/.test( path ) ) {
            posted.push( { path, data } );
            return Promise.resolve( {} );
        }
        if ( parse === false ) {
            return Promise.resolve( { json: async () => rows, headers: { get: () => String( rows.length ) } } );
        }
        return Promise.resolve( rows );
    } );

    const root = document.createElement( 'div' );
    document.body.appendChild( root );
    render( <List />, root );
    return root;
}

beforeEach( () => {
    captured.actions = null;
    apiFetch.mockReset();
    document.body.innerHTML = '';
    window.gratora = { can: { manage_funds: true, manage_options: true } };
} );

const byId = ( id ) => captured.actions.find( ( a ) => a.id === id );

it( 'deactivates every fund chosen', async () => {
    mount();
    await waitFor( () => !! captured.actions );

    expect( byId( 'deactivate' ).supportsBulk ).toBe( true );

    await byId( 'deactivate' ).callback( FUNDS );
    await settle();

    expect( posted.map( ( p ) => p.path ) ).toEqual( [
        '/gratora/v1/admin/funds/1',
        '/gratora/v1/admin/funds/2',
        '/gratora/v1/admin/funds/3',
    ] );
    expect( posted.every( ( p ) => p.data.is_active === false ) ).toBe( true );
} );

/**
 * DataViews hands a bulk callback the whole selection and uses isEligible only
 * to decide whether the button is drawn, so the default fund arrives here and
 * would be switched off, which the server refuses and every donation with no
 * fund chosen depends on.
 */
it( 'leaves the default fund out of a deactivation', async () => {
    const rows = [ fund( 1 ), fund( 2, { is_default: true } ) ];
    mount( rows );
    await waitFor( () => !! captured.actions );

    await byId( 'deactivate' ).callback( rows );
    await settle();

    expect( posted.map( ( p ) => p.path ) ).toEqual( [ '/gratora/v1/admin/funds/1' ] );
} );

it( 'activates every fund chosen', async () => {
    const rows = [ fund( 4, { is_active: false } ), fund( 5, { is_active: false } ) ];
    mount( rows );
    await waitFor( () => !! captured.actions );

    expect( byId( 'activate' ).supportsBulk ).toBe( true );

    await byId( 'activate' ).callback( rows );
    await settle();

    expect( posted.every( ( p ) => p.data.is_active === true ) ).toBe( true );
    expect( posted ).toHaveLength( 2 );
} );

it( 'keeps delete to one fund at a time', async () => {
    mount();
    await waitFor( () => !! captured.actions );

    expect( !! byId( 'delete' ).supportsBulk ).toBe( false );
} );
