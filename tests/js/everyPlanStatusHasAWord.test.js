/**
 * A plan sits in `pending` from the moment a donor approves a PayPal
 * subscription until PayPal activates it, which can be never. Neither the
 * profile's pill nor either screen's filter knew the word, so the row read
 * "pending" in untranslated English and no filter could find it.
 *
 * The screens kept their own copies of the same five statuses. Two copies
 * missing the same word agree with each other, so a test that asked one about
 * the other passed. These drive from the lifecycle instead.
 *
 * What this cannot see: the lifecycle list is maintained by hand against the
 * PHP that writes these statuses. A seventh added there is still invisible
 * here until someone adds it. PayPalSubscriptionTest pins the one that
 * was missing.
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

const captured = { fields: null };

jest.mock( '@wordpress/dataviews', () => ( {
    DataViews: ( props ) => {
        captured.fields = props.fields;
        return null;
    },
    filterSortAndPaginate: ( data ) => ( {
        data,
        paginationInfo: { totalItems: data.length, totalPages: 1 },
    } ),
} ) );

import { PLAN_STATUSES } from '../../assets/admin/_shared/statuses';
import { planStatusPill } from '../../assets/admin/donors/profile/helpers';

const PLAN = {
    id: 7,
    status: 'pending',
    gateway: 'paypal',
    gateway_subscription_id: 'I-SUB',
    amount_cents: 2500,
    currency: 'EUR',
    interval_unit: 'month',
    interval_count: 1,
    donor: { id: 2, name: 'Sam' },
    can_retry: false,
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

async function statusFilterOf( load, props = {} ) {
    seed();
    const Component = load();

    const root = document.createElement( 'div' );
    document.body.appendChild( root );
    render( <Component { ...props } />, root );

    await waitFor( () => !! captured.fields, { what: 'the table to register its fields' } );

    return captured.fields.find( ( f ) => f.id === 'status' );
}

const SCREENS = [
    [ 'the subscriptions list', () => require( '../../assets/admin/subscriptions/List' ).default, {} ],
    [
        'the donor profile',
        () => require( '../../assets/admin/donors/profile/tabs/RecurringTab' ).default,
        { recurring: { plans: [ PLAN ] }, onChange: () => {} },
    ],
];

beforeEach( () => {
    captured.fields = null;
    document.body.innerHTML = '';
    window.gratora = {
        can: {
            refund_donations: true, delete_donations: true, redact_donors: true,
            manage_options: true, view_donations: true, edit_donations: true,
        },
    };
} );

it( 'has a word for every status a plan can hold', () => {
    const slugs = PLAN_STATUSES.filter( ( s ) => planStatusPill( s ).label === s );

    expect( slugs ).toEqual( [] );
} );

it.each( SCREENS )( '%s can be filtered by every one of them', async ( _name, load, props ) => {
    const status = await statusFilterOf( load, props );

    expect( status.elements.map( ( e ) => e.value ) ).toEqual( PLAN_STATUSES );
} );

it.each( SCREENS )( 'and %s filters by the same word the pill shows', async ( _name, load, props ) => {
    const status = await statusFilterOf( load, props );

    const disagreed = status.elements.filter( ( e ) => e.label !== planStatusPill( e.value ).label );

    expect( disagreed ).toEqual( [] );
} );
