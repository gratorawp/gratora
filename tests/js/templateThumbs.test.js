/**
 * The picker exists to show where things sit on the page. Core keys its
 * wireframes by template id, so a layout registered by an add-on matched
 * nothing and fell through to the standard drawing: a peer-to-peer campaign
 * offered two templates under one identical picture, which is the picker
 * failing at the only job it has.
 */
import { thumbFor } from '../../assets/admin/_shared/components/CampaignTemplatePicker.jsx';

test( 'a template core knows is drawn as itself, not as the fallback', () => {
	expect( thumbFor( { id: 'minimal' } ) ).not.toEqual( thumbFor( { id: 'standard' } ) );
} );

test( 'a template that brings its own shape is drawn with it', () => {
	const own = { main: [ 'media', 'text' ], form: true, footer: 'rows' };

	expect( thumbFor( { id: 'p2p-editorial', thumb: own } ) ).toBe( own );
} );

test( 'a layout core has never heard of still gets a picture', () => {
	expect( thumbFor( { id: 'something-an-addon-registered' } ) ).toEqual( thumbFor( { id: 'standard' } ) );
} );

test( 'two add-on templates that arrange differently do not draw the same page', () => {
	const classic   = { id: 'p2p-classic',   thumb: { main: [ 'media', 'text', 'list' ], form: true } };
	const editorial = { id: 'p2p-editorial', thumb: { main: [ 'media', 'text' ], form: true, footer: 'rows' } };

	expect( thumbFor( classic ) ).not.toEqual( thumbFor( editorial ) );
} );
