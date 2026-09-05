/**
 * A structural guard, not a behavioural one: it reads the source rather than
 * clicking the menu. It exists because the ways this action goes missing are
 * all declarative. DataViews filters an ineligible action out of the row menu
 * entirely, and draws a primary one as an icon button that renders as nothing
 * without an icon, so either flag silently removes it.
 */

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );
jest.mock( '@wordpress/api-fetch', () => jest.fn( () => new Promise( () => {} ) ) );

import fs from 'fs';
import path from 'path';

const source = fs.readFileSync(
	path.join( __dirname, '../../assets/admin/subscriptions/List.jsx' ),
	'utf8'
);

test( 'the row menu offers View details', () => {
	expect( source ).toContain( "id:    'view_details'" );
	expect( source ).toContain( "__( 'View details', 'fundraising-toolkit' )" );
} );

test( 'it opens the dialog rather than an action confirmation', () => {
	const block = source.slice(
		source.indexOf( "id:    'view_details'" ),
		source.indexOf( "id:          'copy_subscription_id'" )
	);

	expect( block ).toContain( 'setDetail( items[ 0 ] )' );
	expect( block ).not.toContain( 'setDialog' );
} );

test( 'it carries no eligibility gate, which would hide it from the menu', () => {
	const block = source.slice(
		source.indexOf( "id:    'view_details'" ),
		source.indexOf( "id:          'copy_subscription_id'" )
	);

	expect( block ).not.toContain( 'isEligible' );
} );

test( 'it is not primary, so it stays in the menu rather than needing an icon', () => {
	const block = source.slice(
		source.indexOf( "id:    'view_details'" ),
		source.indexOf( "id:          'copy_subscription_id'" )
	);

	expect( block ).not.toContain( 'isPrimary:  true' );
} );
