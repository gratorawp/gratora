/**
 * A campaign with no goal is an ordinary state: goal_cents is null, goalMet()
 * reads false, and the progress widget stands down. The settings panel had no
 * way to ask for it. The dropdown offered the three ways of MEASURING a goal
 * and nothing else, so a campaign that had one could never be talked out of it.
 */

import { render } from 'preact';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

jest.mock( '@wordpress/api-fetch', () => jest.fn( () => new Promise( () => {} ) ) );

import { GoalPanel } from '../../assets/admin/campaigns/Detail';

function mount( record = {} ) {
	document.body.innerHTML = '<div id="root"></div>';
	const host = document.getElementById( 'root' );
	const edit = jest.fn();

	const c = {
		record: { id: 3, currency: 'USD', goal_type: 'amount', goal_cents: 4500000, goal_count: null, ...record },
		edits: {},
		edit,
		isEdited: () => false,
		bindNumber: ( k ) => ( { value: '', onChange: () => {} } ),
	};

	render( <GoalPanel c={ c } />, host );

	return { host, edit, select: host.querySelector( 'select' ) };
}

const choose = async ( select, value ) => {
	select.value = value;
	select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	await new Promise( ( r ) => setTimeout( r, 0 ) );
};

describe( 'the campaign goal panel', () => {
	it( 'offers no goal alongside the ways of measuring one', () => {
		const { select } = mount();

		expect( [ ...select.options ].map( ( o ) => o.value ) ).toEqual( [ 'none', 'amount', 'donations', 'donors' ] );
	} );

	it( 'starts on the measurement type when a target is set', () => {
		const { select, host } = mount();

		expect( select.value ).toBe( 'amount' );
		expect( host.querySelector( 'input' ) ).not.toBeNull();
	} );

	it( 'reads a campaign with no target as having no goal', () => {
		const { select } = mount( { goal_cents: null } );

		expect( select.value ).toBe( 'none' );
	} );

	it( 'clears both targets when no goal is chosen', async () => {
		const { select, edit } = mount();

		await choose( select, 'none' );

		expect( edit ).toHaveBeenCalledWith( { goal_cents: null, goal_count: null } );
	} );

	it( 'takes the target field away once there is no goal to set', async () => {
		const { select, host } = mount();

		await choose( select, 'none' );

		expect( host.querySelector( 'input[type="text"], input[type="number"]' ) ).toBeNull();
	} );

	it( 'keeps the close-on-goal toggle off the table with no target to reach', async () => {
		const { host, select } = mount();
		await choose( select, 'none' );

		expect( host.textContent ).toContain( 'Set a target above first' );
	} );

	/**
	 * The reason the choice is held rather than derived from the target: deriving
	 * it meant clearing the field to retype an amount silently reclassified the
	 * campaign as having no goal and took the field away mid-keystroke.
	 */
	it( 'stays on the measurement type while the target sits empty', async () => {
		const { select, host } = mount( { goal_cents: null } );

		await choose( select, 'amount' );
		expect( select.value ).toBe( 'amount' );
		expect( host.querySelector( 'input' ) ).not.toBeNull();
	} );

	it( 'switches measurement without clearing anything', async () => {
		const { select, edit } = mount();

		await choose( select, 'donors' );

		expect( edit ).toHaveBeenCalledWith( { goal_type: 'donors' } );
	} );
} );
