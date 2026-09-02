/**
 * KPI cards show counts as plain integers.
 *
 * The strips disagreed: the donors Insights tab rendered 5141 while the donors
 * List tab rendered 5,141 for the same figure, and subscriptions and funds were
 * already plain. Money is not part of this: amounts keep their grouping, which
 * is what tells a reader they are money and not a count.
 */

import { donorKpis } from '../../assets/admin/donors/index';
import { donationKpis } from '../../assets/admin/donations/List';
import { campaignKpis } from '../../assets/admin/campaigns/List';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );
jest.mock( '@wordpress/api-fetch', () => jest.fn( () => new Promise( () => {} ) ) );

const valueFor = ( items, label ) => items.find( ( i ) => i.label === label )?.value;

describe( 'KPI counts', () => {
	it( 'shows donor counts without a thousands separator', () => {
		const items = donorKpis( {
			total_count: 5141, with_donations: 5132,
			total_donated_cents: 3268615000, avg_ltv_cents: 635794,
		} );

		expect( valueFor( items, 'Total donors' ) ).toBe( '5141' );
		expect( valueFor( items, 'With donations' ) ).toBe( '5132' );
	} );

	it( 'shows donation counts without a thousands separator', () => {
		const items = donationKpis( { total_count: 100813, paid_count: 88776, donors_count: 5132, raised_cents: 1639318000 } );

		expect( valueFor( items, 'Total donations' ) ).toBe( '100813' );
		expect( valueFor( items, 'Paid' ) ).toBe( '88776' );
		expect( valueFor( items, 'Unique donors' ) ).toBe( '5132' );
	} );

	it( 'shows campaign counts without a thousands separator', () => {
		const items = campaignKpis( { total_count: 1200, active_count: 1050, donations_count: 100813, raised_cents: 0 } );

		expect( valueFor( items, 'Total' ) ).toBe( '1200' );
		expect( valueFor( items, 'Active' ) ).toBe( '1050' );
		expect( valueFor( items, 'Donations' ) ).toBe( '100813' );
	} );

	/**
	 * The distinction the change rests on: grouping is what marks a figure as
	 * money, so taking it off counts must not take it off amounts.
	 */
	it( 'keeps the separator on money', () => {
		const raised = valueFor( donationKpis( { total_count: 1, paid_count: 1, donors_count: 1, raised_cents: 1639318000 } ), 'Raised' );

		expect( raised ).toMatch( /16,393,180/ );
	} );
} );
