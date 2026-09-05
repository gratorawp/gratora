/**
 * wp-admin ships `input[type="text"] { border-radius: 2px; border: 1px solid
 * #949494 }`, which outweighs a single class. The form title is an input, so
 * styling it by class alone leaves it wearing core's square grey box in the
 * middle of a header built from our own tokens.
 *
 * Two halves have to agree: the selector needs the attribute, and the element
 * has to keep the type that selector matches.
 */

import fs from 'fs';
import path from 'path';

const read = ( p ) => fs.readFileSync( path.join( __dirname, '../../assets/admin/forms', p ), 'utf8' );

const SCSS = read( 'editor.scss' );
const JSX  = read( 'Editor.jsx' );

test( 'the title rule outranks core rather than tying with it', () => {
	expect( SCSS ).toContain( '&__title[type="text"]' );
} );

test( 'the input still carries the type that selector matches', () => {
	const el = JSX.match( /<input[\s\S]{0,80}?className="fundkit-editor-header__title"[\s\S]{0,400}?\/>/ );

	expect( el ).not.toBeNull();
	expect( el[ 0 ] ).toContain( 'type="text"' );
} );

test( 'the height is ours too, since core sets a 40px floor', () => {
	const rule = SCSS.match( /&__title\[type="text"\] \{[\s\S]*?\n    \}/ );

	expect( rule ).not.toBeNull();
	const min = rule[ 0 ].match( /min-height:\s*(\d+)px/ );
	expect( min ).not.toBeNull();
	expect( Number( min[ 1 ] ) ).toBeLessThan( 40 );
} );

test( 'the corners are ours, not core\'s', () => {
	const rule = SCSS.match( /&__title\[type="text"\] \{[\s\S]*?\n    \}/ );

	expect( rule ).not.toBeNull();
	const radius = rule[ 0 ].match( /border-radius:\s*(\d+)px/ );
	expect( radius ).not.toBeNull();
	expect( Number( radius[ 1 ] ) ).toBeGreaterThan( 2 );
} );
