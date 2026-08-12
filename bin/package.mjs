#!/usr/bin/env node
/**
 * Build the distributable plugin zip, honouring .distignore.
 *
 * Written because .distignore on its own is a promise nothing keeps: the file
 * existed for a while with no packaging step reading it, which reads as "the
 * zip is clean" while any zip actually cut by hand shipped everything.
 *
 * Rules follow the .gitignore subset WP-CLI's dist-archive uses:
 *   /foo    anchored at the plugin root
 *   foo     matches a file or directory anywhere in the tree
 *   *.log   glob, matched against the basename
 *   #       comment
 */

import { execFileSync } from 'node:child_process';
import { cpSync, existsSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, statSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..' );

/**
 * The directory the plugin installs into, taken from the header rather than
 * from whatever this checkout happens to be called.
 *
 * WordPress.org derives the slug from the plugin name and requires the text
 * domain to match it, so the header is the one place that already has to be
 * right. Reading the folder name instead produced a zip that installed to
 * wp-content/plugins/dono while the directory installs to
 * dono-fundraising-platform: a site that took one from each channel would end
 * up running the plugin twice.
 */
const slug = ( () => {
    const header = readFileSync( path.join( root, 'dono.php' ), 'utf8' );
    const match  = header.match( /^\s*\*\s*Text Domain:\s*(\S+)\s*$/m );

    if ( ! match ) {
        console.error( 'No Text Domain in the plugin header. Refusing to guess the install directory.' );
        process.exit( 1 );
    }

    return match[ 1 ];
} )();

function rules() {
    const file = path.join( root, '.distignore' );
    if ( ! existsSync( file ) ) {
        console.error( `No .distignore in ${ slug }. Refusing to guess what is safe to ship.` );
        process.exit( 1 );
    }
    return readFileSync( file, 'utf8' )
        .split( '\n' )
        .map( ( l ) => l.trim() )
        .filter( ( l ) => l !== '' && ! l.startsWith( '#' ) );
}

function toMatcher( rule ) {
    const anchored = rule.startsWith( '/' );
    const body     = ( anchored ? rule.slice( 1 ) : rule ).replace( /\/$/, '' );
    const glob     = body.includes( '*' );

    if ( glob ) {
        const re = new RegExp( '^' + body.replace( /[.+^${}()|[\]\\]/g, '\\$&' ).replace( /\*/g, '.*' ) + '$' );
        // A glob rule matches on basename wherever it appears.
        return ( rel ) => re.test( path.basename( rel ) );
    }
    if ( anchored ) {
        return ( rel ) => rel === body || rel.startsWith( body + '/' );
    }
    return ( rel ) => rel === body
        || rel.endsWith( '/' + body )
        || rel.startsWith( body + '/' )
        || rel.includes( '/' + body + '/' );
}

/**
 * .distignore cannot strip development dependencies: they are scattered across
 * vendor/ under names nobody lists by hand, and the first attempt at this
 * produced a 3 GB zip because two thirds of it was PHPUnit and its tooling.
 * Composer already knows which packages are dev-only, so ask it.
 */
function devPackagesPresent() {
    const composer = path.join( root, 'composer.json' );
    if ( ! existsSync( composer ) ) return [];

    const dev = Object.keys( JSON.parse( readFileSync( composer, 'utf8' ) )[ 'require-dev' ] || {} );

    return dev.filter( ( name ) => existsSync( path.join( root, 'vendor', ...name.split( '/' ) ) ) );
}

const stragglers = devPackagesPresent();
if ( stragglers.length > 0 ) {
    console.error( `vendor/ still has development dependencies: ${ stragglers.join( ', ' ) }` );
    console.error( 'Run `composer install --no-dev` first, then package.' );
    console.error( '(`composer install` afterwards puts your test suite back.)' );
    process.exit( 1 );
}

/**
 * The prefixed vendor is what the plugin actually loads at runtime, and it is
 * Strauss output rather than anything composer restores. A zip without it
 * installs cleanly and then fatals on the first query or the first receipt,
 * and it cannot be caught by running the plugin locally, where the directory
 * is present whether or not the build produced it.
 */
const REQUIRED_PREFIXED = [
    [ 'dono', 'queryable' ],   // every database call
    [ 'dompdf', 'dompdf' ],    // receipts and annual statements
];

const missingPrefixed = REQUIRED_PREFIXED
    .filter( ( parts ) => ! existsSync( path.join( root, 'vendor', 'vendor-prefixed', ...parts ) ) )
    .map( ( parts ) => parts.join( '/' ) );

if ( missingPrefixed.length > 0 ) {
    console.error( `vendor/vendor-prefixed/ is missing: ${ missingPrefixed.join( ', ' ) }` );
    console.error( 'Run `composer strauss` before packaging.' );
    process.exit( 1 );
}

const matchers = rules().map( toMatcher );
const excluded = [];

// Our own output directory: on a second run it already exists, and without
// this the zip contains the previous zip.
const OUTPUT_DIR = 'dist';

function ignored( rel ) {
    if ( rel === OUTPUT_DIR || rel.startsWith( OUTPUT_DIR + '/' ) ) {
        return true;
    }
    if ( matchers.some( ( m ) => m( rel ) ) ) {
        excluded.push( rel );
        return true;
    }
    return false;
}

function copyTree( from, to, prefix = '' ) {
    mkdirSync( to, { recursive: true } );
    for ( const entry of readdirSync( from ) ) {
        const rel = prefix ? `${ prefix }/${ entry }` : entry;
        if ( ignored( rel ) ) continue;

        const src = path.join( from, entry );
        if ( statSync( src ).isDirectory() ) {
            copyTree( src, path.join( to, entry ), rel );
        } else {
            cpSync( src, path.join( to, entry ) );
        }
    }
}

const staging = mkdtempSync( path.join( tmpdir(), `${ slug }-dist-` ) );
const payload = path.join( staging, slug );

copyTree( root, payload );

const distDir = path.join( root, 'dist' );
mkdirSync( distDir, { recursive: true } );
const zip = path.join( distDir, `${ slug }.zip` );
rmSync( zip, { force: true } );

execFileSync( 'zip', [ '-qr', zip, slug ], { cwd: staging } );
rmSync( staging, { recursive: true, force: true } );

const mb = ( statSync( zip ).size / 1024 / 1024 ).toFixed( 2 );
console.log( `${ slug }.zip  ${ mb } MB  ->  ${ path.relative( process.cwd(), zip ) }` );
console.log( `excluded ${ excluded.length } paths:` );
for ( const rel of excluded.sort() ) console.log( `  ${ rel }` );
