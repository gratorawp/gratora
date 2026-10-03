/**
 * WordPress.org asks for the current release in readme.txt and the earlier ones
 * in a file of their own. Run against a repository made for the test, because
 * the release script reads its entries from git and writes both files.
 */

const { execFileSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );

const SCRIPT = path.join( __dirname, '../../bin/changelog.mjs' );

jest.setTimeout( 30000 );

let dir;

const git = ( ...args ) =>
	execFileSync(
		'git',
		[
			'-c',
			'user.name=test',
			'-c',
			'user.email=test@example.org',
			'-c',
			'commit.gpgsign=false',
			'-c',
			'core.hooksPath=/dev/null',
			...args,
		],
		{ cwd: dir, encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ] }
	);

const read = ( name ) => fs.readFileSync( path.join( dir, name ), 'utf8' );

const write = ( name, text ) => fs.writeFileSync( path.join( dir, name ), text );

/** One commit with the given subject, and the release it goes out in. */
function release( version, subject ) {
	git( 'commit', '-q', '--allow-empty', '-m', subject );
	write( 'gratora.php', `<?php\n/**\n * Version: ${ version }\n */\n` );
	execFileSync( process.execPath, [ path.join( dir, 'bin/changelog.mjs' ), '--write' ], {
		cwd: dir,
		stdio: 'ignore',
	} );
	git( 'add', '-A' );
	git( 'commit', '-q', '-m', `chore: version ${ version }` );
	git( 'tag', `v${ version }` );
}

/** What readme.txt has under its Changelog heading. */
const readmeChangelog = () => read( 'readme.txt' ).split( '== Changelog ==' )[ 1 ].split( '\n== ' )[ 0 ];

const versionsIn = ( text ) => [ ...text.matchAll( /^= (.+) =$/gm ) ].map( ( match ) => match[ 1 ] );

beforeEach( () => {
	dir = fs.mkdtempSync( path.join( os.tmpdir(), 'gratora-changelog-' ) );
	fs.mkdirSync( path.join( dir, 'bin' ) );
	fs.copyFileSync( SCRIPT, path.join( dir, 'bin/changelog.mjs' ) );
	write( 'gratora.php', '<?php\n/**\n * Version: 1.0.0\n */\n' );
	write(
		'readme.txt',
		'=== Plugin ===\n\n== Description ==\n\nText.\n\n== Changelog ==\n\n= 1.0.0 =\n* Initial release.\n'
	);
	write( 'changelog.txt', '== Changelog ==\n\n= 1.0.0 =\n* Initial release.\n' );
	git( 'init', '-q' );
	git( 'add', '-A' );
	git( 'commit', '-q', '-m', 'chore: start' );
	git( 'tag', 'v1.0.0' );
} );

afterEach( () => fs.rmSync( dir, { recursive: true, force: true } ) );

test( 'readme.txt shows the release just written and none before it', () => {
	release( '1.0.1', 'fix: a form saves' );
	release( '1.1.0', 'feature: a donor portal' );

	expect( versionsIn( readmeChangelog() ) ).toEqual( [ '1.1.0' ] );
} );

test( 'readme.txt says where the earlier releases are', () => {
	release( '1.0.1', 'fix: a form saves' );

	expect( readmeChangelog() ).toContain( 'changelog.txt' );
} );

test( 'changelog.txt keeps every release, newest first', () => {
	release( '1.0.1', 'fix: a form saves' );
	release( '1.1.0', 'feature: a donor portal' );

	expect( versionsIn( read( 'changelog.txt' ) ) ).toEqual( [ '1.1.0', '1.0.1', '1.0.0' ] );
} );
