/**
 * Both the subscriptions row and a donor's recurring tab name a plan as
 * `#subscription/<id>`, and nothing on the destination read it. Copied,
 * bookmarked or followed from another screen, the address opened an
 * unfiltered list of every plan on the site and left the reader to find the
 * one they had asked for.
 */

import { render } from 'preact';
import apiFetch from '@wordpress/api-fetch';

const { waitFor, settle } = require( './support/waitFor' );

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

jest.mock( '../../assets/admin/_shared/notify', () => ( {
    __esModule: true,
    default: { success: jest.fn(), error: jest.fn(), info: jest.fn() },
} ) );

jest.mock( '@wordpress/dataviews', () => ( {
    DataViews: () => null,
    filterSortAndPaginate: ( data ) => ( { data, paginationInfo: { totalItems: data.length, totalPages: 1 } } ),
} ) );

import notify from '../../assets/admin/_shared/notify';

// Not on the first page of the list, which is the case that matters.
const LINKED = {
    id: 903,
    status: 'past_due',
    gateway: 'stripe',
    gateway_label: 'Stripe',
    gateway_subscription_id: 'sub_linked',
    amount_cents: 2500,
    currency: 'USD',
    interval_unit: 'month',
    interval_count: 1,
    donor: { id: 2, name: 'Sam Okafor' },
    errors: [],
    failed_renewals_count: 1,
};

const asked = { paths: [] };

function seed( { found = true } = {} ) {
    asked.paths = [];
    apiFetch.mockReset();
    apiFetch.mockImplementation( ( { path, parse } ) => {
        asked.paths.push( String( path ) );
        if ( parse === false ) {
            return Promise.resolve( { json: async () => [], headers: { get: () => '0' } } );
        }
        if ( /\/admin\/recurring\/\d+$/.test( String( path ) ) ) {
            return found ? Promise.resolve( LINKED ) : Promise.reject( { message: 'gone' } );
        }
        if ( String( path ).includes( '/me/' ) ) return Promise.resolve( {} );
        return Promise.resolve( [] );
    } );
}

async function mount() {
    const List = require( '../../assets/admin/subscriptions/List' ).default;
    const root = document.createElement( 'div' );
    document.body.appendChild( root );
    render( <List />, root );
    await settle();
}

const dialog = () => document.querySelector( '.gratora-dialog' );

beforeEach( () => {
    document.body.innerHTML = '';
    window.location.hash = '';
    window.gratora = { can: { refund_donations: true, view_donations: true, manage_options: true } };
} );

it( 'opens the plan the address names', async () => {
    window.location.hash = '#subscription/903';
    seed();
    await mount();

    await waitFor( () => !! dialog(), { what: 'the plan the link named' } );

    expect( dialog().textContent ).toContain( '903' );
} );

/** Asked for by id, because the plan is as likely to be behind a filter. */
it( 'asks the server for it rather than hunting the rows on screen', async () => {
    window.location.hash = '#subscription/903';
    seed();
    await mount();
    await waitFor( () => !! dialog(), { what: 'the plan the link named' } );

    expect( asked.paths ).toContain( '/gratora/v1/admin/recurring/903' );
} );

it( 'opens nothing when the address names no plan', async () => {
    seed();
    await mount();

    expect( dialog() ).toBeNull();
    expect( asked.paths.some( ( p ) => /\/admin\/recurring\/\d+$/.test( p ) ) ).toBe( false );
} );

/** A stale bookmark says so rather than opening an empty dialog. */
it( 'says so when the plan is gone', async () => {
    window.location.hash = '#subscription/903';
    seed( { found: false } );
    await mount();
    await settle();

    expect( dialog() ).toBeNull();
    expect( notify.error ).toHaveBeenCalled();
} );
