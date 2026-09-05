/**
 * Changing how often a donor is charged. Most processors mint a mandate
 * against a fixed cadence and cannot move it, so the action is offered from
 * the row's capability rather than from its status.
 */

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );
jest.mock( '@wordpress/api-fetch', () => jest.fn( () => new Promise( () => {} ) ) );

import { actionsFor } from '../../assets/admin/_shared/recurring/PlanActions';

const PLAN = {
	id: 38,
	status: 'active',
	amount_cents: 2500,
	currency: 'EUR',
	frequency: 'monthly',
	frequency_options: [ 'weekly', 'biweekly', 'monthly', 'quarterly', 'yearly' ],
};

const ids = ( plan ) => actionsFor( plan ).map( ( a ) => a.id );

test( 'a processor that can move a cadence is offered the action', () => {
	expect( ids( { ...PLAN, can_change_interval: true } ) ).toContain( 'change_interval' );
} );

test( 'one that cannot is not offered it at all', () => {
	expect( ids( { ...PLAN, can_change_interval: false } ) ).not.toContain( 'change_interval' );
	expect( ids( PLAN ) ).not.toContain( 'change_interval' );
} );

test( 'a finished plan is offered nothing, cadence included', () => {
	expect( ids( { ...PLAN, status: 'cancelled', can_change_interval: true } ) ).toEqual( [] );
} );

test( 'it sits with the other changes, not among the destructive ones', () => {
	const list = actionsFor( { ...PLAN, can_change_interval: true } );
	const change = list.find( ( a ) => a.id === 'change_interval' );
	const cancel = list.find( ( a ) => a.id === 'cancel' );

	expect( change.destructive ).toBeUndefined();
	expect( list.indexOf( change ) ).toBeLessThan( list.indexOf( cancel ) );
} );

test( 'a paused plan can still be re-scheduled', () => {
	expect( ids( { ...PLAN, status: 'paused', can_change_interval: true } ) ).toContain( 'change_interval' );
} );
