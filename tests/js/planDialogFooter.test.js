/**
 * The footer carried Close and a plan action labelled Cancel, both reading as
 * a way out of the dialog, with Cancel in the rightmost slot where dismissal
 * normally sits and styled like every other button.
 */

import { render } from 'preact';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );
jest.mock( '@wordpress/api-fetch', () => jest.fn( () => new Promise( () => {} ) ) );

import PlanDetailDialog from '../../assets/admin/subscriptions/PlanDetailDialog';

const PLAN = {
	id: 39,
	status: 'active',
	gateway: 'stripe',
	gateway_subscription_id: 'demo-sub038',
	amount_cents: 2500,
	currency: 'EUR',
	interval_unit: 'month',
	interval_count: 1,
	donor: { id: 7, name: 'Sam' },
	errors: [],
};

function mount( onClose = () => {}, onAction = () => {} ) {
	document.body.innerHTML = '<div id="root"></div>';
	render(
		<PlanDetailDialog plan={ PLAN } onClose={ onClose } onAction={ onAction } />,
		document.getElementById( 'root' )
	);

	return document.body;
}

test( 'no button says only Cancel, which reads as dismissing the dialog', () => {
	const labels = [ ...mount().querySelectorAll( '.fundkit-dialog__foot button' ) ]
		.map( ( b ) => b.textContent.trim() );

	expect( labels ).toContain( 'Close' );
	expect( labels ).not.toContain( 'Cancel' );
	expect( labels ).toContain( 'Cancel subscription' );
} );

test( 'the destructive action does not look like the rest', () => {
	const body = mount();
	const danger = [ ...body.querySelectorAll( '.fundkit-dialog__foot button' ) ]
		.filter( ( b ) => b.className.includes( 'fundkit-btn--danger' ) );

	expect( danger ).toHaveLength( 1 );
	expect( danger[ 0 ].textContent.trim() ).toBe( 'Cancel subscription' );
} );

test( 'Close closes and starts no action', () => {
	let closed = 0;
	let acted = null;
	const body = mount( () => { closed++; }, ( a ) => { acted = a; } );

	const close = [ ...body.querySelectorAll( '.fundkit-dialog__foot button' ) ]
		.find( ( b ) => b.textContent.trim() === 'Close' );
	close.click();

	expect( closed ).toBe( 1 );
	expect( acted ).toBeNull();
} );

test( 'the title names the plan as an identifier', () => {
	expect( mount().textContent ).toContain( 'Subscription #39' );
} );
