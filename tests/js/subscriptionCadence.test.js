/**
 * The admin names a plan's cadence with the words the donor chose it by, and
 * offers that cadence as a filter without offering it as a column. Both screens
 * that list plans have to say the same thing, and a plan on a cadence this
 * product cannot name has to be counted rather than rounded to the nearest one.
 */

import { render } from 'preact';

import apiFetch from '@wordpress/api-fetch';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

jest.mock( '../../assets/admin/_shared/notify', () => ( {
	__esModule: true,
	default: { success: jest.fn(), error: jest.fn(), info: jest.fn() },
} ) );

const captured = {};

jest.mock( '@wordpress/dataviews', () => ( {
	DataViews: ( props ) => {
		captured.props = props;
		return null;
	},
	filterSortAndPaginate: ( data ) => ( { data, paginationInfo: { totalItems: data.length, totalPages: 1 } } ),
} ) );

import { CADENCE_LABEL, cadenceLabel } from '../../assets/admin/_shared/recurring/planColumns';
import List from '../../assets/admin/subscriptions/List';
import RecurringTab from '../../assets/admin/donors/profile/tabs/RecurringTab';

const { settle } = require( './support/waitFor' );

/** Every value FrequencyMap::fromInterval can return other than null. */
const SERVER_RANGE = [ 'weekly', 'biweekly', 'monthly', 'quarterly', 'yearly' ];

const plan = ( over = {} ) => ( {
	id: 1, gateway: 'stripe', amount_cents: 2500, currency: 'USD',
	interval_unit: 'month', interval_count: 1, frequency: 'monthly',
	status: 'active', payments_count: 2, total_paid_cents: 5000,
	failed_renewals_count: 0, errors: [],
	next_payment_at: '2026-10-01 00:00:00',
	donor: { id: 9, name: 'A' }, campaign: null,
	...over,
} );

/** The text a field's cell renders for one row. */
function cell( fields, id, item ) {
	document.body.innerHTML = '<div id="root"></div>';
	const host = document.getElementById( 'root' );
	render( fields.find( ( f ) => f.id === id ).render( { item } ), host );

	return host.textContent;
}

/**
 * The list, mounted over a saved view. A view that names the interval field is
 * the state on disk for any reader whose saved arrangement predates the field
 * being kept out of the table.
 */
async function mountList( savedFields ) {
	apiFetch.mockImplementation( ( { path, parse } ) => {
		if ( parse === false ) {
			return Promise.resolve( { json: async () => [ plan() ], headers: { get: () => '1' } } );
		}
		if ( path.startsWith( '/gratora/v1/admin/me/table-view' ) ) {
			return Promise.resolve( savedFields ? { fields: savedFields, order: savedFields } : {} );
		}
		if ( path.startsWith( '/gratora/v1/admin/recurring/unlinked' ) ) {
			return Promise.resolve( { total: 0, items: [], window_days: 7, can_retry: false } );
		}
		if ( path.startsWith( '/gratora/v1/admin/recurring/stats' ) ) {
			return Promise.resolve( null );
		}
		return Promise.resolve( {} );
	} );

	document.body.innerHTML = '<div id="root"></div>';
	render( <List />, document.getElementById( 'root' ) );
	await settle();

	return captured.props;
}

beforeEach( () => {
	apiFetch.mockReset();
	delete captured.props;
} );

// The words the donor picked the plan with on the form, which the receipt
// repeats. Asserting the map is non-empty would only restate the map.
const WORDING = [
	[ 'weekly',    'week',  1, 'Weekly' ],
	[ 'biweekly',  'week',  2, 'Every 2 weeks' ],
	[ 'monthly',   'month', 1, 'Monthly' ],
	[ 'quarterly', 'month', 3, 'Quarterly' ],
	[ 'yearly',    'year',  1, 'Yearly' ],
];

test.each( WORDING )( 'a %s plan (%s x%i) reads as %s', ( frequency, unit, count, expected ) => {
	expect( cadenceLabel( plan( { frequency, interval_unit: unit, interval_count: count } ) ) ).toBe( expected );
} );

it( 'covers every cadence the server can report, and no cadence it cannot', () => {
	expect( Object.keys( CADENCE_LABEL ).sort() ).toEqual( [ ...SERVER_RANGE ].sort() );
} );

it( 'counts a cadence it cannot name instead of rounding to the nearest one', () => {
	// An imported plan can sit on a pair no form offers. Calling it monthly
	// would tell the admin the card is charged on a day it is not.
	expect( cadenceLabel( plan( { frequency: null, interval_count: 6 } ) ) ).toBe( 'Every 6 months' );
	expect( cadenceLabel( plan( { frequency: null, interval_unit: 'day', interval_count: 10 } ) ) ).toBe( 'Every 10 days' );
} );

it( 'reads the cadence beside the amount on the subscriptions list', async () => {
	const props = await mountList();

	expect( cell( props.fields, 'amount', plan() ) ).toBe( '$25.00 / Monthly' );
} );

it( 'reads the same cadence beside the amount on the donor profile', async () => {
	document.body.innerHTML = '<div id="root"></div>';
	render( <RecurringTab recurring={ { plans: [ plan() ] } } onChange={ () => {} } />, document.getElementById( 'root' ) );
	await settle();

	expect( cell( captured.props.fields, 'amount', plan() ) ).toBe( '$25.00 / Monthly' );
} );

it( 'offers no Interval column, even to a reader whose saved view names one', async () => {
	const props = await mountList( [ 'id', 'donor', 'amount', 'status', 'interval' ] );

	expect( props.view.fields ).not.toContain( 'interval' );
} );

it( 'still offers the cadence as a filter', async () => {
	const props = await mountList();
	const interval = props.fields.find( ( f ) => f.id === 'interval' );

	expect( interval.elements.map( ( e ) => e.value ) ).toEqual( SERVER_RANGE );
	// dataviews draws one toggle per field and locks it when hiding is off, so
	// the column cannot be switched back on from the picker.
	expect( interval.enableHiding ).toBe( false );
} );
