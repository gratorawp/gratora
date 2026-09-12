/**
 * Four admin screens where the control on screen did not do what it looked
 * like it did.
 */

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

import { render } from 'preact';

import ActivityTab from '../../assets/admin/donors/profile/tabs/ActivityTab';

const plan = ( over = {} ) => ( {
	id: 1,
	status: 'active',
	amount_cents: 2500,
	currency: 'USD',
	interval_unit: 'week',
	interval_count: 2,
	frequency: 'biweekly',
	next_payment_at: '2026-10-01 09:00:00',
	...over,
} );

function screen( recurring ) {
	document.body.innerHTML = '<div id="root"></div>';
	render(
		<ActivityTab
			donations={ [] }
			events={ [] }
			eventsTotal={ 0 }
			campaigns={ [] }
			recurring={ { plans: recurring } }
			donationsTotal={ 0 }
			onAllDonations={ () => {} }
			onSeeAllActivity={ () => {} }
		/>,
		document.getElementById( 'root' )
	);

	return document.getElementById( 'root' ).textContent;
}

describe( 'the plan card names the cadence the donor is actually on', () => {
	// A card that names the unit alone files a fortnightly plan under weekly and
	// a quarterly one under monthly, which is a wrong charge date either way.
	test( 'a fortnightly plan does not read as weekly', () => {
		const card = screen( [ plan() ] );

		expect( card ).toContain( 'Every 2 weeks' );
		expect( card ).not.toContain( 'Weekly' );
	} );

	test( 'a quarterly plan does not read as monthly', () => {
		const card = screen( [ plan( { interval_unit: 'month', interval_count: 3, frequency: 'quarterly' } ) ] );

		expect( card ).toContain( 'Quarterly' );
		expect( card ).not.toContain( 'Monthly' );
	} );

	test( 'an ordinary monthly plan reads as monthly', () => {
		expect( screen( [ plan( { interval_unit: 'month', interval_count: 1, frequency: 'monthly' } ) ] ) ).toContain( 'Monthly' );
	} );
} );
