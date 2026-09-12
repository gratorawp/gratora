/**
 * The two numbers in this panel decide what a donor is charged when they tick
 * "cover the fees". Both were controlled input[type=number] fields recomputed
 * from their attribute on every keystroke, and an input[type=number] reports an
 * empty value for anything partly typed, so pressing the decimal point set the
 * attribute to zero and React rewrote the box under the caret: 2.9 saved as 9.
 *
 * Driven as keystrokes, because that is the only way this fails.
 */

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );
// The condition panel reads the block-editor data store, which is not what is
// under test here and needs the whole editor to exist.
jest.mock( '../../assets/admin/forms/blocks/_shared/condition', () => ( {
	DEFAULT_CONDITION: { enabled: false },
	ConditionPanel: () => null,
} ) );
// @wordpress/components does not load under this repo's preact alias, so the
// two controls are stubbed down to the real DOM elements they render. What is
// under test is the draft handling between a keystroke and the render that
// follows it, which is where the numbers were being destroyed. That the fields
// are no longer controlled input[type=number] is asserted from source below,
// since a type=number input sanitises its own value and no stub can show that.
jest.mock( '@wordpress/components', () => {
	return {
		PanelBody: ( { children } ) => children,
		ToggleControl: () => null,
		TextControl: ( { label, value, onChange, onBlur, inputMode } ) => {
			const id = `f-${ label.replace( /\W+/g, '' ) }`;
			return (
				<div>
					<label htmlFor={ id }>{ label }</label>
					<input
						id={ id }
						inputMode={ inputMode }
						value={ value }
						onInput={ ( e ) => onChange( e.target.value ) }
						onBlur={ onBlur }
					/>
				</div>
			);
		},
	};
} );
jest.mock( '@wordpress/block-editor', () => ( {
	useBlockProps: Object.assign( () => ( {} ), { save: () => ( {} ) } ),
	InspectorControls: ( { children } ) => children,
	RichText: () => null,
} ) );

import { render } from 'preact';
import { useState } from '@wordpress/element';
import register from '../../assets/admin/forms/blocks/cover-fees';

let Edit;
let defaults;

register( {
	register: ( name, def ) => {
		Edit = def.edit;
		defaults = Object.fromEntries(
			Object.entries( def.attributes ).map( ( [ k, v ] ) => [ k, v.default ] )
		);
	},
} );

// The real editor holds the attributes and re-renders on setAttributes, so the
// harness has to as well: a controlled field's whole behaviour is what it does
// between a keystroke and the render that follows it.
let attributes;

function Host( { initial } ) {
	const [ attrs, setAttrs ] = useState( initial );
	attributes = attrs;

	return <Edit attributes={ attrs } setAttributes={ ( patch ) => setAttrs( ( a ) => ( { ...a, ...patch } ) ) } />;
}

const tick = () => new Promise( ( r ) => setTimeout( r, 0 ) );

function mount( over = {} ) {
	document.body.innerHTML = '<div id="root"></div>';
	render( <Host initial={ { ...defaults, percent: 2.9, fixed: 30, ...over } } />, document.getElementById( 'root' ) );
}

const fieldFor = ( labelText ) => {
	const label = [ ...document.querySelectorAll( 'label' ) ]
		.find( ( l ) => l.textContent.trim() === labelText );
	expect( label ).toBeTruthy();
	return document.getElementById( label.getAttribute( 'for' ) );
};

// One character at a time, the way a person enters a number.
async function typeInto( labelText, text ) {
	let input = fieldFor( labelText );
	input.dispatchEvent( new Event( 'focus', { bubbles: true } ) );

	let sofar = '';
	for ( const ch of text ) {
		sofar += ch;
		input = fieldFor( labelText );
		input.value = sofar;
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		// eslint-disable-next-line no-await-in-loop
		await tick();
	}
}

// preact/compat delegates onBlur to focusout, which is what a browser fires
// alongside blur. Dispatching only blur reaches no handler.
async function blur( labelText ) {
	fieldFor( labelText ).dispatchEvent( new Event( 'focusout', { bubbles: true } ) );
	await tick();
}

test( 'a percent typed with a decimal point is the percent that is saved', async () => {
	mount();
	await typeInto( 'Percent fee', '1.75' );

	expect( attributes.percent ).toBe( 1.75 );
} );

test( 'the decimal point does not wipe what came before it', async () => {
	mount();
	await typeInto( 'Percent fee', '2.' );

	// The half-typed number still reads as 2, not 0, and the box still holds
	// what was typed rather than being rewritten to the parsed value.
	expect( attributes.percent ).toBe( 2 );
	expect( fieldFor( 'Percent fee' ).value ).toBe( '2.' );
} );

test( 'a fixed fee typed in major units is stored in minor units', async () => {
	mount();
	await typeInto( 'Fixed fee', '0.30' );

	expect( attributes.fixed ).toBe( 30 );
} );

test( 'a fixed fee whose digits arrive after the point is not rounded off midway', async () => {
	mount();
	await typeInto( 'Fixed fee', '0.25' );

	expect( attributes.fixed ).toBe( 25 );
} );

test( 'the resting display is normalised once the field is left', async () => {
	mount();
	await typeInto( 'Fixed fee', '1.5' );
	await blur( 'Fixed fee' );

	expect( attributes.fixed ).toBe( 150 );
	expect( fieldFor( 'Fixed fee' ).value ).toBe( '1.50' );
} );

test( 'clearing a field reads as zero rather than as a stale number', async () => {
	mount();
	await typeInto( 'Percent fee', '' );
	const input = fieldFor( 'Percent fee' );
	input.value = '';
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	await tick();

	expect( attributes.percent ).toBe( 0 );
} );
