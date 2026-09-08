#!/usr/bin/env node
/**
 * Build the plugin zip using .distignore: /foo anchors at root, bare names match anywhere,
 * globs match basenames, and # starts comments.
 */

import { execFileSync } from 'node:child_process';
import { cpSync, existsSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, statSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..' );

/**
 * The directory the plugin installs into. Named here and in deploy.yml, and the
 * two have to agree: a zip that unpacks to a different directory than the
 * directory installs to leaves a site running the plugin twice.
 */
const slug = 'fundraising-toolkit';

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
 * Check installed.json and transitive lockfile dev packages; require-dev alone misses
 * dependencies.
 */
function devInstall() {
    const manifest = path.join( root, 'vendor', 'composer', 'installed.json' );
    if ( ! existsSync( manifest ) ) {
        return { installed: false, readable: false, withDev: false, present: [] };
    }

    const installed = JSON.parse( readFileSync( manifest, 'utf8' ) );

    // Composer 1 wrote a bare array with no record of the kind of install it
    // performed. Refusing is right, but not while claiming the tree has
    // development dependencies in it: nothing here knows that either way.
    if ( typeof installed !== 'object' || installed === null || typeof installed.dev !== 'boolean' ) {
        return { installed: true, readable: false, withDev: false, present: [] };
    }

    const lock      = path.join( root, 'composer.lock' );
    const names     = existsSync( lock )
        ? ( JSON.parse( readFileSync( lock, 'utf8' ) )[ 'packages-dev' ] || [] ).map( ( p ) => p.name )
        : ( installed[ 'dev-package-names' ] || [] );

    return {
        installed: true,
        readable: true,
        // Composer writes false only for an install that excluded require-dev.
        withDev: installed.dev !== false,
        present: names.filter( ( name ) => existsSync( path.join( root, 'vendor', ...name.split( '/' ) ) ) ),
    };
}

/** Reject stale builds by mtime; webpack output is not byte-reproducible. */
function newestMtime( dir ) {
    if ( ! existsSync( dir ) ) return null;

    let newest = 0;
    const walk = ( at ) => {
        for ( const entry of readdirSync( at ) ) {
            const full = path.join( at, entry );
            const stat = statSync( full );
            if ( stat.isDirectory() ) walk( full );
            else newest = Math.max( newest, stat.mtimeMs );
        }
    };
    walk( dir );

    return newest || null;
}

const builtAt  = newestMtime( path.join( root, 'build' ) );
const sourceAt = newestMtime( path.join( root, 'assets' ) );

if ( builtAt === null ) {
    console.error( 'No build/ directory, so the zip would carry no compiled assets at all.' );
    console.error( 'Run `npm run build` first, then package.' );
    process.exit( 1 );
}
if ( sourceAt !== null && sourceAt > builtAt ) {
    console.error( 'assets/ is newer than build/, so the zip would ship a bundle that predates the source.' );
    console.error( 'Run `npm run build` first, then package.' );
    process.exit( 1 );
}

const vendor = devInstall();
if ( ! vendor.installed ) {
    console.error( 'vendor/ carries no composer manifest, so there is no telling what is in it.' );
    console.error( 'Run `composer install --no-dev` first, then package.' );
    process.exit( 1 );
}
if ( ! vendor.readable ) {
    console.error( 'vendor/composer/installed.json says nothing about how vendor/ was installed.' );
    console.error( 'Reinstall with composer 2: `composer install --no-dev`, then package.' );
    process.exit( 1 );
}
if ( vendor.withDev || vendor.present.length > 0 ) {
    // A full dev install is eighty-odd packages, so name a handful and count the rest.
    const named = vendor.present.slice( 0, 5 ).join( ', ' );
    const rest  = vendor.present.length - 5;

    console.error( vendor.present.length > 0
        ? `vendor/ still has development dependencies: ${ named }${ rest > 0 ? `, and ${ rest } more` : '' }`
        : 'vendor/ was installed with development dependencies.' );
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
    // The vendor directory is the composer package name, not the namespace.
    [ 'fundkit', 'queryable' ],   // every database call
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

/**
 * Regenerate Composer’s autoloader after payload exclusions so it cannot claim classes from
 * omitted directories.
 */
execFileSync( 'composer', [ 'dump-autoload', '--no-dev', '--no-interaction', '--quiet' ], { cwd: payload } );

const distDir = path.join( root, 'dist' );
mkdirSync( distDir, { recursive: true } );
const zip = path.join( distDir, `${ slug }.zip` );
rmSync( zip, { force: true } );

execFileSync( 'zip', [ '-qr', zip, slug ], { cwd: staging } );

/**
 * The same payload, unpacked. The zip is what a tester installs; svn wants a
 * directory to rsync into trunk, and unzipping the artifact to get one invites
 * a deploy that ships whatever was already sitting there.
 */
const tree = path.join( distDir, slug );
rmSync( tree, { recursive: true, force: true } );
cpSync( payload, tree, { recursive: true } );

rmSync( staging, { recursive: true, force: true } );

const mb = ( statSync( zip ).size / 1024 / 1024 ).toFixed( 2 );
console.log( `${ slug }.zip  ${ mb } MB  ->  ${ path.relative( process.cwd(), zip ) }` );
console.log( `${ slug }/      unpacked  ->  ${ path.relative( process.cwd(), tree ) }` );
console.log( `excluded ${ excluded.length } paths:` );
for ( const rel of excluded.sort() ) console.log( `  ${ rel }` );
