/**
 * Four places a donation could be lost between the donor deciding to give and
 * the money arriving.
 */

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

import { render } from 'preact';

import CountrySelect from '../../assets/donation-form/components/CountrySelect';
import { countryName, localizedCountries } from '../../assets/_shared/countries';
import { keepGatewayValid } from '../../assets/donation-form/util/gateways';

const tick = () => new Promise( ( r ) => setTimeout( r, 0 ) );

function mount( node ) {
	document.body.innerHTML = '<div id="root"></div>';
	render( node, document.getElementById( 'root' ) );
}

const input = () => document.querySelector( '.gratora-form__country-select-input' );
const list  = () => document.querySelector( '.gratora-form__country-select-list' );
const options = () => [ ...document.querySelectorAll( '.gratora-form__country-select-label' ) ]
	.map( ( el ) => el.textContent );

// Plain preact listens for blur; compat delegates to focusout. Both, so the
// test does not depend on which runtime the component was built against.
async function leave() {
	input().dispatchEvent( new Event( 'blur', { bubbles: true } ) );
	input().dispatchEvent( new Event( 'focusout', { bubbles: true } ) );
	await tick();
}

async function type( text ) {
	input().dispatchEvent( new Event( 'focus', { bubbles: true } ) );
	await tick();
	input().value = text;
	input().dispatchEvent( new Event( 'input', { bubbles: true } ) );
	await tick();
}

describe( 'the country picker gets out of the way', () => {
	test( 'leaving the field closes the list', async () => {
		mount( <CountrySelect value="" onChange={ () => {} } id="c" /> );
		await type( 'Germ' );

		expect( list() ).toBeTruthy();

		await leave();

		expect( list() ).toBeNull();
	} );

	test( 'and stops showing a half-typed search as though a country were chosen', async () => {
		mount( <CountrySelect value="" onChange={ () => {} } id="c" /> );
		await type( 'Germ' );

		await leave();

		expect( input().value ).toBe( '' );
	} );

	/**
	 * The form focuses whichever field failed validation, and the panel that
	 * opened there covered the message saying what was wrong.
	 */
	test( 'a focus the form moved does not open the list', async () => {
		mount( <CountrySelect value="" onChange={ () => {} } id="c" /> );

		input().focus();
		input().dispatchEvent( new Event( 'focus', { bubbles: true } ) );
		await tick();

		expect( list() ).toBeNull();
		expect( input().getAttribute( 'aria-expanded' ) ).toBe( 'false' );
	} );

	test( 'clicking the field opens it', async () => {
		mount( <CountrySelect value="" onChange={ () => {} } id="c" /> );

		input().dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
		await tick();

		expect( list() ).toBeTruthy();
	} );

	test( 'and an arrow opens it from the keyboard', async () => {
		mount( <CountrySelect value="" onChange={ () => {} } id="c" /> );

		input().dispatchEvent( new Event( 'focus', { bubbles: true } ) );
		await tick();
		input().dispatchEvent( new KeyboardEvent( 'keydown', { key: 'ArrowDown', bubbles: true } ) );
		await tick();

		expect( list() ).toBeTruthy();
	} );

	/**
	 * The donor form wraps this in a bare label, which forwards a click on any
	 * non-interactive descendant, an option included, on to the input.
	 */
	test( 'picking a country inside a label does not reopen the list', async () => {
		const picked = [];
		mount( <label><CountrySelect value="" onChange={ ( c ) => picked.push( c ) } id="c" /></label> );

		await type( 'Germ' );
		expect( list() ).toBeTruthy();

		document.querySelector( '.gratora-form__country-select-option' ).click();
		await tick();

		expect( picked ).toEqual( [ 'DE' ] );
		expect( list() ).toBeNull();
	} );

	test( 'a chosen country still shows after the field is left', async () => {
		mount( <CountrySelect value="DE" onChange={ () => {} } id="c" /> );
		await leave();

		expect( input().value ).toBe( countryName( 'DE' ) );
	} );
} );

describe( 'a donor can find their own country', () => {
	afterEach( () => { document.documentElement.lang = ''; } );

	test( 'the list is in the language of the page', () => {
		document.documentElement.lang = 'de';

		expect( countryName( 'DE' ) ).toBe( 'Deutschland' );
	} );

	test( 'and searching in that language finds it', async () => {
		document.documentElement.lang = 'de';

		mount( <CountrySelect value="" onChange={ () => {} } id="c" /> );
		await type( 'Deutsch' );

		expect( options() ).toContain( 'Deutschland' );
	} );

	test( 'the English name and the code still match, for a donor who knows those', async () => {
		mount( <CountrySelect value="" onChange={ () => {} } id="c" /> );

		await type( 'Germany' );
		expect( options().length ).toBeGreaterThan( 0 );

		await type( 'DE' );
		expect( options().length ).toBeGreaterThan( 0 );
	} );

	test( 'every country still has a label', () => {
		const all = localizedCountries();

		expect( all.length ).toBeGreaterThan( 200 );
		expect( all.every( ( c ) => typeof c.label === 'string' && c.label !== '' ) ).toBe( true );
	} );
} );

describe( 'the selected gateway stays one the donor can be charged through', () => {
	const config = {
		gateways: {
			options: [
				{ id: 'offline', currencies: [ 'USD' ], frequencies: [ 'one_time' ] },
				{ id: 'stripe',  currencies: [ 'USD' ], frequencies: [ 'one_time', 'recurring' ] },
			],
		},
	};

	test( 'switching to a frequency the current gateway cannot take moves off it', () => {
		const sent = [];
		// The donor walked past the gateway block on page one, so nothing on
		// screen owns this choice any more.
		keepGatewayValid(
			config,
			{ gateway: 'offline', currency: 'USD', values: { frequency: 'monthly' } },
			( a ) => sent.push( a )
		);

		expect( sent ).toEqual( [ { type: 'SET_GATEWAY', gateway: 'stripe' } ] );
	} );

	test( 'a gateway that is still offered is left alone', () => {
		const sent = [];
		keepGatewayValid(
			config,
			{ gateway: 'offline', currency: 'USD', values: { frequency: 'one-time' } },
			( a ) => sent.push( a )
		);

		expect( sent ).toEqual( [] );
	} );

	test( 'with nothing to offer it changes nothing, so the empty-state message stands', () => {
		const sent = [];
		keepGatewayValid(
			config,
			{ gateway: 'offline', currency: 'JPY', values: { frequency: 'monthly' } },
			( a ) => sent.push( a )
		);

		expect( sent ).toEqual( [] );
	} );
} );
