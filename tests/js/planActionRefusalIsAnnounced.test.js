/**
 * A refusal has to read as a refusal.
 *
 * This dialog is opened from the subscriptions list and from the donor
 * profile's recurring tab, which are separate bundles with their own
 * stylesheets. An error drawn with a class that only one of them defines is
 * ordinary body text on the other, sitting directly under the sentence
 * explaining what the action does, with nothing to say the action did not
 * happen.
 */

import { render } from 'preact';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );
jest.mock( '@wordpress/api-fetch', () => jest.fn() );

import apiFetch from '@wordpress/api-fetch';

import PlanActionDialog from '../../assets/admin/_shared/recurring/PlanActions';

const { waitFor } = require( './support/waitFor' );

const PLAN = {
    id: 35,
    status: 'paused',
    amount_cents: 2000,
    currency: 'USD',
    frequency: 'monthly',
    interval_unit: 'month',
    interval_count: 1,
};

const REFUSAL = 'Cannot resume subscription demo-sub034 (stripe, plan #35): the gateway is not available.';

function open( action ) {
    document.body.innerHTML = '<div id="root"></div>';

    render(
        <PlanActionDialog plan={ PLAN } action={ action } onClose={ () => {} } onDone={ () => {} } />,
        document.getElementById( 'root' )
    );

    return document.body;
}

const confirmButton = ( host ) =>
    [ ...host.querySelectorAll( 'button' ) ].find( ( b ) => /Apply change/.test( b.textContent ) );

beforeEach( () => {
    apiFetch.mockReset();
} );

it( 'announces a refusal rather than leaving it to read as body text', async () => {
    apiFetch.mockImplementation( () => Promise.reject( { message: REFUSAL } ) );

    const host = open( 'resume' );
    confirmButton( host ).click();

    await waitFor( () => !! host.querySelector( '[role="alert"]' ), { what: 'the refusal' } );

    // role=alert is the difference a screen reader hears and the styling the
    // component carries with it, neither of which a paragraph has.
    expect( host.querySelector( '[role="alert"]' ).textContent ).toContain( 'Cannot resume subscription' );
} );

it( 'leaves the dialog open on a refusal, so the message is there to read', async () => {
    apiFetch.mockImplementation( () => Promise.reject( { message: REFUSAL } ) );

    const host = open( 'resume' );
    confirmButton( host ).click();

    await waitFor( () => !! host.querySelector( '[role="alert"]' ), { what: 'the refusal' } );

    expect( confirmButton( host ) ).toBeTruthy();
} );

it( 'keeps the approval link inside the refusal it answers', async () => {
    apiFetch.mockImplementation( () => Promise.reject( {
        message: 'PayPal needs the donor to approve this change.',
        data:    { approve_url: 'https://paypal.test/approve/ABC' },
    } ) );

    const host = open( 'change_amount' );
    confirmButton( host ).click();

    await waitFor( () => !! host.querySelector( '[role="alert"] a' ), { what: 'the approval link' } );

    // Below the message as a loose line it reads as unrelated to the failure
    // it is the remedy for.
    const link = host.querySelector( '[role="alert"] a' );
    expect( link.getAttribute( 'href' ) ).toBe( 'https://paypal.test/approve/ABC' );
} );
