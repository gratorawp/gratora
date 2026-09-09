/**
 * The health cell and the two row actions both screens share. These were
 * structural tests over inline copies in the subscriptions list; the code they
 * guarded is one module now, so they render and call it instead.
 */

import { render } from 'preact';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

import {
	renderHealth,
	viewDetailsAction,
	copySubscriptionIdAction,
	intervalLabel,
} from '../../assets/admin/_shared/recurring/planColumns';

function health( item ) {
	document.body.innerHTML = '<div id="root"></div>';
	const host = document.getElementById( 'root' );
	render( renderHealth( item ), host );

	return host;
}

test( 'a declined renewal reads as a failure', () => {
	const host = health( { failed_renewals_count: 3, errors: [] } );

	expect( host.textContent ).toBe( '3 failures' );
	expect( host.querySelector( '.gratora-pill--amber' ) ).not.toBeNull();
} );

test( 'a failed operation reads as a problem, worded apart from a failure', () => {
	const host = health( { failed_renewals_count: 0, errors: [ {}, {} ] } );

	expect( host.textContent ).toBe( '2 problems' );
	expect( host.querySelector( '.gratora-pill--red' ) ).not.toBeNull();
} );

test( 'a renewal failure wins, since the donor card is the more urgent fact', () => {
	expect( health( { failed_renewals_count: 1, errors: [ {} ] } ).textContent ).toBe( '1 failure' );
} );

test( 'OK is said only when there is neither', () => {
	expect( health( { failed_renewals_count: 0, errors: [] } ).textContent ).toBe( 'OK' );
	expect( health( { failed_renewals_count: 0 } ).textContent ).toBe( 'OK' );
} );

test( 'View details opens the dialog and starts no action', () => {
	let opened = null;
	const action = viewDetailsAction( ( row ) => { opened = row; } );

	action.callback( [ { id: 7 } ] );

	expect( opened ).toEqual( { id: 7 } );
	// DataViews drops an ineligible action from the menu and renders a primary
	// one as an icon button, so either flag would silently remove it.
	expect( action.isEligible ).toBeUndefined();
	expect( action.isPrimary ).toBeUndefined();
} );

test( 'Copy subscription id is offered only where there is one', () => {
	const action = copySubscriptionIdAction();

	expect( action.isEligible( { gateway_subscription_id: 'sub_1' } ) ).toBe( true );
	expect( action.isEligible( { gateway_subscription_id: '' } ) ).toBe( false );
} );

test( 'the interval is pluralised per unit rather than printed raw', () => {
	expect( intervalLabel( 'month', 1 ) ).toBe( '1 month' );
	expect( intervalLabel( 'month', 3 ) ).toBe( '3 months' );
	expect( intervalLabel( 'week', 2 ) ).toBe( '2 weeks' );
} );
