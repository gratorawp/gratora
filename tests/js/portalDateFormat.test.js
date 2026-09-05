/**
 * A donor and the org look at the same event and must read the same date. The
 * portal formatted with a bare toLocaleDateString, so admin said
 * "Sep 02, 2026, 02:44 PM" and the portal said "9/2/2026" for one timestamp.
 */

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );
jest.mock( '@wordpress/api-fetch', () => jest.fn( () => new Promise( () => {} ) ) );

import { formatDate as portalDate } from '../../assets/donor-portal/index';
import { formatDate as adminDate } from '../../assets/admin/donations/format';

const STAMPS = [
	'2026-09-02 14:44:00',
	'2026-01-01 00:30:00',
	'2026-12-31 23:59:59',
];

test( 'the portal reads a timestamp exactly as the admin does', () => {
	for ( const iso of STAMPS ) {
		expect( portalDate( iso ) ).toBe( adminDate( iso ) );
	}
} );

test( 'it no longer pushes a date-only value to UTC midnight', () => {
	// The old implementation appended Z unconditionally, which could move a
	// bare date across a day boundary depending on the reader's zone.
	expect( portalDate( '2026-09-02' ) ).toBe( adminDate( '2026-09-02' ) );
} );

test( 'an empty value stays empty rather than printing a placeholder', () => {
	expect( portalDate( '' ) ).toBe( '' );
	expect( portalDate( null ) ).toBe( '' );
} );

test( 'an unparseable value is handed back rather than shown as Invalid Date', () => {
	expect( portalDate( 'not a date' ) ).toBe( 'not a date' );
} );
