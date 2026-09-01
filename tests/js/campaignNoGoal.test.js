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

const SAVED = { id: 3, currency: 'USD', goal_type: 'amount', goal_cents: 4500000, goal_count: null };

function mount( overrides = {} ) {
	document.body.innerHTML = '<div id="root"></div>';
	const host = document.getElementById( 'root' );

	const saved = { ...SAVED, ...overrides };
	const c = {
		record: { ...saved },
		savedRecord: saved,
		edits: {},
		isEdited: ( k ) => c.edits[ k ] !== undefined,
		bindNumber: () => ( { value: '', onChange: () => {} } ),
		// The hook hands the panel a merged view, so an edit lands in c.record.
		edit: jest.fn( ( patch ) => {
			Object.assign( c.record, patch );
			Object.assign( c.edits, patch );
			draw();
		} ),
	};

	const draw = () => render( <GoalPanel c={ c } />, host );
	draw();

	return {
		host,
		edit: c.edit,
		select: () => host.querySelector( 'select' ),
		card: () => host.textContent,
		targetField: () => host.querySelector( 'input[type="text"], input[type="number"]' ),
		// Discard drops the edits; the merged view goes back to what was saved.
		discard: async () => {
			c.record = { ...saved };
			c.edits = {};
			draw();
			await settle();
		},
	};
}

const settle = () => new Promise( ( r ) => setTimeout( r, 0 ) );

const choose = async ( select, value ) => {
	const el = select();
	el.value = value;
	el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	await settle();
};

describe( 'the campaign goal panel', () => {
	it( 'offers no goal alongside the ways of measuring one', () => {
		const { select } = mount();

		expect( [ ...select().options ].map( ( o ) => o.value ) )
			.toEqual( [ 'none', 'amount', 'donations', 'donors' ] );
	} );

	it( 'starts on the measurement type when a target is set', () => {
		const { select, targetField } = mount();

		expect( select().value ).toBe( 'amount' );
		expect( targetField() ).not.toBeNull();
	} );

	it( 'reads a campaign with no target as having no goal', () => {
		const { select } = mount( { goal_cents: null } );

		expect( select().value ).toBe( 'none' );
	} );

	it( 'clears both targets when no goal is chosen', async () => {
		const { select, edit } = mount();

		await choose( select, 'none' );

		expect( edit ).toHaveBeenCalledWith( { goal_cents: null, goal_count: null } );
	} );

	it( 'takes the target field away once there is no goal to set', async () => {
		const { select, targetField } = mount();

		await choose( select, 'none' );

		expect( targetField() ).toBeNull();
	} );

	it( 'drops the close-on-goal toggle entirely when there is no goal', async () => {
		const { select, card } = mount();

		await choose( select, 'none' );

		expect( card() ).not.toContain( 'Close when the goal is met' );
	} );

	/**
	 * Different case: a measurement type is chosen and the target is still
	 * blank. The intent to have a goal exists, so the toggle stays and says
	 * what is missing.
	 */
	it( 'keeps the toggle, disabled, while a chosen target sits empty', async () => {
		const { select, card } = mount( { goal_cents: null } );

		await choose( select, 'amount' );

		expect( card() ).toContain( 'Set a target above first' );
	} );

	it( 'switches measurement without clearing anything', async () => {
		const { select, edit } = mount();

		await choose( select, 'donors' );

		expect( edit ).toHaveBeenCalledWith( { goal_type: 'donors' } );
	} );

	/**
	 * Why the choice overrides the stored state at all: without it, clearing
	 * the field to retype an amount reclassified the campaign as having no goal
	 * and took the field away mid-keystroke.
	 */
	it( 'stays on the measurement type while its target sits empty', async () => {
		const { select, targetField } = mount( { goal_cents: null } );

		await choose( select, 'amount' );

		expect( select().value ).toBe( 'amount' );
		expect( targetField() ).not.toBeNull();
	} );

	/**
	 * ...and why it only overrides in that one direction: a discarded edit has
	 * to put the panel back on the goal the campaign still has.
	 */
	it( 'goes back to the saved goal when the edit is discarded', async () => {
		const { select, card, discard } = mount();

		await choose( select, 'none' );
		await discard();

		expect( select().value ).toBe( 'amount' );
		expect( card() ).toContain( 'Close when the goal is met' );
	} );
} );
