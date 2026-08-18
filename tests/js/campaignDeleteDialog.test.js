/**
 * The delete confirmation is the only description of the cascade the admin ever
 * reads, and the campaign's WordPress page is removed with wp_delete_post($id,
 * true): past the trash, with nothing to restore. Anything the dialog leaves
 * out is something the admin agrees to lose without knowing it.
 */

import apiFetch from '@wordpress/api-fetch';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
// Webpack aliases react to preact/compat for the admin bundles, and this screen
// only loads under that alias.
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

import { campaignDeleteMessage } from '../../assets/admin/campaigns/Detail';
import { campaignsDeleteMessage } from '../../assets/admin/campaigns/List';

/** Answers the forms probe with a total, the one call the message makes. */
function seedFormsTotal( total ) {
    apiFetch.mockImplementation( () => Promise.resolve( {
        headers: { get: () => String( total ) },
    } ) );
}

beforeEach( () => {
    apiFetch.mockReset();
} );

test( 'a campaign with a page is told the page goes too and cannot be recovered', async () => {
    seedFormsTotal( 2 );

    const message = await campaignDeleteMessage( { id: 7, page_id: 31 } );

    expect( message ).toContain( '2 forms' );
    expect( message ).toContain( 'WordPress page' );
    expect( message ).toContain( 'does not go to the trash' );
    expect( message ).toContain( 'This cannot be undone.' );
} );

test( 'a campaign with no page is not warned about one', async () => {
    seedFormsTotal( 0 );

    const message = await campaignDeleteMessage( { id: 7, page_id: null } );

    expect( message ).not.toContain( 'page' );
    expect( message ).toContain( 'This cannot be undone.' );
} );

test( 'a forms probe that fails still names the page', async () => {
    apiFetch.mockImplementation( () => Promise.reject( new Error( 'network down' ) ) );

    const message = await campaignDeleteMessage( { id: 7, page_id: 31 } );

    expect( message ).toContain( 'WordPress page' );
} );

/**
 * The list screen's row and bulk delete call the same route, so it destroys the
 * same pages. It is also the path most admins reach for, and the one selection
 * where only some of the campaigns have a page.
 */
describe( 'the list screen says the same thing', () => {
    test( 'a selection containing a page is warned about it', () => {
        const message = campaignsDeleteMessage( [
            { id: 1, page_id: 31 },
            { id: 2, page_id: null },
        ] );

        expect( message ).toContain( '1 of them has a WordPress page' );
        expect( message ).toContain( 'rather than sent to the trash' );
        expect( message ).toContain( 'This cannot be undone.' );
    } );

    test( 'a single campaign with a page is warned in the singular', () => {
        const message = campaignsDeleteMessage( [ { id: 1, page_id: 31 } ] );

        expect( message ).toContain( 'Permanently delete this campaign?' );
        expect( message ).toContain( 'The WordPress page it created is deleted with it' );
    } );

    test( 'a selection with no pages is not warned about one', () => {
        const message = campaignsDeleteMessage( [ { id: 1 }, { id: 2 } ] );

        expect( message ).not.toContain( 'WordPress page' );
        expect( message ).toContain( 'This cannot be undone.' );
    } );
} );
