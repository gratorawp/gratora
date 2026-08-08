#!/usr/bin/env node
/**
 * Build the changelog from conventional commits.
 *
 *   node bin/changelog.mjs                      # preview the next entry
 *   node bin/changelog.mjs --write              # write it
 *   node bin/changelog.mjs --since v1.0.0       # explicit range
 *   node bin/changelog.mjs --all                # include chore/refactor/test
 *
 * changelog.txt is the full history and the file the plugin directory shows in
 * its Changelog tab. readme.txt keeps only the most recent entries, because a
 * readme carrying every release is mostly changelog by the third one.
 *
 * Upgrade Notice is written too: it is the only part WordPress puts in front of
 * someone on the Plugins screen when an update is waiting, and a release that
 * skips it updates silently.
 */

import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..' );
const argv = process.argv.slice( 2 );

const flag = ( name ) => argv.includes( `--${ name }` );
const value = ( name ) => {
    const i = argv.indexOf( `--${ name }` );
    return i === -1 ? null : argv[ i + 1 ] ?? null;
};

const git = ( ...args ) => execFileSync( 'git', args, { cwd: root, encoding: 'utf8' } ).trim();

const README    = path.join( root, 'readme.txt' );
const CHANGELOG = path.join( root, 'changelog.txt' );
const README_KEEPS = 3;

/** Version from the plugin header, so the changelog cannot name one that was never shipped. */
function pluginVersion() {
    const header = readFileSync( path.join( root, 'dono.php' ), 'utf8' );
    const m = /^\s*\*\s*Version:\s*(.+)$/m.exec( header );
    if ( ! m ) throw new Error( 'No Version header in dono.php' );
    return m[ 1 ].trim();
}

function lastTag() {
    try {
        // stderr swallowed: an untagged repo is an ordinary state here, and
        // git's "cannot describe anything" reads like a failure of this script.
        return execFileSync( 'git', [ 'describe', '--tags', '--abbrev=0' ], {
            cwd: root,
            encoding: 'utf8',
            stdio: [ 'ignore', 'pipe', 'ignore' ],
        } ).trim();
    } catch {
        return null;
    }
}

/**
 * feat and feature both appear in this history, so they fold together. Anything
 * outside the map is work the reader of a changelog did not ask about.
 */
const BUCKETS = {
    feature: 'Added',
    feat:    'Added',
    fix:     'Fixed',
    perf:    'Fixed',
};
const QUIET = [ 'chore', 'refactor', 'test', 'docs', 'style', 'ci', 'build' ];
const ORDER = [ 'Added', 'Fixed', 'Changed' ];

function collect( since ) {
    const range = since ? `${ since }..HEAD` : 'HEAD';
    const raw   = git( 'log', range, '--no-merges', '--format=%s' );
    if ( ! raw ) return {};

    const groups = {};
    for ( const subject of raw.split( '\n' ) ) {
        const m = /^([a-z]+)(?:\([^)]*\))?:\s*(.+)$/.exec( subject );
        if ( ! m ) continue;

        const [ , type, text ] = m;
        let bucket = BUCKETS[ type ];
        if ( ! bucket ) {
            if ( ! flag( 'all' ) || ! QUIET.includes( type ) ) continue;
            bucket = 'Changed';
        }

        // Subjects are written lowercase; a changelog line starts as a sentence.
        const line = text.charAt( 0 ).toUpperCase() + text.slice( 1 );
        ( groups[ bucket ] ??= [] ).push( line );
    }
    return groups;
}

function renderEntry( version, groups ) {
    const out = [ `= ${ version } =` ];
    let empty = true;

    for ( const bucket of ORDER ) {
        const lines = groups[ bucket ];
        if ( ! lines?.length ) continue;
        empty = false;
        out.push( `**${ bucket }**` );
        for ( const line of lines ) out.push( `* ${ line }` );
        out.push( '' );
    }

    if ( empty ) return null;
    return out.join( '\n' ).trimEnd();
}

