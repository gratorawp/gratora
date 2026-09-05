/**
 * A failed gateway action is recorded as an error.* event. The timeline's
 * fallback would read "error.admin.recurring" as "Recurring", muted, which
 * says less than nothing next to a real cancellation.
 */

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

import { render } from 'preact';
import { eventMeta } from '../../assets/admin/donors/profile/helpers';
import { eventTitle } from '../../assets/admin/donors/profile/tabs/ActivityTab';

const FAILED_CANCEL = {
	type: 'error.admin.recurring',
	payload: { message: 'Cannot cancel subscription demo-sub037 (stripe, plan #38): the gateway is not available.' },
	amount_cents: null,
};

function text( node ) {
	document.body.innerHTML = '<div id="root"></div>';
	const host = document.getElementById( 'root' );
	render( node, host );

	return host.textContent;
}

test( 'it reads as a failure, not as a muted "Recurring"', () => {
	const meta = eventMeta( FAILED_CANCEL );

	expect( meta.dot ).toBe( 'is-error' );
	expect( meta.label ).toBe( 'Action failed' );
	expect( meta.label ).not.toBe( 'Recurring' );
} );

test( 'the row says what went wrong, in the words the failure used', () => {
	expect( text( eventTitle( FAILED_CANCEL, null ) ) ).toContain( 'the gateway is not available' );
} );

test( 'an error with no message still says something', () => {
	expect( text( eventTitle( { type: 'error.admin.recurring', payload: {} }, null ) ) ).toBe( 'Action failed' );
} );

test( 'a known event type is untouched', () => {
	expect( eventMeta( { type: 'recurring.renewed' } ).label ).toBe( 'Recurring payment' );
} );
