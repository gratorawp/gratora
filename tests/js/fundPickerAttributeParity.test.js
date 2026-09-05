/**
 * The fund picker is declared twice: once for the editor and once for the
 * server that renders it to donors. An attribute added to one side only looks
 * fine in the editor and then renders as its PHP default on the live form, so
 * the two lists have to say the same thing.
 *
 * Read from source rather than from a render: what is being checked is that
 * somebody edited both files, and they are different languages.
 */

import fs from 'fs';
import path from 'path';

const read = ( p ) => fs.readFileSync( path.join( __dirname, '../..', p ), 'utf8' );

const JS  = read( 'assets/admin/forms/blocks/fund-picker/index.js' );
const PHP = read( 'src/Forms/Blocks/FundPickerBlock.php' );

// Handled generically for every block by ConditionPanel, so it has no server
// counterpart and is not part of this comparison.
const EDITOR_ONLY = [ 'condition' ];

const normalise = ( raw ) => {
	const v = raw.trim().replace( /,$/, '' );
	if ( v === "''" ) return '';
	if ( v === '[]' ) return '[]';
	return v;
};

function jsAttributes() {
	const block = JS.match( /attributes:\s*\{([\s\S]*?)\n\s{8}\},/ );
	expect( block ).not.toBeNull();

	const out = {};
	for ( const m of block[ 1 ].matchAll( /(\w+):\s*\{\s*type:\s*'(\w+)',\s*default:\s*([^}]+)\}/g ) ) {
		out[ m[ 1 ] ] = { type: m[ 2 ], default: normalise( m[ 3 ] ) };
	}
	return out;
}

function phpAttributes() {
	const out = {};
	for ( const m of PHP.matchAll( /'(\w+)'\s*=>\s*\['type'\s*=>\s*'(\w+)',\s*'default'\s*=>\s*(.+?)\],\s*$/gm ) ) {
		out[ m[ 1 ] ] = { type: m[ 2 ], default: normalise( m[ 3 ] ) };
	}
	return out;
}

const js  = jsAttributes();
const php = phpAttributes();

test( 'both sides were actually parsed', () => {
	expect( Object.keys( js ).length ).toBeGreaterThan( 4 );
	expect( Object.keys( php ).length ).toBeGreaterThan( 4 );
	expect( js.label ).toEqual( { type: 'string', default: '' } );
} );

test( 'every attribute the editor writes is one the server reads', () => {
	const missing = Object.keys( js )
		.filter( ( k ) => ! EDITOR_ONLY.includes( k ) )
		.filter( ( k ) => ! ( k in php ) );

	expect( missing ).toEqual( [] );
} );

test( 'every attribute the server reads is one the editor offers', () => {
	expect( Object.keys( php ).filter( ( k ) => ! ( k in js ) ) ).toEqual( [] );
} );

test( 'the two agree on type and default, so an unsaved block renders the same on both', () => {
	for ( const [ name, spec ] of Object.entries( php ) ) {
		expect( { name, ...js[ name ] } ).toEqual( { name, ...spec } );
	}
} );

test( 'descriptions are on until an org turns them off', () => {
	expect( php.showDescriptions ).toEqual( { type: 'boolean', default: 'true' } );
	expect( JS ).toContain( 'showDescriptions && ' );
} );
