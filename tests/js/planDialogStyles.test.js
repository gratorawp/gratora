/**
 * PlanDetailDialog is opened from the subscriptions list and from the donor
 * profile's recurring tab, which are separate bundles. Its styles lived with
 * the screen it was written for, so on the other one it rendered unstyled.
 */

import fs from 'fs';
import path from 'path';

const read = ( p ) => fs.readFileSync( path.join( __dirname, '../../assets/admin', p ), 'utf8' );

const ENTRYPOINTS = [ 'subscriptions/subscriptions.scss', 'donors/donors.scss' ];

test( 'every screen that opens the dialog loads its styles', () => {
	for ( const entry of ENTRYPOINTS ) {
		expect( read( entry ) ).toContain( "_shared/plan-dialog" );
	}
} );

test( 'the styles live in one place, not per screen', () => {
	const shared = read( '_shared/_plan-dialog.scss' );

	for ( const selector of [ '.sd-head', '.sd-group', '.sd-kv', '.sd-errors', '.sd-foot__close' ] ) {
		expect( shared ).toContain( selector );
	}

	// And no screen keeps a private copy that could drift from the markup.
	for ( const entry of ENTRYPOINTS ) {
		expect( read( entry ) ).not.toContain( '.sd-head {' );
	}
} );

test( 'the classes the dialog renders are the ones the partial defines', () => {
	const markup = read( 'subscriptions/PlanDetailDialog.jsx' );
	const shared = read( '_shared/_plan-dialog.scss' );

	const used = [ ...markup.matchAll( /className="(sd-[a-z_-]+)/g ) ].map( ( m ) => m[ 1 ] );
	expect( used.length ).toBeGreaterThan( 4 );

	for ( const cls of new Set( used ) ) {
		const root = cls.split( '__' )[ 0 ];
		expect( shared ).toContain( `.${ root }` );
	}
} );
