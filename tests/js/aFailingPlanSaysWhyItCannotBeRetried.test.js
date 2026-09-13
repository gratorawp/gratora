/**
 * A subscription that says "Past due, 3 failures" and offers nothing.
 *
 * Retry is only possible where the gateway takes the instruction, and
 * DataViews drops an action a row is not eligible for without a word, so the
 * row that most needs a control had none and no reason for it either.
 *
 * The subscriptions list is where that sentence has to be readable, because
 * nothing else on that screen is going to say it. The donor profile is not:
 * its own banner carries the reason above the table, so offering the control
 * there only to answer with the same words would be one sentence twice on one
 * screen. That difference is the point of these tests.
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

/** The same row, from a gateway that does take the instruction. */
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

async function mount( Component, props, rows ) {
    seed( rows );

    const root = document.createElement( 'div' );
    document.body.appendChild( root );
    render( <Component { ...props } />, root );

    await waitFor( () => !! captured.actions, { what: 'the table to register its actions' } );
    await settle();

    return captured.actions.find( ( a ) => a.id === 'retry' );
}

const subscriptionsList = ( rows ) =>
    mount( require( '../../assets/admin/subscriptions/List' ).default, {}, rows );

/** The modal the control answers in, whatever it has to say. */
const dialog = () => document.querySelector( '.gratora-dialog, [role="dialog"]' );

const donorProfile = ( rows ) =>
    mount(
        require( '../../assets/admin/donors/profile/tabs/RecurringTab' ).default,
        { recurring: { plans: rows }, onChange: () => {} },
        rows
    );

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

describe( 'the subscriptions list, which has nothing else to say it', () => {

    it( 'still offers the control on a plan it cannot collect', async () => {
        const retry = await subscriptionsList( [ BLOCKED ] );

        expect( retry.isEligible( BLOCKED ) ).toBe( true );
    } );

    it( 'reads the reason out in a dialog when that control is used', async () => {
        const retry = await subscriptionsList( [ BLOCKED ] );

        retry.callback( [ BLOCKED ] );
        await settle();

        expect( dialog() ).not.toBeNull();
        expect( dialog().textContent ).toContain( WHY );
    } );

    /** Where the click was, not on the page the admin has to go back to. */
    it( 'says it in the dialog rather than behind it', async () => {
        const retry = await subscriptionsList( [ BLOCKED ] );

        retry.callback( [ BLOCKED ] );
        await settle();

        const outside = document.body.textContent.replace( dialog().textContent, '' );

        expect( outside ).not.toContain( WHY );
    } );

    /** A selection part collectable is not part acted on and part dropped. */
    it( 'carries the reason into the confirmation for a mixed selection', async () => {
        const retry = await subscriptionsList( [ BLOCKED ] );

        retry.callback( [ BLOCKED, RETRYABLE ] );
        await settle();

        expect( dialog().textContent ).toContain( WHY );
        expect( dialog().textContent ).toMatch( CONFIRMATION );
    } );

    /** The reason instead of the request: nothing is put to the gateway. */
    it( 'offers no confirmation to collect it', async () => {
        const retry = await subscriptionsList( [ BLOCKED ] );

        retry.callback( [ BLOCKED ] );
        await settle();

        expect( document.body.textContent ).not.toMatch( CONFIRMATION );
    } );

    /** A healthy plan is owed nothing, so it gets neither control nor reason. */
    it( 'offers an active plan no retry at all', async () => {
        const retry = await subscriptionsList( [ BLOCKED ] );

        expect( retry.isEligible( {
            ...BLOCKED, status: 'active', failed_renewals_count: 0,
        } ) ).toBe( false );
    } );

    it( 'sends a plan it can collect straight to the confirmation', async () => {
        const retry = await subscriptionsList( [ RETRYABLE ] );

        retry.callback( [ RETRYABLE ] );
        await settle();

        expect( document.body.textContent ).not.toContain( WHY );
        expect( document.body.textContent ).toMatch( CONFIRMATION );
    } );
} );

describe( 'the donor profile, which says it in a banner above the table', () => {
    it( 'leaves the control off a plan it cannot collect', async () => {
        const retry = await donorProfile( [ BLOCKED ] );

        expect( retry.isEligible( BLOCKED ) ).toBe( false );
    } );

    it( 'still offers it on a plan it can', async () => {
        const retry = await donorProfile( [ RETRYABLE ] );

        expect( retry.isEligible( RETRYABLE ) ).toBe( true );
    } );
} );
