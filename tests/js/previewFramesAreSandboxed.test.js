/**
 * The two admin previews build their document with srcDoc. An about:srcdoc
 * frame inherits the embedding origin, so without a sandbox anything scripted
 * inside one runs as wp-admin, with the admin's cookies and nonce. The only
 * thing standing between that and the form editor is sanitizeBlocks, which
 * exempts unfiltered_html holders outright.
 *
 * allow-scripts alone gives the frame an opaque origin while still letting the
 * form's own JS run. allow-same-origin must never join it: the pair together
 * lets the framed document reach up and remove its own sandbox attribute,
 * which is the same as having none.
 */

const fs = require( 'fs' );
const path = require( 'path' );

const FRAMES = [
	[ 'the form editor preview', 'assets/admin/forms/Editor.jsx' ],
	[ 'the onboarding preview', 'assets/admin/onboarding/Onboarding.jsx' ],
];

/** @return {string[]} the sandbox value of every srcDoc iframe in the file */
function sandboxesOfSrcDocFrames( source ) {
	return [ ...source.matchAll( /<iframe\b[^>]*?\/?>/gs ) ]
		.filter( ( m ) => /srcDoc=/.test( m[ 0 ] ) )
		.map( ( m ) => {
			const attr = m[ 0 ].match( /sandbox="([^"]*)"/ );
			return attr ? attr[ 1 ] : null;
		} );
}

describe.each( FRAMES )( '%s', ( _name, file ) => {
	const source = fs.readFileSync( path.join( __dirname, '../..', file ), 'utf8' );
	const sandboxes = sandboxesOfSrcDocFrames( source );

	test( 'has a srcDoc frame to check', () => {
		expect( sandboxes.length ).toBeGreaterThan( 0 );
	} );

	test( 'every srcDoc frame is sandboxed', () => {
		expect( sandboxes ).not.toContain( null );
	} );

	test( 'no sandbox re-grants the embedding origin', () => {
		sandboxes.forEach( ( value ) => {
			expect( value ).not.toMatch( /allow-same-origin/ );
		} );
	} );
} );

/** The detector has to detect, or the assertions above are theatre. */
describe( 'the detector', () => {
	test( 'sees an unsandboxed srcDoc frame', () => {
		expect( sandboxesOfSrcDocFrames( '<iframe className="x" srcDoc={ html } />' ) ).toEqual( [ null ] );
	} );

	test( 'reads the sandbox value off a multi-line frame', () => {
		const markup = `<iframe
			className="x"
			sandbox="allow-scripts"
			srcDoc={ html }
		/>`;
		expect( sandboxesOfSrcDocFrames( markup ) ).toEqual( [ 'allow-scripts' ] );
	} );

	test( 'ignores a frame that is not srcDoc', () => {
		expect( sandboxesOfSrcDocFrames( '<iframe src="https://example.org" />' ) ).toEqual( [] );
	} );
} );
