#!/usr/bin/env node
/**
 * Cut a release without leaving the checkout in a state somebody has to undo.
 *
 * Packaging needs a vendor/ with no development dependencies in it, and the
 * packager refuses to guess: it stops and tells you to install --no-dev first.
 * Doing that by hand means the tree is left without a test suite until you
 * remember the second command, and a failed package leaves it that way for
 * good. That is a poor thing to stand on when the next step is a deploy.
 *
 * So the swap happens here, and the restore is in a finally: interrupt this,
 * or break the packager, and the checkout still ends up the way it started.
 *
 * strauss is itself a development dependency, and vendor/vendor-prefixed is not
 * composer's to remove, so the prefixed tree written by the full install
 * survives the --no-dev pass and rides into the payload. Running strauss with
 * dev dependencies already gone would produce nothing at all.
 */

import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..' );

const run = ( cmd, args ) =>
    execFileSync( cmd, args, { cwd: root, stdio: 'inherit' } );

if ( ! existsSync( path.join( root, 'vendor', 'vendor-prefixed' ) ) ) {
    console.error( 'No vendor/vendor-prefixed: run `composer install` once so strauss can write it.' );
    process.exit( 1 );
}

let failed = null;

try {
    console.log( '> composer install --no-dev' );
    run( 'composer', [ 'install', '--no-dev', '--no-interaction', '--quiet' ] );

    console.log( '> package' );
    run( 'node', [ path.join( root, 'bin', 'package.mjs' ) ] );
} catch ( error ) {
    failed = error;
} finally {
    console.log( '> composer install (restoring the test suite)' );
    try {
        run( 'composer', [ 'install', '--no-interaction', '--quiet' ] );
    } catch ( restoreError ) {
        console.error( 'The restore failed. vendor/ has no development dependencies in it.' );
        console.error( 'Run `composer install` before trying to run the tests.' );
        process.exitCode = 1;
    }
}

if ( failed ) {
    process.exit( typeof failed.status === 'number' ? failed.status : 1 );
}
