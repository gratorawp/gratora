/**
 * Preact does not filter URL schemes the way React 19 does, so a
 * `javascript:` value bound to an href runs when the donor clicks it. Block
 * attributes are not run through kses - sanitizeBlocks only reaches
 * innerContent - so this value travelled from the form editor to the payment
 * page unexamined, on the one page where a donor is typing card details.
 */

import { safeUrl } from '../../assets/donation-form/util/url';

describe( 'a link the donation form is willing to render', () => {
	it.each( [
		'javascript:alert(1)',
		'JaVaScRiPt:alert(1)',
		'  javascript:alert(1)  ',
		'java\tscript:alert(1)',
		'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
		'vbscript:msgbox(1)',
	] )( 'refuses %s', ( hostile ) => {
		expect( safeUrl( hostile ) ).toBe( '' );
	} );

	it.each( [
		'https://example.org/terms',
		'http://example.org/terms',
		'mailto:hello@example.org',
		'/terms',
		'terms.html',
		'//example.org/terms',
	] )( 'keeps %s', ( ok ) => {
		expect( safeUrl( ok ) ).toBe( ok );
	} );

	it( 'treats nothing as nothing', () => {
		expect( safeUrl( '' ) ).toBe( '' );
		expect( safeUrl( null ) ).toBe( '' );
		expect( safeUrl( undefined ) ).toBe( '' );
	} );
} );
