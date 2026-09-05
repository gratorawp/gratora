/**
 * A failure tag tells the admin a plan is in trouble. What the trouble was
 * only reaches them if the detail dialog actually renders it, so this asserts
 * the message is on screen rather than merely present in the payload.
 */

import { render } from 'preact';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

jest.mock( '@wordpress/api-fetch', () => jest.fn( () => new Promise( () => {} ) ) );

import PlanDetailDialog from '../../assets/admin/subscriptions/PlanDetailDialog';

function mount( plan ) {
	document.body.innerHTML = '<div id="root"></div>';
	const host = document.getElementById( 'root' );

	render( <PlanDetailDialog plan={ plan } onClose={ () => {} } onAction={ () => {} } />, host );

	return document.body.textContent;
}

const BASE = {
	id: 38,
	status: 'active',
	gateway: 'stripe',
	gateway_subscription_id: 'demo-sub037',
	amount_cents: 2500,
	currency: 'EUR',
	interval_unit: 'month',
	interval_count: 1,
	donor: { id: 7, name: 'Sam' },
	errors: [],
};

test( 'the admin reads the failure reason, not just that there was one', () => {
	const text = mount( {
		...BASE,
		errors: [
			{ at: '2026-09-01 10:00:00', source: 'portal.recurring', origin: 'Donor portal', message: 'the gateway is not available' },
		],
	} );

	expect( text ).toContain( 'the gateway is not available' );
	// The surface it came from, not the routing key that got it there.
	expect( text ).toContain( 'Donor portal' );
	expect( text ).not.toContain( 'portal.recurring' );
} );

test( 'a plan with nothing wrong shows no problems section', () => {
	expect( mount( BASE ) ).not.toContain( 'Problems' );
} );

test( 'every recorded problem is listed, newest first as the server ordered them', () => {
	const text = mount( {
		...BASE,
		errors: [
			{ at: '2026-09-02 10:00:00', source: 'recurring', origin: 'Scheduled run', message: 'resume failed' },
			{ at: '2026-09-01 10:00:00', source: 'gateway.stripe', origin: 'Stripe', message: 'could not reach Stripe' },
		],
	} );

	expect( text ).toContain( 'resume failed' );
	expect( text ).toContain( 'could not reach Stripe' );
	expect( text.indexOf( 'resume failed' ) ).toBeLessThan( text.indexOf( 'could not reach Stripe' ) );
} );
