#!/usr/bin/env node
/**
 * Package with production dependencies, restoring development dependencies in finally. Run
 * Strauss first: it is a dev dependency, and its prefixed output survives the production
 * install.
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
