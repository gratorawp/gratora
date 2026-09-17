/**
 * A donor and the org look at the same event and must read the same date. Two
 * renderers for one timestamp drift, and then the portal says "9/2/2026" for
 * the donation the admin screen dates "Sep 02, 2026, 02:44 PM".
 */

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );
jest.mock( '@wordpress/api-fetch', () => jest.fn( () => new Promise( () => {} ) ) );

import { formatDateTime as portalDate } from '../../assets/donor-portal/index';
import { formatDateTime as adminDate } from '../../assets/admin/donations/format';

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

test( 'a calendar day is the same day on both, wherever the reader is', () => {
	expect( portalDate( '2026-09-02' ) ).toBe( adminDate( '2026-09-02' ) );
	expect( portalDate( '2026-09-02' ) ).toContain( 'Sep 02' );
} );

test( 'a date nobody set leaves the portal line empty, where the admin prints a dash', () => {
	expect( portalDate( '' ) ).toBe( '' );
	expect( portalDate( null ) ).toBe( '' );
	expect( adminDate( '' ) ).toBe( '-' );
} );

test( 'an unparseable value is handed back rather than shown as Invalid Date', () => {
	expect( portalDate( 'not a date' ) ).toBe( 'not a date' );
	expect( adminDate( 'not a date' ) ).toBe( 'not a date' );
} );
