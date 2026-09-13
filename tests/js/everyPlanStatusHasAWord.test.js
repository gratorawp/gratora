/**
 * The words for a plan's statuses come from the side that writes them.
 *
 * Four screens and a donor portal each kept their own list of the same five,
 * so `pending` (where a PayPal plan waits for activation) had no word anywhere
 * and no filter could find it. Two copies missing the same status agree with
 * each other, which is why the test asking one about the other passed.
 *
 * These seed a vocabulary the browser could not have guessed and check it
 * comes out the other end, so a screen that goes back to its own list fails
 * here rather than on a translated site.
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

import { planStatusPill } from '../../assets/admin/donors/profile/helpers';
import { recurringStatusLabel } from '../../assets/donor-portal/statusLabels';

// Deliberately not the real vocabulary: a screen falling back to a list of its
// own would still look right against the real words.
const SHIPPED = [
    { value: 'active', label: 'Underway', variant: 'green' },
    { value: 'pending', label: 'Waiting on PayPal', variant: 'amber' },
    { value: 'lapsed', label: 'Lapsed', variant: 'red' },
];

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

async function statusFieldOf( load, props = {} ) {
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
        plan_statuses: SHIPPED,
        can: {
            refund_donations: true, delete_donations: true, redact_donors: true,
            manage_options: true, view_donations: true, edit_donations: true,
        },
    };
    window.gratoraPortal = { planStatuses: SHIPPED };
} );

it.each( SCREENS )( '%s filters by exactly what it was handed', async ( _name, load, props ) => {
    const status = await statusFieldOf( load, props );

    expect( status.elements ).toEqual( SHIPPED.map( ( { value, label } ) => ( { value, label } ) ) );
} );

it( 'the profile pill says the word it was handed', () => {
    expect( planStatusPill( 'pending' ).label ).toBe( 'Waiting on PayPal' );
} );

it( 'and wears the colour it was handed', () => {
    expect( planStatusPill( 'lapsed' ).cls ).toBe( 'is-error' );
} );

it( 'the donor portal says the word it was handed', () => {
    expect( recurringStatusLabel( 'pending' ) ).toBe( 'Waiting on PayPal' );
} );
