/**
 * The goal card is the first of five KPIs on the campaign overview. With no
 * target it had nothing to report and said so, spending a fifth of the row on
 * a "-" over "No goal set". A campaign without a goal is an ordinary state,
 * not a gap to announce.
 */

import { render } from 'preact';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

jest.mock( '@wordpress/api-fetch', () => jest.fn( () => new Promise( () => {} ) ) );

import { GoalProgressCard } from '../../assets/admin/campaigns/Detail';

function mount( campaign ) {
	document.body.innerHTML = '<div id="root"></div>';
	const host = document.getElementById( 'root' );

	render( <GoalProgressCard campaign={ campaign } />, host );

	return host;
}

const AMOUNT = { goal_type: 'amount', goal_cents: 400000, raised_cents: 100000 };

describe( 'the goal card on the campaign overview', () => {
	it( 'reports progress against an amount target', () => {
		const host = mount( AMOUNT );

		expect( host.textContent ).toContain( '25%' );
		expect( host.querySelector( '.gratora-metric__bar-fill' ).style.width ).toBe( '25%' );
	} );

	it( 'counts donations and donors the same way', () => {
		expect( mount( { goal_type: 'donations', goal_count: 50, donations_count: 10 } ).textContent )
			.toContain( '20%' );
		expect( mount( { goal_type: 'donors', goal_count: 4, donors_count: 3 } ).textContent )
			.toContain( '75%' );
	} );

	it( 'stands down entirely when no target is set', () => {
		expect( mount( { goal_type: 'amount', goal_cents: null, raised_cents: 100000 } ).innerHTML )
			.toBe( '' );
	} );

	it( 'treats a zero target as no target', () => {
		expect( mount( { goal_type: 'amount', goal_cents: 0, raised_cents: 100000 } ).innerHTML )
			.toBe( '' );
	} );

	it( 'reads the count target for a count goal, not the amount', () => {
		// goal_cents carries a stale amount from an earlier goal type; the card
		// measures donors, so an unset goal_count is still no goal.
		expect( mount( { goal_type: 'donors', goal_cents: 400000, goal_count: null, donors_count: 3 } ).innerHTML )
			.toBe( '' );
	} );

	it( 'says how far past a met goal it is', () => {
		// The card prints "current / target" underneath, so a capped number is
		// contradicted by the line below it. Five of one site's seven campaigns
		// read 100% while standing between 1,957% and 24,841%.
		const host = mount( { goal_type: 'amount', goal_cents: 100000, raised_cents: 250000 } );

		expect( host.textContent ).toContain( '250%' );
		expect( host.textContent ).not.toContain( '100%' );
	} );

	it( 'but its bar still stops at the end of the track', () => {
		const host = mount( { goal_type: 'amount', goal_cents: 100000, raised_cents: 250000 } );

		expect( host.querySelector( '.gratora-metric__bar-fill' ).style.width ).toBe( '100%' );
	} );
} );
