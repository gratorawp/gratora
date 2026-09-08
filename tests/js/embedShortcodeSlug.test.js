/**
 * The server normalises a slug through sanitize_title on save, so "Spring Gala
 * 2026" becomes spring-gala-2026. The Embed tab built its shortcode from what
 * was typed, so an author who copied it before saving pasted a shortcode naming
 * a form that will never exist, onto a live page, and got nothing.
 */

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );
jest.mock( '@wordpress/api-fetch', () => jest.fn( () => new Promise( () => {} ) ) );

// The editor's chrome is @wordpress/components on ariakit, which does not
// render under preact/compat. None of it is what this measures.
jest.mock( '@wordpress/components', () => {
    const passthrough = ( name ) => ( props ) => (
        <div data-wp={ name }>{ props.label }{ props.value }{ props.help }{ props.children }</div>
    );

    return new Proxy( {}, {
        get: ( _t, key ) => ( key === '__esModule' ? true : passthrough( String( key ) ) ),
    } );
} );

jest.mock( '@wordpress/block-editor', () => new Proxy( {}, {
    get: ( _t, key ) => ( key === '__esModule' ? true : () => null ),
} ) );

jest.mock( '@wordpress/interface', () => new Proxy( {}, {
    get: ( _t, key ) => ( key === '__esModule' ? true : () => null ),
} ) );

jest.mock( '../../assets/admin/_shared/notify', () => ( {
    __esModule: true,
    notify:  { success: jest.fn(), error: jest.fn(), info: jest.fn() },
    default: { success: jest.fn(), error: jest.fn(), info: jest.fn() },
} ) );

// Set before the editor is required: @wordpress/interface subscribes to
// breakpoints at import time.
window.matchMedia = () => ( {
    matches: false, media: '', onchange: null,
    addListener: () => {}, removeListener: () => {},
    addEventListener: () => {}, removeEventListener: () => {},
    dispatchEvent: () => false,
} );

const { render } = require( 'preact' );
const { EmbedSection } = require( '../../assets/admin/forms/Editor' );

const shortcodeValue = () => {
    const field = document.querySelector( 'input, textarea, code' );

    return field ? ( field.value ?? field.textContent ) : document.body.textContent;
};

function record( { saved = 'spring-gala-2026', edited = null } = {} ) {
    return {
        savedRecord: { id: 12, slug: saved, title: 'Gala', blocks: '' },
        isEdited:    ( k ) => k === 'slug' && edited !== null,
        value:       ( k, f = '' ) => ( k === 'slug' ? ( edited ?? saved ) : f ),
    };
}

beforeEach( () => { document.body.innerHTML = '<div id="root"></div>'; } );

const mount = ( c ) => render( <EmbedSection c={ c } />, document.getElementById( 'root' ) );

it( 'names the slug the server stored', () => {
    mount( record() );

    expect( shortcodeValue() ).toContain( 'slug="spring-gala-2026"' );
} );

it( 'does not name the slug the author is still typing', () => {
    mount( record( { edited: 'Spring Gala 2026' } ) );

    expect( shortcodeValue() ).toContain( 'slug="spring-gala-2026"' );
    expect( shortcodeValue() ).not.toContain( 'Spring Gala 2026' );
} );

it( 'says the shortcode is behind an unsaved edit', () => {
    mount( record( { edited: 'Spring Gala 2026' } ) );

    expect( document.body.textContent ).toContain( 'Save the form' );
} );

it( 'and says nothing when there is no unsaved edit', () => {
    mount( record() );

    expect( document.body.textContent ).not.toContain( 'Save the form' );
} );
