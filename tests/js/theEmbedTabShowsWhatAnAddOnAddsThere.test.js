/**
 * Add-ons that put a form somewhere other than a page of this site belong in
 * the form's Embed tab, beside the shortcode. They register a panel on the
 * form-embed surface and are handed the form as it was last saved, and whether
 * the editor holds changes that are not saved yet.
 */

import { render } from 'preact';
import { act } from 'preact/test-utils';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );
jest.mock( '@wordpress/api-fetch', () => jest.fn( () => new Promise( () => {} ) ) );

// The editor's chrome is @wordpress/components on ariakit, which does not
// render under preact/compat. None of it is what this measures.
jest.mock( '@wordpress/components', () => new Proxy( {}, {
    get: ( _t, key ) => ( key === '__esModule' ? true : ( props ) => <div>{ props.children }</div> ),
} ) );

jest.mock( '@wordpress/block-editor', () => new Proxy( {}, {
    get: ( _t, key ) => ( key === '__esModule' ? true : () => null ),
} ) );

jest.mock( '@wordpress/interface', () => new Proxy( {}, {
    get: ( _t, key ) => ( key === '__esModule' ? true : () => null ),
} ) );

window.matchMedia = () => ( {
    matches: false, media: '', onchange: null,
    addListener: () => {}, removeListener: () => {},
    addEventListener: () => {}, removeEventListener: () => {},
    dispatchEvent: () => false,
} );

const { EmbedSection } = require( '../../assets/admin/forms/Editor' );

function registry( panels ) {
    return {
        register: () => {},
        get: ( surface ) => ( surface === 'form-embed' ? panels : [] ),
    };
}

const form = {
    savedRecord: { id: 12, slug: 'spring-gala-2026', status: 'published', title: 'Gala', blocks: '' },
    isEdited:    () => false,
    value:       ( k, f = '' ) => f,
};

beforeEach( () => {
    document.body.innerHTML = '<div id="root"></div>';
} );

afterEach( () => {
    delete window.gratora;
} );

it( 'mounts a panel an add-on registered, with the saved form and whether there are unsaved changes', async () => {
    const mount = jest.fn( ( node ) => {
        node.textContent = 'Shown on other websites';
    } );
    window.gratora = { panels: registry( [ { id: 'elsewhere', mount } ] ) };

    await act( async () => {
        render( <EmbedSection c={ form } dirty />, document.getElementById( 'root' ) );
    } );

    expect( mount ).toHaveBeenCalledTimes( 1 );
    expect( mount.mock.calls[ 0 ][ 1 ] ).toEqual( {
        formId: 12,
        slug: 'spring-gala-2026',
        status: 'published',
        isDirty: true,
    } );
    expect( document.body.textContent ).toContain( 'Shown on other websites' );
    expect( document.body.textContent ).toContain( 'slug="spring-gala-2026"' );
} );
