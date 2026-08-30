/**
 * The campaign settings screen prints the address donors land on. WordPress
 * owns the page slug and appends a suffix when another page already holds it,
 * without telling the campaign row, so an address built from that row can point
 * at an unrelated page while the header's own View page button goes elsewhere.
 */

import { render } from 'preact';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

import { PublicAddressCard } from '../../assets/admin/campaigns/Detail';

/** A record controller holding a campaign slug, with nothing edited. */
const controller = ( slug, edited = false ) => ( {
    value:    ( key, fallback ) => ( key === 'slug' ? slug : fallback ),
    isEdited: () => edited,
    bind:     () => ( { value: slug, onInput: () => {} } ),
    edits:    {},
} );

function mount( node ) {
    document.body.innerHTML = '<div id="root"></div>';
    render( node, document.getElementById( 'root' ) );
    return document.getElementById( 'root' );
}

test( 'the public URL is the page permalink, not the campaign slug', () => {
    const root = mount(
        <PublicAddressCard
            c={ controller( 'support-us' ) }
            pageUrl="https://example.test/campaigns/support-us-2/"
        />
    );

    const link = root.querySelector( '.fundkit-url-preview a' );
    expect( link.getAttribute( 'href' ) ).toBe( 'https://example.test/campaigns/support-us-2/' );

    const shown = root.querySelector( '.fundkit-url-preview .url' ).textContent;
    expect( shown ).toContain( 'example.test/campaigns/support-us-2' );
    expect( shown ).not.toContain( 'support-us<' );
} );

test( 'a permalink outside the campaigns prefix is shown as it is', () => {
    const root = mount(
        <PublicAddressCard
            c={ controller( 'support-us' ) }
            pageUrl="https://example.test/?page_id=41"
        />
    );

    expect( root.querySelector( '.fundkit-url-preview a' ).getAttribute( 'href' ) ).toBe( 'https://example.test/?page_id=41' );
    expect( root.querySelector( '.fundkit-url-preview .url' ).textContent ).not.toContain( '/campaigns/' );
} );

test( 'a campaign with no page advertises no address at all', () => {
    const root = mount( <PublicAddressCard c={ controller( 'support-us' ) } pageUrl={ null } /> );

    expect( root.querySelector( '.fundkit-url-preview' ) ).toBeNull();
    expect( root.textContent ).toContain( 'no page yet' );
} );
