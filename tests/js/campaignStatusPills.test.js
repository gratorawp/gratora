/**
 * The status control offers the statuses a campaign can actually be put into.
 *
 * STATUS_LABEL carries three more, because the campaigns list filters by them:
 * scheduled, ended and goal_met are derived on the server from the dates and
 * the goal. Offered here they did not fail loudly. CampaignService::coerceStatus
 * accepts draft, published and archived and quietly returns draft for anything
 * else, so picking "Ended" on a live campaign sent it back to draft and stopped
 * it taking donations.
 */

import { render } from 'preact';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );
jest.mock( '@wordpress/api-fetch', () => jest.fn( () => new Promise( () => {} ) ) );

import { StatusPillGroup } from '../../assets/admin/campaigns/Detail';

function mount( value ) {
	document.body.innerHTML = '<div id="root"></div>';
	const host = document.getElementById( 'root' );
	const onChange = jest.fn();

	render( <StatusPillGroup value={ value } onChange={ onChange } />, host );

	return {
		onChange,
		states: [ ...host.querySelectorAll( '[data-state]' ) ].map( ( b ) => b.dataset.state ),
	};
}

describe( 'the campaign status control', () => {
	it( 'offers the statuses a campaign can be put into', () => {
		expect( mount( 'published' ).states ).toEqual( [ 'draft', 'published' ] );
	} );

	/**
	 * The bug this pins: these are computed from the dates and the goal, and the
	 * writer coerces them to draft.
	 */
	it( 'does not offer a status that is derived rather than stored', () => {
		const { states } = mount( 'published' );

		expect( states ).not.toContain( 'ended' );
		expect( states ).not.toContain( 'scheduled' );
		expect( states ).not.toContain( 'goal_met' );
	} );

	/**
	 * Archiving asks the server about live recurring donations first, so it runs
	 * from the campaign menu. The pill shows the state without offering the jump.
	 */
	it( 'hides archive, which has its own flow', () => {
		expect( mount( 'draft' ).states ).not.toContain( 'archived' );
	} );

	it( 'still shows archived when the campaign already is', () => {
		expect( mount( 'archived' ).states ).toEqual( [ 'draft', 'published', 'archived' ] );
	} );

	it( 'reports the status that was picked', () => {
		const { onChange, states } = mount( 'draft' );
		expect( states ).toContain( 'published' );

		document.querySelector( '[data-state="published"]' ).click();

		expect( onChange ).toHaveBeenCalledWith( 'published' );
	} );
} );
