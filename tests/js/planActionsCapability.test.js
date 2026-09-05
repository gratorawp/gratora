/**
 * Changing a recurring plan is refund-grade authority. A bookkeeper granted
 * only "view donations" was offered Pause, Skip next, Change amount and Cancel
 * in every row menu, and learned the truth after filling the dialog in.
 */

import { render } from 'preact';
import apiFetch from '@wordpress/api-fetch';

import { actionsFor, retryActionFor } from '../../assets/admin/_shared/recurring/PlanActions';
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

const captured = { actions: null };

jest.mock( '@wordpress/dataviews', () => ( {
    DataViews: ( props ) => {
        captured.actions = props.actions;
        return null;
    },
} ) );

const PLAN = {
    id: 7,
    status: 'past_due',
    can_retry: true,
    can_change_interval: true,
    failed_renewals_count: 2,
    amount_cents: 2500,
    currency: 'EUR',
    interval_unit: 'month',
    interval_count: 1,
    donor: { name: 'Nadia' },
};

let listPaths = [];

function mount( savedView ) {
    listPaths = [];
    apiFetch.mockImplementation( ( { path, parse } ) => {
        if ( path.startsWith( '/fundkit/v1/admin/me/table-view' ) ) {
            return Promise.resolve( savedView || {} );
        }
        if ( parse === false ) {
            listPaths.push( path );
            return Promise.resolve( { json: async () => [ PLAN ], headers: { get: () => '1' } } );
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
    apiFetch.mockReset();
    document.body.innerHTML = '';
    delete window.fundkit;
} );

const eligible = () => captured.actions
    .filter( ( a ) => ! a.isEligible || a.isEligible( PLAN ) )
    .map( ( a ) => a.id );

it( 'offers no plan action to a reader who cannot change what is charged', async () => {
    window.fundkit = { can: { refund_donations: false } };

    mount();
    await waitFor( () => !! captured.actions );

    // Reading a plan is not the authority in question.
    expect( eligible() ).toEqual( [ 'view_details' ] );
    expect( actionsFor( PLAN ) ).toEqual( [] );
    expect( retryActionFor( PLAN ) ).toBeNull();
} );

it( 'still offers them to a reader who can', async () => {
    window.fundkit = { can: { refund_donations: true } };

    mount();
    await waitFor( () => !! captured.actions );

    expect( eligible() ).toEqual(
        expect.arrayContaining( [ 'retry', 'pause', 'skip_next', 'change_amount', 'cancel' ] )
    );
} );

it( 'keeps them when the page never said, so a stale global cannot lock an admin out', async () => {
    mount();
    await waitFor( () => !! captured.actions );

    expect( eligible() ).toContain( 'cancel' );
} );

it( 'asks the server for a cadence, not an interval unit', async () => {
    mount( { filters: [ { field: 'interval', operator: 'is', value: 'monthly' } ] } );
    await waitFor( () => listPaths.some( ( p ) => p.includes( 'frequency=' ) ) );

    const last = listPaths[ listPaths.length - 1 ];
    expect( last ).toContain( 'frequency=monthly' );
    expect( last ).not.toContain( 'interval=' );
} );
