/**
 * The list columns print the date on the line underneath the relative age, so
 * an age that gives up and prints a date past some threshold reads "Aug 25,
 * 2026" twice and says nothing the row did not already say.
 */

import { timeAgo } from '../../assets/admin/_shared/format';

const daysAgo = ( n ) => new Date( Date.now() - n * 86400000 ).toISOString();

describe( 'how long ago, past a week', () => {
	it.each( [
		[ 8,    '1w ago' ],
		[ 20,   '2w ago' ],
		[ 29,   '4w ago' ],
		[ 31,   '1mo ago' ],
		[ 200,  '6mo ago' ],
		[ 364,  '12mo ago' ],
		[ 400,  '1y ago' ],
		[ 1200, '3y ago' ],
	] )( '%d days ago reads as %s', ( days, expected ) => {
		expect( timeAgo( daysAgo( days ) ) ).toBe( expected );
	} );

	it( 'never falls back to a bare date, which the row already shows', () => {
		[ 8, 45, 500, 5000 ].forEach( ( days ) => {
			expect( timeAgo( daysAgo( days ) ) ).toMatch( /ago$/ );
		} );
	} );
} );

describe( 'the shorter ages', () => {
	it( 'answers in days below a week', () => {
		expect( timeAgo( daysAgo( 3 ) ) ).toBe( '3d ago' );
	} );

	it( 'shows a date for something dated ahead', () => {
		const later = new Date( Date.now() + 3 * 86400000 ).toISOString();

		expect( timeAgo( later ) ).not.toMatch( /ago$/ );
	} );
} );
