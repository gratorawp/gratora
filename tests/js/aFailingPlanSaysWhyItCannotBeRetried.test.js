/**
 * A subscription that says "Past due, 3 failures" and offers nothing.
 *
 * Retry is only possible where the gateway takes the instruction, and
 * DataViews drops an action a row is not eligible for without a word, so the
 * row that most needs a control had none and no reason for it either. The
 * server now sends the reason; these are the two screens that must read it
 * out, because a sentence nobody can reach is the same as no sentence.
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

const captured = { actions: null };

jest.mock( '@wordpress/dataviews', () => ( {
    DataViews: ( props ) => {
        captured.actions = props.actions;
        return null;
    },
    filterSortAndPaginate: ( data ) => ( {
        data,
        paginationInfo: { totalItems: data.length, totalPages: 1 },
    } ),
} ) );

const WHY = 'Stripe is not set up on this site, so nothing can be asked of it.';

/** What asking the gateway looks like, on either screen. */
const CONFIRMATION = /Retry (the payment|these payments)/;

const BLOCKED = {
    id: 7,
    status: 'past_due',
    gateway: 'stripe',
    gateway_label: 'Stripe',
    gateway_subscription_id: 'sub_1',
    amount_cents: 2500,
    currency: 'EUR',
    interval_unit: 'month',
    interval_count: 1,
    donor: { id: 2, name: 'Sam' },
    can_retry: false,
    retry_blocked: WHY,
    failed_renewals_count: 3,
    errors: [],
};

/** Same row, from a gateway that does take the instruction. */
const RETRYABLE = { ...BLOCKED, id: 8, can_retry: true, retry_blocked: null };

function seed( rows ) {
    apiFetch.mockReset();
    apiFetch.mockImplementation( ( { path, parse } ) => {
        if ( parse === false ) {
            return Promise.resolve( { json: async () => rows, headers: { get: () => String( rows.length ) } } );
        }
        if ( typeof path === 'string' && path.includes( '/me/' ) ) return Promise.resolve( {} );
        return Promise.resolve( rows );
    } );
}

const SCREENS = [
    [
        'the subscriptions list',
        () => [ require( '../../assets/admin/subscriptions/List' ).default, {} ],
    ],
    [
        'the donor profile',
        ( rows ) => [
            require( '../../assets/admin/donors/profile/tabs/RecurringTab' ).default,
            { recurring: { plans: rows }, onChange: () => {} },
        ],
    ],
];

async function mount( build, rows ) {
    seed( rows );
    const [ Component, props ] = build( rows );

    const root = document.createElement( 'div' );
    document.body.appendChild( root );
    render( <Component { ...props } />, root );

    await waitFor( () => !! captured.actions, { what: 'the table to register its actions' } );
    await settle();

    return captured.actions.find( ( a ) => a.id === 'retry' );
}

beforeEach( () => {
    captured.actions = null;
    document.body.innerHTML = '';
    window.gratora = {
        can: {
            refund_donations: true, delete_donations: true, redact_donors: true,
            manage_options: true, view_donations: true, edit_donations: true,
        },
    };
} );

it.each( SCREENS )( 'on %s a plan that cannot be retried still offers the control', async ( _name, build ) => {
    const retry = await mount( build, [ BLOCKED ] );

    expect( retry.isEligible( BLOCKED ) ).toBe( true );
} );

it.each( SCREENS )( 'and %s reads the reason out when it is used', async ( _name, build ) => {
    const retry = await mount( build, [ BLOCKED ] );

    retry.callback( [ BLOCKED ] );
    await settle();

    expect( document.body.textContent ).toContain( WHY );
} );

/** The reason instead of the request: nothing is put to the gateway. */
it.each( SCREENS )( 'and %s offers no confirmation to collect it', async ( _name, build ) => {
    const retry = await mount( build, [ BLOCKED ] );

    retry.callback( [ BLOCKED ] );
    await settle();

    expect( document.body.textContent ).not.toMatch( CONFIRMATION );
} );

/** A healthy plan is not owed a payment, so it gets neither control nor reason. */
it.each( SCREENS )( 'on %s an active plan is offered no retry at all', async ( _name, build ) => {
    const retry = await mount( build, [ BLOCKED ] );

    expect( retry.isEligible( {
        ...BLOCKED, status: 'active', failed_renewals_count: 0,
    } ) ).toBe( false );
} );

/** And the row that can be collected goes straight to the confirmation. */
it.each( SCREENS )( 'on %s a retryable plan says nothing and opens the dialog', async ( _name, build ) => {
    const retry = await mount( build, [ RETRYABLE ] );

    retry.callback( [ RETRYABLE ] );
    await settle();

    expect( document.body.textContent ).not.toContain( WHY );
    expect( document.body.textContent ).toMatch( CONFIRMATION );
} );
