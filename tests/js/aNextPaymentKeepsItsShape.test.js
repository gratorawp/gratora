/**
 * The donor profile dates a next payment as a day and a month. The donation
 * screen a click away dates the same kind of event with its year, and the two
 * shapes are not interchangeable: one renderer for both would silently add a
 * year to every metric card or take it off every donation row.
 */

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

import { render } from 'preact';
import LifetimeMetrics from '../../assets/admin/donors/profile/LifetimeMetrics';
import { formatDateTime } from '../../assets/admin/donations/format';

const LIFETIME = {
	total_cents:       50000,
	count:             4,
	avg_cents:         12500,
	largest_cents:     20000,
	one_time_count:    1,
	recurring_count:   3,
	mrr_cents:         2500,
	mrr_unconverted:   0,
	active_plan_count: 1,
	plan_counts:       {},
	next_payment_at:   '2026-09-02 14:44:00',
	sparkline:         [],
};

function text( node ) {
	document.body.innerHTML = '<div id="root"></div>';
	const host = document.getElementById( 'root' );
	render( node, host );

	return host.textContent;
}

test( 'the profile dates the next payment by day and month', () => {
	const body = text( <LifetimeMetrics lifetime={ LIFETIME } /> );

	expect( body ).toContain( 'next Sep 02' );
	expect( body ).not.toContain( '2026' );
} );

test( 'the donations screen dates the same timestamp with its year and time', () => {
	expect( formatDateTime( LIFETIME.next_payment_at ) ).toContain( '2026' );
	expect( formatDateTime( LIFETIME.next_payment_at ) ).toMatch( /\d{2}:\d{2}/ );
} );
