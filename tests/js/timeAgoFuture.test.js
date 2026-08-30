/**
 * The shared helper clamps a negative age to zero, so anything dated ahead of
 * now comes back as "just now". A donation an admin recorded for later today
 * then sat at the top of the dashboard claiming to have arrived this second,
 * which is the most reassuring reading of the data and the wrong one.
 */
import { timeAgo } from '../../assets/admin/_shared/format';

const iso = ( offsetMs ) => new Date( Date.now() + offsetMs ).toISOString().replace( 'T', ' ' ).slice( 0, 19 );

test( 'something that has not happened shows its date, not just now', () => {
	const answer = timeAgo( iso( 3 * 60 * 60 * 1000 ) );

	expect( answer ).not.toMatch( /just now/i );
	expect( answer ).not.toMatch( /ago/i );
} );

test( 'something that happened seconds ago is still just now', () => {
	expect( timeAgo( iso( -5 * 1000 ) ) ).toMatch( /just now/i );
} );

/** A server clock and a browser clock disagree by seconds, not by minutes. */
test( 'a donation made this instant is not pushed into the future by clock skew', () => {
	expect( timeAgo( iso( 20 * 1000 ) ) ).toMatch( /just now/i );
} );

test( 'the ordinary relative answers are untouched', () => {
	expect( timeAgo( iso( -45 * 60 * 1000 ) ) ).toMatch( /45m ago/ );
	expect( timeAgo( iso( -3 * 60 * 60 * 1000 ) ) ).toMatch( /3h ago/ );
} );

test( 'nothing in still gives the helper its own answer', () => {
	expect( timeAgo( '' ) ).toBe( '-' );
} );