/** Split a WordPress readme/changelog body into `= x.y.z =` blocks, newest first. */
function splitEntries( body ) {
    const parts = body.split( /^(?== \d)/m ).map( ( s ) => s.trimEnd() ).filter( Boolean );
    return parts;
}

function replaceSection( text, heading, body ) {
    const start = text.indexOf( `== ${ heading } ==` );
    if ( start === -1 ) {
        return `${ text.trimEnd() }\n\n== ${ heading } ==\n\n${ body }\n`;
    }
    const after = text.indexOf( '\n== ', start + 1 );
    const tail  = after === -1 ? '' : text.slice( after );
    return `${ text.slice( 0, start ) }== ${ heading } ==\n\n${ body }\n${ tail }`;
}

function sectionBody( text, heading ) {
    const start = text.indexOf( `== ${ heading } ==` );
    if ( start === -1 ) return '';
    const from  = start + `== ${ heading } ==`.length;
    const after = text.indexOf( '\n== ', from );
    return text.slice( from, after === -1 ? undefined : after ).trim();
}

const version = value( 'version' ) ?? pluginVersion();
const since   = value( 'since' ) ?? lastTag();

if ( ! since ) {
    console.error( 'No tags yet, so there is no range to describe.' );
    console.error( 'Tag the current release first, or pass --since <ref> explicitly.' );
    console.error( 'e.g. node bin/changelog.mjs --since 4ae6690' );
    process.exit( 1 );
}

const groups = collect( since );
const entry  = renderEntry( version, groups );

if ( ! entry ) {
    console.error( `Nothing user-facing since ${ since }. Use --all to include chores.` );
    process.exit( 1 );
}

const counts = ORDER
    .filter( ( b ) => groups[ b ]?.length )
    .map( ( b ) => `${ groups[ b ].length } ${ b.toLowerCase() }` )
    .join( ', ' );

if ( ! flag( 'write' ) ) {
    console.log( `# ${ version }, from ${ since }..HEAD (${ counts })\n` );
    console.log( entry );
    console.log( '\n(preview only; pass --write to update changelog.txt and readme.txt)' );
    process.exit( 0 );
}

// changelog.txt: the full history, newest first.
const existing = existsSync( CHANGELOG )
    ? splitEntries( sectionBody( readFileSync( CHANGELOG, 'utf8' ), 'Changelog' ) )
    : [];

if ( existing.some( ( e ) => e.startsWith( `= ${ version } =` ) ) ) {
    console.error( `changelog.txt already has an entry for ${ version }.` );
    console.error( 'Bump the Version header in dono.php, or pass --version.' );
    process.exit( 1 );
}

const all = [ entry, ...existing ];
writeFileSync( CHANGELOG, `== Changelog ==\n\n${ all.join( '\n\n' ) }\n` );

// readme.txt: the recent few, then a pointer at the rest.
const readme = readFileSync( README, 'utf8' );
const recent = all.slice( 0, README_KEEPS ).join( '\n\n' );
const pointer = all.length > README_KEEPS ? '\nThe full history lives in changelog.txt.\n' : '';

let next = replaceSection( readme, 'Changelog', `${ recent }\n${ pointer }` );

// One line, because WordPress shows it inline on the Plugins screen and a
// paragraph there is a wall of text beside a button.
const headline = ( groups.Added?.[ 0 ] ?? groups.Fixed?.[ 0 ] ?? '' ).replace( /\.$/, '' );
next = replaceSection( next, 'Upgrade Notice', `= ${ version } =\n${ headline }.` );

writeFileSync( README, next );

console.log( `Wrote ${ version } (${ counts })` );
console.log( '  changelog.txt  full history' );
console.log( `  readme.txt     newest ${ Math.min( all.length, README_KEEPS ) } entries + upgrade notice` );
