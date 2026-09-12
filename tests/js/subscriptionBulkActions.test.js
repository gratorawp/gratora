/**
 * A table of subscriptions with no bulk action on it.
 *
 * Every plan action took `items[0]` and opened a dialog about that one plan, so
 * selecting thirty and choosing Cancel acted on one of them and silently left
 * the rest running. A campaign that ends, a gateway being retired, a sponsor
 * pulling out: all of them are the same job done thirty times by hand.
 *
 * Change amount is deliberately not among them. One amount across a selection
 * is almost never the amount any of those donors agreed to.
 */

import { render } from 'preact';
import apiFetch from '@wordpress/api-fetch';

import List from '../../assets/admin/subscriptions/List';

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

const plan = ( id ) => ( {
    id,
    status: 'active',
    can_retry: false,
    amount_cents: 2500,
    currency: 'EUR',
    interval_unit: 'month',
    interval_count: 1,
    donor: { name: `Donor ${ id }` },
} );

const PLANS = [ plan( 1 ), plan( 2 ), plan( 3 ) ];

let posted = [];

function mount() {
    posted = [];
    apiFetch.mockImplementation( ( { path, parse, method, data } ) => {
        if ( path.startsWith( '/gratora/v1/admin/me/table-view' ) ) return Promise.resolve( {} );
        if ( parse === false ) {
            return Promise.resolve( { json: async () => PLANS, headers: { get: () => '3' } } );
        }
        if ( method === 'POST' && /recurring\/\d+\/action/.test( path ) ) {
            posted.push( { path, data } );
            return Promise.resolve( {} );
        }
        return Promise.resolve( { items: [], total: 0 } );
    } );

    const root = document.createElement( 'div' );
    document.body.appendChild( root );
    render( <List />, root );
    return root;
}

beforeEach( () => {
    captured.actions = null;
    captured.confirm = null;
    apiFetch.mockReset();
    document.body.innerHTML = '';
    window.gratora = { can: { refund_donations: true } };
} );

const byId = ( id ) => captured.actions.find( ( a ) => a.id === id );

it.each( [ 'pause', 'resume', 'cancel' ] )( 'offers %s over a selection', async ( id ) => {
    mount();
    await waitFor( () => !! captured.actions );

    expect( byId( id ).supportsBulk ).toBe( true );
} );

it( 'does not offer change amount over a selection', async () => {
    mount();
    await waitFor( () => !! captured.actions );

    expect( !! byId( 'change_amount' ).supportsBulk ).toBe( false );
} );

it( 'asks once and then acts on every plan chosen', async () => {
    mount();
    await waitFor( () => !! captured.actions );

    byId( 'cancel' ).callback( PLANS );
    await settle();

    expect( captured.confirm ).toBeTruthy();
    expect( captured.confirm.message ).toContain( '3' );

    await captured.confirm.onConfirm();
    await settle();

    expect( posted.map( ( p ) => p.path ) ).toEqual( [
        '/gratora/v1/admin/recurring/1/action',
        '/gratora/v1/admin/recurring/2/action',
        '/gratora/v1/admin/recurring/3/action',
    ] );
    expect( posted.every( ( p ) => p.data.action === 'cancel' ) ).toBe( true );
} );

it( 'keeps the single plan dialog when only one is chosen', async () => {
    mount();
    await waitFor( () => !! captured.actions );

    byId( 'cancel' ).callback( [ PLANS[ 0 ] ] );
    await settle();

    // The rich dialog carries a reason and the notify choice, which a bulk
    // confirmation cannot ask per plan.
    expect( captured.confirm ).toBeNull();
    expect( posted ).toEqual( [] );
} );
