/**
 * The refund amount is money, and the admin types it into the same control
 * every other money field in the admin uses. It was a bare number input, which
 * printed 102900.30 where the rest of the screen says $102,900.30, and let an
 * admin type past the refundable maximum with nothing said until submit.
 */

import { render } from 'preact';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
// No test in this suite mounts a real Modal; the dialog's own markup is what
// is being driven.
jest.mock( '@wordpress/components', () => ( {
	__esModule: true,
	Modal: ( { children } ) => <div>{ children }</div>,
} ) );

import apiFetch from '@wordpress/api-fetch';

import AmountInput from '../../assets/admin/_shared/components/AmountInput';
import RefundDialog from '../../assets/admin/donations/detail/RefundDialog';

const MAX = 102900.30;

function mount( props = {} ) {
	document.body.innerHTML = '<div id="root"></div>';
	const host = document.getElementById( 'root' );
	const onChange = jest.fn();

	render(
		<AmountInput value={ MAX } onChange={ onChange } currency="USD" min={ 0.01 } max={ MAX } { ...props } />,
		host
	);

	return { host, onChange, input: host.querySelector( 'input' ) };
}

describe( 'the control the refund amount is typed into', () => {
	it( 'shows the amount grouped, the way the rest of the screen writes money', () => {
		const { input } = mount();

		expect( input.value ).toBe( '102,900.30' );
	} );

	it( 'names the currency beside it rather than leaving a bare number', () => {
		const { host } = mount();

		expect( host.textContent ).toContain( '$' );
	} );

	it( 'is a text field, so the grouping is not stripped by the browser', () => {
		const { input } = mount();

		expect( input.getAttribute( 'type' ) ).toBe( 'text' );
	} );

	it( 'refuses to report more than can be refunded', () => {
		const { input, onChange } = mount();

		input.value = '999999';
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );

		expect( onChange ).toHaveBeenCalledWith( MAX );
	} );

	it( 'reports a plain number, which is what the request is built from', () => {
		const { input, onChange } = mount();

		input.value = '25.50';
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );

		expect( onChange ).toHaveBeenCalledWith( 25.5 );
		expect( Math.round( 25.5 * 100 ) ).toBe( 2550 );
	} );
} );

/**
 * The box and the request have to agree. Blur formats with toFixed and submit
 * rounds with Math.round, and the two disagree on a tie: 2.675 settled the box
 * to 2.67 while the request carried 268, so the screen said one number and the
 * gateway moved another. In a three-decimal currency the box also offered a
 * decimal the cents column cannot hold.
 */
describe( 'the refund dialog, as an admin drives it', () => {
	const donation = ( currency ) => ( {
		reference:        'REF-1',
		currency,
		refundable_cents: 500000,
	} );

	function open( currency ) {
		document.body.innerHTML = '<div id="root"></div>';
		const host = document.getElementById( 'root' );

		render(
			<RefundDialog donation={ donation( currency ) } onClose={ () => {} } onSuccess={ () => {} } />,
			host
		);

		return { host, input: host.querySelector( '.fundkit-amount__input' ) };
	}

	// Preact renders on a later tick, so the dialog holds the old amount until
	// this has run.
	async function settle() {
		for ( let i = 0; i < 5; i++ ) {
			await new Promise( ( r ) => requestAnimationFrame( () => r() ) );
			await new Promise( ( r ) => setTimeout( r, 0 ) );
		}
	}

	async function type( input, text ) {
		input.dispatchEvent( new Event( 'focus', { bubbles: true } ) );
		input.value = text;
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		await settle();
		input.dispatchEvent( new Event( 'blur', { bubbles: true } ) );
		await settle();
	}

	const submit = async ( host ) => {
		host.querySelector( 'form' ).dispatchEvent( new Event( 'submit', { bubbles: true, cancelable: true } ) );
		await settle();
	};

	beforeEach( () => {
		apiFetch.mockClear();
		apiFetch.mockResolvedValue( {} );
	} );

	it( 'sends the number it settles on', async () => {
		const { host, input } = open( 'USD' );

		await type( input, '2.675' );
		await submit( host );

		expect( input.value ).toBe( '2.68' );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].data.amount_cents ).toBe( 268 );
	} );

	it( 'offers no more precision than the cents column holds', async () => {
		const { input } = open( 'KWD' );

		await type( input, '12.345' );

		expect( input.value ).toBe( '12.35' );
	} );

	it( 'refuses an empty box rather than quietly refunding the minimum', async () => {
		const { host, input } = open( 'USD' );

		await type( input, '' );
		await submit( host );

		expect( apiFetch ).not.toHaveBeenCalled();
		expect( document.body.textContent ).toContain( 'Amount must be between' );
	} );
} );
