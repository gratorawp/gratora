/**
 * Retry is the one plan action whose answer is not the end of the story: the
 * gateway takes the instruction and its own webhook confirms the money later.
 *
 * The dialog closed on the 200, so an admin clicked Retry now and got a modal
 * vanishing and nothing else. A failure was already reported in place; a
 * success was reported nowhere. The round trip is to the processor, so the
 * wait is long enough to look like the click missed.
 */

import { render } from 'preact';
import apiFetch from '@wordpress/api-fetch';

const { settle } = require( './support/waitFor' );

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

import PlanActionDialog from '../../assets/admin/_shared/recurring/PlanActions';

const PLAN = {
    id: 7,
    status: 'past_due',
    gateway: 'stripe',
    gateway_label: 'Stripe',
    amount_cents: 2500,
    currency: 'USD',
    interval_unit: 'month',
    interval_count: 1,
    frequency: 'monthly',
    frequency_options: [ 'monthly' ],
    failed_renewals_count: 2,
};

const closed = { count: 0 };
const done = { count: 0 };

function mount( action = 'retry' ) {
    document.body.innerHTML = '<div id="root"></div>';
    render(
        <PlanActionDialog
            plan={ PLAN }
            action={ action }
            onClose={ () => { closed.count++; } }
            onDone={ () => { done.count++; } }
        />,
        document.getElementById( 'root' )
    );
}

const button = ( label ) => [ ...document.querySelectorAll( 'button' ) ]
    .find( ( b ) => b.textContent.trim() === label );

/** Resolves when the test says so, so the in-flight state can be looked at. */
function holdTheGateway() {
    let answer;
    apiFetch.mockReset();
    apiFetch.mockImplementation( () => new Promise( ( resolve, reject ) => {
        answer = { resolve, reject };
    } ) );

    return () => answer;
}

beforeEach( () => {
    closed.count = 0;
    done.count = 0;
    window.gratora = { can: { refund_donations: true, manage_options: true } };
} );

describe( 'while the gateway is being asked', () => {
    it( 'says so, with a spinner, naming the gateway', async () => {
        const gateway = holdTheGateway();
        mount();
        button( 'Retry now' ).click();
        await settle();

        expect( document.body.textContent ).toContain( 'Asking Stripe to collect it' );
        expect( document.querySelector( '.components-spinner' ) ).not.toBeNull();
        expect( gateway() ).toBeDefined();
    } );

    it( 'stops offering the explanation of a decision already taken', async () => {
        holdTheGateway();
        mount();
        button( 'Retry now' ).click();
        await settle();

        expect( document.body.textContent ).not.toContain( 'will try to collect the outstanding renewal' );
    } );
} );

describe( 'when the gateway has taken it', () => {
    const succeed = async () => {
        const gateway = holdTheGateway();
        mount();
        button( 'Retry now' ).click();
        await settle();
        gateway().resolve( {} );
        await settle();
    };

    it( 'keeps the dialog up and says what happened', async () => {
        await succeed();

        expect( closed.count ).toBe( 0 );
        expect( document.body.textContent ).toContain( 'Stripe has been asked to collect it' );
    } );

    /** Taken is not paid, and the webhook is what says paid. */
    it( 'does not call it a payment', async () => {
        await succeed();

        expect( document.body.textContent ).not.toMatch( /paid|collected\b/i );
    } );

    it( 'refreshes the row behind it', async () => {
        await succeed();

        expect( done.count ).toBe( 1 );
    } );

    it( 'leaves nothing to do but close', async () => {
        await succeed();

        expect( button( 'Retry now' ) ).toBeUndefined();
        expect( button( 'Close' ) ).toBeDefined();
    } );
} );

describe( 'when the gateway refuses', () => {
    it( 'says so in the dialog and offers another go', async () => {
        const gateway = holdTheGateway();
        mount();
        button( 'Retry now' ).click();
        await settle();
        gateway().reject( { message: 'There is nothing outstanding to collect.' } );
        await settle();

        expect( closed.count ).toBe( 0 );
        expect( document.body.textContent ).toContain( 'nothing outstanding to collect' );
        expect( button( 'Retry now' ) ).toBeDefined();
    } );
} );

/** Every other action reports by the row changing, and closes as it did. */
describe( 'the actions whose answer is the whole story', () => {
    it( 'closes on success', async () => {
        const gateway = holdTheGateway();
        mount( 'skip_next' );
        button( 'Apply change' ).click();
        await settle();
        gateway().resolve( {} );
        await settle();

        expect( closed.count ).toBe( 1 );
    } );
} );
