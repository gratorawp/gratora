/**
 * The donor profile's recurring table identifies a plan the way the
 * Subscriptions screen does: its own id first, opening the same detail dialog,
 * then the handle the gateway knows it by, then the gateway. The two ids are
 * different numbers for the same plan and an admin reconciling against a
 * gateway dashboard needs to read them apart.
 */

import { render } from 'preact';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

jest.mock( '@wordpress/api-fetch', () => jest.fn( () => Promise.resolve( {} ) ) );

const captured = {};

jest.mock( '@wordpress/dataviews', () => ( {
	...jest.requireActual( '@wordpress/dataviews' ),
	DataViews: ( props ) => {
		captured.props = props;
		return null;
	},
} ) );

const { filterSortAndPaginate } = jest.requireActual( '@wordpress/dataviews' );

import RecurringTab from '../../assets/admin/donors/profile/tabs/RecurringTab';

const { settle } = require( './support/waitFor' );

const plan = ( over = {} ) => ( {
	id: 38, gateway: 'stripe', gateway_subscription_id: 'sub_1Abc',
	amount_cents: 2500, currency: 'USD', interval_unit: 'month',
	interval_count: 1, frequency: 'monthly', status: 'active',
	payments_count: 2, total_paid_cents: 5000, failed_renewals_count: 0,
	errors: [], next_payment_at: '2026-10-01 00:00:00', started_at: '2026-01-01 00:00:00',
	...over,
} );

async function mount( plans = [ plan() ] ) {
	document.body.innerHTML = '<div id="root"></div><div id="cell"></div>';
	render(
		<RecurringTab recurring={ { plans } } onChange={ () => {} } />,
		document.getElementById( 'root' )
	);
	await settle();

	return captured.props;
}

/** Renders one field's cell on its own, so a click reaches the tab's state. */
function cell( id, item ) {
	const host = document.getElementById( 'cell' );
	render( captured.props.fields.find( ( f ) => f.id === id ).render( { item } ), host );

	return host;
}

beforeEach( () => {
	delete captured.props;
} );

it( 'leads with the three columns that identify the plan, in that order', async () => {
	const props = await mount();
	const lead = props.view.fields.slice( 0, 3 );
	const label = ( id ) => props.fields.find( ( f ) => f.id === id ).label;

	expect( lead ).toEqual( [ 'id', 'gateway_subscription_id', 'gateway' ] );
	expect( lead.map( label ) ).toEqual( [ 'ID', 'Gateway subscription ID', 'Gateway' ] );
} );

it( 'shows the subscription id the Subscriptions screen shows', async () => {
	await mount();

	expect( cell( 'id', plan() ).textContent ).toBe( '#38' );
} );

it( 'opens the plan detail dialog when the id is clicked', async () => {
	await mount();

	cell( 'id', plan() ).querySelector( 'a' ).click();
	await settle();

	const dialog = document.getElementById( 'root' ).textContent;
	expect( dialog ).toContain( '$25.00' );
	expect( dialog ).toContain( 'Monthly' );
} );

it( 'sends a reader who opens the id in a new tab to the Subscriptions screen', async () => {
	await mount();

	const href = cell( 'id', plan() ).querySelector( 'a' ).getAttribute( 'href' );

	expect( href ).toContain( 'page=gratora-subscriptions' );
	expect( href ).toContain( '#subscription/38' );
} );

it( 'shows the gateway handle apart from the gateway', async () => {
	await mount();

	expect( cell( 'gateway_subscription_id', plan() ).textContent ).toBe( 'sub_1Abc' );
	expect( cell( 'gateway', plan() ).textContent ).toBe( 'stripe' );
} );

it( 'says so when the gateway never linked one', async () => {
	await mount();

	expect( cell( 'gateway_subscription_id', plan( { gateway_subscription_id: '' } ) ).textContent ).toBe( 'Not linked' );
} );

it( 'finds a plan by either id', async () => {
	const props = await mount( [ plan(), plan( { id: 40, gateway_subscription_id: 'sub_9Zzz' } ) ] );

	const found = ( search ) => filterSortAndPaginate(
		[ plan(), plan( { id: 40, gateway_subscription_id: 'sub_9Zzz' } ) ],
		{ ...props.view, search },
		props.fields
	).data.map( ( p ) => p.id );

	expect( found( '38' ) ).toEqual( [ 38 ] );
	expect( found( 'sub_9Zzz' ) ).toEqual( [ 40 ] );
} );

it( 'sorts Next charge with a cancelled plan in the list', async () => {
	const props = await mount();
	// A cancelled plan carries no next_payment_at, and the default comparator
	// calls localeCompare on whatever getValue returns.
	const mixed = [ plan(), plan( { id: 41, status: 'cancelled', next_payment_at: null } ) ];

	for ( const direction of [ 'asc', 'desc' ] ) {
		expect( () => filterSortAndPaginate(
			mixed,
			{ ...props.view, sort: { field: 'next_payment_at', direction } },
			props.fields
		) ).not.toThrow();
	}
} );

it( 'finds a plan by the id as the cell prints it', async () => {
	const props = await mount();
	const rows = [ plan(), plan( { id: 40, gateway_subscription_id: 'sub_9Zzz' } ) ];
	const found = ( search ) => filterSortAndPaginate( rows, { ...props.view, search }, props.fields )
		.data.map( ( p ) => p.id );

	expect( found( '#38' ) ).toEqual( [ 38 ] );
} );

it( 'orders ids as numbers, so 10 does not sort above 9', async () => {
	const props = await mount();
	const many = [ plan( { id: 9 } ), plan( { id: 10 } ), plan( { id: 2 } ) ];

	const sorted = filterSortAndPaginate(
		many,
		{ ...props.view, sort: { field: 'id', direction: 'asc' } },
		props.fields
	).data.map( ( p ) => p.id );

	expect( sorted ).toEqual( [ 2, 9, 10 ] );
} );

it( 'takes its health cell and its row actions from the shared module', async () => {
	const props = await mount( [ plan( { failed_renewals_count: 2 } ) ] );

	// The tab drifted from the Subscriptions screen once by growing its own
	// copies of these, and the tab's copy had no way to read a plan's problems.
	expect( props.actions.map( ( a ) => a.id ) ).toEqual(
		expect.arrayContaining( [ 'view_details', 'copy_subscription_id' ] )
	);
	expect( cell( 'failed', plan( { failed_renewals_count: 2 } ) ).textContent ).toBe( '2 failures' );
} );

it( 'keeps the columns the Subscriptions screen has, off by default', async () => {
	const props = await mount();
	const defined = props.fields.map( ( f ) => f.id );

	expect( defined ).toEqual( expect.arrayContaining( [ 'gateway', 'started_at' ] ) );
	expect( props.view.fields ).not.toContain( 'started_at' );
} );
