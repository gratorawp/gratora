/**
 * The back link sat on the form editor saying "Campaign overview" while
 * pointing at the campaign's Forms tab, and a form belongs to a campaign only
 * optionally: without one it linked to campaign id 0, which is not a page.
 */

import { formsBackHref, campaignHref } from '../../assets/admin/forms/format';

beforeEach( () => {
	window.history.replaceState( {}, '', '/wp-admin/admin.php' );
} );

test( 'a form in a campaign goes back to that campaign\'s forms', () => {
	expect( formsBackHref( 12 ) ).toBe( campaignHref( 12 ) );
	expect( formsBackHref( 12 ) ).toContain( 'id=12' );
	expect( formsBackHref( 12 ) ).toContain( 'tab=forms' );
} );

test( 'a form without one goes to the forms list, not to campaign zero', () => {
	for ( const empty of [ 0, null, undefined, '', NaN ] ) {
		const href = formsBackHref( empty );

		expect( href ).toContain( 'page=fundkit-forms' );
		expect( href ).not.toContain( 'id=0' );
		expect( href ).not.toContain( 'fundkit-campaigns' );
	}
} );

test( 'both destinations are a list of forms, so one label fits', () => {
	expect( formsBackHref( 12 ) ).toContain( 'tab=forms' );
	expect( formsBackHref( 0 ) ).toContain( 'page=fundkit-forms' );
} );
