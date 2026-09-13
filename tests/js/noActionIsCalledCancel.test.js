/**
 * DataViews draws its own "Cancel" beside the bulk actions to drop the
 * selection, so an action of ours called "Cancel" put two buttons of that name
 * side by side in an icon-only bar, and only one of them ended subscriptions
 * at the processor. In a toolbar the word reads as "never mind".
 *
 * The shared plan vocabulary already named the act: PlanActions offers
 * "Cancel subscription" and the confirm button says the same. These are the
 * call sites that did not use it.
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
    default: { success: jest.fn(), error: jest.fn(), info: jest.fn() },
} ) );

const captured = { actions: null };

jest.mock( '@wordpress/dataviews', () => ( {
    DataViews: ( props ) => {
        captured.actions = props.actions;
        return null;
    },
    // The donor profile paginates in the browser rather than on the server.
    filterSortAndPaginate: ( data ) => ( {
        data,
        paginationInfo: { totalItems: data.length, totalPages: 1 },
    } ),
} ) );

const PLAN = {
    id: 7,
    status: 'active',
    gateway: 'stripe',
    gateway_subscription_id: 'sub_1',
    amount_cents: 2500,
    currency: 'EUR',
    interval_unit: 'month',
    interval_count: 1,
    donor: { id: 2, name: 'Sam' },
    can_retry: true,
    failed_renewals_count: 0,
    errors: [],
};

function seed() {
    apiFetch.mockReset();
    apiFetch.mockImplementation( ( { path, parse } ) => {
        if ( parse === false ) {
            return Promise.resolve( { json: async () => [ PLAN ], headers: { get: () => '1' } } );
        }
        if ( typeof path === 'string' && path.includes( '/me/' ) ) return Promise.resolve( {} );
        return Promise.resolve( [ PLAN ] );
    } );
}

/** DataViews accepts a string or a function of the selection. */
const labelOf = ( action, items ) =>
    ( typeof action.label === 'string' ? action.label : action.label( items ) );

async function actionsOf( load, props = {} ) {
    seed();
    const Component = load();

    const root = document.createElement( 'div' );
    document.body.appendChild( root );
    render( <Component { ...props } />, root );

    await waitFor( () => !! captured.actions, { what: 'the table to register its actions' } );

    return captured.actions;
}

// The subscriptions screen fetches its own plans; the donor profile is handed
// them, so each is rendered the way its own screen does it.
const TABLES = [
    [ 'subscriptions', () => require( '../../assets/admin/subscriptions/List' ).default, {} ],
    [
        'the donor profile',
        () => require( '../../assets/admin/donors/profile/tabs/RecurringTab' ).default,
        { recurring: { plans: [ PLAN ] }, onChange: () => {} },
    ],
];

beforeEach( () => {
    captured.actions = null;
    document.body.innerHTML = '';
    window.gratora = {
        can: {
            delete_donations: true, refund_donations: true, redact_donors: true,
            manage_options: true, view_donations: true, edit_donations: true,
        },
    };
} );

it.each( TABLES )( 'no action on %s is called just Cancel', async ( _name, load, props ) => {
    const actions = await actionsOf( load, props );

    const bare = actions
        .filter( ( a ) => labelOf( a, [ PLAN ] ).trim() === 'Cancel' )
        .map( ( a ) => a.id );

    expect( bare ).toEqual( [] );
} );

it.each( TABLES )( 'and %s names the subscription it would end', async ( _name, load, props ) => {
    const actions = await actionsOf( load, props );
    const cancel  = actions.find( ( a ) => a.id === 'cancel' );

    expect( cancel ).toBeDefined();
    expect( labelOf( cancel, [ PLAN ] ) ).toBe( 'Cancel subscription' );
} );

/** The bulk bar acts on a selection, so it says how many it would end. */
test( 'the subscriptions bulk label counts what it would cancel', async () => {
    const actions = await actionsOf( () => require( '../../assets/admin/subscriptions/List' ).default );
    const cancel  = actions.find( ( a ) => a.id === 'cancel' );

    expect( labelOf( cancel, [ PLAN, PLAN, PLAN ] ) ).toBe( 'Cancel 3 subscriptions' );
} );
