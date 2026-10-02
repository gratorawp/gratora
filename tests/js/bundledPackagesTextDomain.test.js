/**
 * DataViews and the editor skeleton are bundled into the admin scripts, and as
 * published they translate through WordPress's own text domain, which a Gratora
 * screen carries no translations for. The build gives each of their calls the
 * plugin's domain. Driven against the packages as installed, so a release that
 * calls the functions some other way fails here instead of shipping a list
 * whose filters and paging are English in every language.
 */

const fs = require( 'fs' );
const path = require( 'path' );
// eslint-disable-next-line import/no-extraneous-dependencies -- the parser the build step runs on, which @wordpress/scripts brings
const babel = require( '@babel/core' );

const { rewrite } = require( '../../build-tools/bundledPackagesDomain.cjs' );

const DOMAIN = 'gratora-donation-platform';
const BEFORE_DOMAIN = { __: 1, _x: 2, _n: 3, _nx: 4 };
const PACKAGES = [ 'dataviews', 'interface' ].map(
	( name ) => path.join( __dirname, '../../node_modules/@wordpress', name, 'build-module' )
);

/** Every module the packages ship that translates something. */
function translatingModules() {
	const found = [];
	const walk = ( dir ) => {
		for ( const entry of fs.readdirSync( dir, { withFileTypes: true } ) ) {
			const full = path.join( dir, entry.name );
			if ( entry.isDirectory() ) {
				walk( full );
			} else if ( entry.name.endsWith( '.js' ) ) {
				const source = fs.readFileSync( full, 'utf8' );
				if ( source.includes( '@wordpress/i18n' ) ) {
					found.push( source );
				}
			}
		}
	};
	PACKAGES.forEach( walk );

	return found;
}

/**
 * Every call to a gettext function in a module, whatever it is called on, as
 * its name and its literal arguments.
 */
function gettextCalls( source ) {
	const calls = [];
	babel.traverse( babel.parseSync( source, { babelrc: false, configFile: false, sourceType: 'module' } ), {
		CallExpression( { node } ) {
			const name = node.callee.name ?? node.callee.property?.name;
			if ( Object.hasOwn( BEFORE_DOMAIN, name ) ) {
				calls.push( { name, args: node.arguments.map( ( arg ) => arg.value ) } );
			}
		},
	} );

	return calls;
}

it( 'gives every string the packages translate the plugin domain', () => {
	const calls = translatingModules().flatMap( ( source ) => gettextCalls( rewrite( source ) ) );

	expect( calls.length ).toBeGreaterThan( 50 );
	for ( const { name, args } of calls ) {
		expect( [ name, args.length, args.at( -1 ) ] ).toEqual( [ name, BEFORE_DOMAIN[ name ] + 1, DOMAIN ] );
	}
} );

it( 'leaves the text, its plural and its context as the package wrote them', () => {
	for ( const source of translatingModules() ) {
		const before = gettextCalls( source );
		const after  = gettextCalls( rewrite( source ) );

		expect( after.map( ( { name, args } ) => [ name, ...args.slice( 0, BEFORE_DOMAIN[ name ] ) ] ) )
			.toEqual( before.map( ( { name, args } ) => [ name, ...args ] ) );
	}
} );

it( 'leaves a call that names its own domain alone', () => {
	const source = "import { __ } from '@wordpress/i18n';\n__( 'Close', 'another-plugin' );";

	expect( gettextCalls( rewrite( source ) ) ).toEqual( [ { name: '__', args: [ 'Close', 'another-plugin' ] } ] );
} );

it( 'leaves a function of the same name from somewhere else alone', () => {
	const source = "import { sprintf } from '@wordpress/i18n';\nimport { __ } from './labels';\n__( 'Close' );";

	expect( gettextCalls( rewrite( source ) ) ).toEqual( [ { name: '__', args: [ 'Close' ] } ] );
} );
