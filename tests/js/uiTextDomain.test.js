/**
 * The shared UI package hardcodes its own text domain, and this plugin declares
 * a different one, so its strings only resolve if the build rewrites them. When
 * the two domains are the same string the loader is a no-op that still returns
 * something plausible, so the rewrite is driven against the domain the package
 * actually ships rather than against a fixture.
 */

const fs = require( 'fs' );
const path = require( 'path' );

const { rewrite } = require( '../../build-tools/gratoraUiDomain.cjs' );

const PLUGIN_DOMAIN = 'gratora-donation-platform';
const DIST = path.join( __dirname, '../../node_modules/@gratora/ui/dist' );

/** Every text domain the shipped package asks gettext for. */
function shippedDomains() {
	const found = new Set();
	const walk = ( dir ) => {
		for ( const entry of fs.readdirSync( dir, { withFileTypes: true } ) ) {
			const full = path.join( dir, entry.name );
			if ( entry.isDirectory() ) {
				walk( full );
			} else if ( entry.name.endsWith( '.js' ) ) {
				const src = fs.readFileSync( full, 'utf8' );
				for ( const m of src.matchAll( /__\(\s*(?:'[^']*'|"[^"]*")\s*,\s*(?:'([^']+)'|"([^"]+)")/g ) ) {
					found.add( m[ 1 ] || m[ 2 ] );
				}
			}
		}
	};
	walk( DIST );

	return found;
}

it( 'rewrites the domain the package ships to the one the plugin declares', () => {
	for ( const domain of shippedDomains() ) {
		expect( rewrite( `__( 'Dismiss', '${ domain }' )` ) ).toBe( `__( 'Dismiss', '${ PLUGIN_DOMAIN }' )` );
	}
} );

it( 'leaves a string that is already on the plugin domain alone', () => {
	const already = `__( 'Dismiss', '${ PLUGIN_DOMAIN }' )`;

	expect( rewrite( already ) ).toBe( already );
} );

it( 'handles both quote styles, since the bundler emits either', () => {
	const [ shipped ] = [ ...shippedDomains() ];

	expect( rewrite( `__("Close", "${ shipped }")` ) ).toBe( `__("Close", "${ PLUGIN_DOMAIN }")` );
} );

it( 'does not rewrite a domain that merely starts with the plugin domain', () => {
	// 'gratora' is a prefix of the package's own domain, so a rewrite keyed on
	// the short name would leave a truncated suffix behind.
	expect( rewrite( "__( 'x', 'gratora-events' )" ) ).toBe( "__( 'x', 'gratora-events' )" );
} );
