/**
 * A block inspector that tells an admin something is configured on another
 * screen has to take them there. Reading "enable them under Settings, Currency"
 * with nothing to click means hunting through the menu with an unsaved form
 * open behind you.
 *
 * The check is per file, not per sentence: a block that names a destination has
 * to offer a way to reach one. It cannot tell whether a given sentence links to
 * the RIGHT screen, so it catches the dead end, not a wrong address.
 */

import fs from 'fs';
import path from 'path';

const BLOCKS = path.join( __dirname, '../../assets/admin/forms/blocks' );

// A destination outside the block's own panel, named in prose.
const POINTS_ELSEWHERE = /(?:in|under|from|to)\s+Settings\b|Settings\s*(?:→|,)\s*[A-Z]|under\s+Donations\b|in\s+Campaigns\b/;

const files = fs.readdirSync( BLOCKS, { withFileTypes: true } )
	.filter( ( d ) => d.isDirectory() && d.name !== '_shared' )
	.flatMap( ( d ) => fs.readdirSync( path.join( BLOCKS, d.name ) )
		.filter( ( f ) => /\.jsx?$/.test( f ) )
		.map( ( f ) => ( { block: d.name, file: path.join( BLOCKS, d.name, f ) } ) ) );

test( 'the block directory was actually read', () => {
	expect( files.length ).toBeGreaterThan( 30 );
} );

describe( 'a block that sends the admin elsewhere gives them a link', () => {
	const pointing = files
		.map( ( f ) => ( { ...f, source: fs.readFileSync( f.file, 'utf8' ) } ) )
		.filter( ( f ) => POINTS_ELSEWHERE.test( f.source ) );

	test( 'the pattern still matches something, so this suite is not vacuous', () => {
		expect( pointing.map( ( f ) => f.block ).sort() ).toEqual(
			expect.arrayContaining( [ 'currency-switcher', 'payment-gateways' ] )
		);
	} );

	for ( const { block, source } of pointing ) {
		test( block, () => {
			expect( source ).toContain( '<ExternalLink' );

			// Two shapes are in use: a literal admin.php URL, and one handed
			// down from PHP by admin_url(), which is what a block linking to a
			// core screen has to use.
			expect( source ).toMatch( /admin\.php\?page=gratora-|href=\{\s*\w*[Uu]rl|href=\{\s*\w*[Hh]ref/ );
		} );
	}
} );

describe( 'the goal block links at the field, not at the screen', () => {
	const goal   = fs.readFileSync( path.join( BLOCKS, 'goal/index.js' ), 'utf8' );
	const detail = fs.readFileSync(
		path.join( __dirname, '../../assets/admin/campaigns/Detail.jsx' ),
		'utf8'
	);

	test( 'it names the sub-tab that holds the goal fields', () => {
		expect( goal ).toMatch( /tab=settings#goal/ );
	} );

	test( 'and that sub-tab is one the campaign screen actually has', () => {
		const keys = [ ...detail.matchAll( /\{\s*key:\s*'([a-z-]+)'/g ) ].map( ( m ) => m[ 1 ] );

		expect( keys ).toContain( 'goal' );
	} );

	test( 'the campaign screen opens on the sub-tab the fragment names', () => {
		// Without this the link lands on General, which carries no goal field.
		expect( detail ).toMatch( /location\.hash/ );
		expect( detail ).toMatch( /SUB_TABS\.some\(/ );
	} );
} );
