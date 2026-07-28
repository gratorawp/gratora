// Caps a comment at MAX_SENTENCES. Counts sentences, not wrapped lines: one
// dense invariant is fine, a paragraph of narration is not.
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

const MAX_SENTENCES = 3;
const MAX_LINES = 8; // run-on guard: one sentence should not fill a screen
const EXT = /\.(php|jsx?|mjs|scss)$/;

const isComment = ( line ) => {
	const t = line.trim();

	return (
		t.startsWith( '//' ) ||
		t.startsWith( '/*' ) ||
		t.startsWith( '*' ) ||
		t.startsWith( '*/' ) ||
		( t.startsWith( '#' ) && ! t.startsWith( '#!' ) )
	);
};

const strip = ( line ) => line.trim().replace( /^(\/\*\*?|\*\/|\*|\/\/|#)\s?/, '' ).trim();

// A block of `Key: value` lines is metadata a machine reads (the WP plugin
// header, @wordpress/scripts directives), not prose. Never rewrite it.
const isMetadata = ( block ) => {
	const prose = block.map( strip ).filter( ( t ) => t !== '' && t !== '/**' && t !== '*/' );

	return prose.length > 0 && prose.filter( ( t ) => /^[A-Z][\w .-]*:\s/.test( t ) ).length >= prose.length / 2;
};

// @param/@return and friends are annotations, not prose. Only prose is capped.
const isProse = ( line ) => {
	const t = line.trim().replace( /^(\/\*\*?|\*\/|\*|\/\/|#)\s?/, '' ).trim();

	return t !== '' && ! t.startsWith( '@' );
};

function offences( file ) {
	const lines = readFileSync( file, 'utf8' ).split( '\n' );
	const out = [];
	let start = -1;
	let block = [];

	const flush = () => {
		if ( isMetadata( block ) ) {
			block = [];
			return;
		}
		const prose = block.filter( isProse );
		const text = prose.map( ( l ) => l.trim().replace( /^(\/\*\*?|\*\/|\*|\/\/|#)\s?/, '' ) ).join( ' ' );
		const sentences = ( text.match( /[.!?](\s|$)/g ) || [] ).length || ( text ? 1 : 0 );

		if ( sentences > MAX_SENTENCES ) {
			out.push( { line: start + 1, what: `${ sentences } sentences (max ${ MAX_SENTENCES })` } );
		} else if ( prose.length > MAX_LINES ) {
			out.push( { line: start + 1, what: `${ prose.length } lines in one sentence (max ${ MAX_LINES })` } );
		}
		block = [];
	};

	lines.forEach( ( line, i ) => {
		if ( isComment( line ) ) {
			if ( block.length === 0 ) {
				start = i;
			}
			block.push( line );
		} else {
			flush();
		}
	} );
	flush();

	return out;
}

function walk( dir ) {
	const out = [];
	for ( const entry of readdirSync( dir ) ) {
		if ( [ 'node_modules', 'vendor', 'vendor-prefixed', 'build', 'dist' ].includes( entry ) || entry.startsWith( '.' ) ) {
			continue;
		}
		const full = join( dir, entry );
		if ( statSync( full ).isDirectory() ) {
			out.push( ...walk( full ) );
		} else if ( EXT.test( full ) ) {
			out.push( full );
		}
	}

	return out;
}

const root = process.cwd();
const targets = process.argv.slice( 2 );
const files = targets.length > 0 ? targets.filter( ( f ) => EXT.test( f ) ) : walk( root );
const problems = [];

for ( const file of files ) {
	for ( const hit of offences( file ) ) {
		problems.push( `${ relative( root, file ) }:${ hit.line } - ${ hit.what }` );
	}
}

if ( problems.length > 0 ) {
	console.error( `\nComments too long (${ problems.length }). Say the one non-obvious thing, or delete:\n` );
	problems.forEach( ( p ) => console.error( `  ${ p }` ) );
	console.error( '' );
	process.exit( 2 );
}

if ( targets.length === 0 ) {
	console.log( `Comment check passed (${ files.length } files).` );
}
