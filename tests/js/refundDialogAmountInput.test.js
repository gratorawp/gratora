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

import AmountInput from '../../assets/admin/_shared/components/AmountInput';

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
