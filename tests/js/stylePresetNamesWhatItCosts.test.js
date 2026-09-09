/**
 * A campaign's inline token overrides reach a form only while the form
 * inherits. Naming a preset here silently drops them, and the picker used to
 * say nothing about it.
 */

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );
jest.mock( '@wordpress/api-fetch', () => jest.fn( () => new Promise( () => {} ) ) );

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

window.matchMedia = () => ( {
    matches: false, media: '', onchange: null,
    addListener: () => {}, removeListener: () => {},
    addEventListener: () => {}, removeEventListener: () => {},
    dispatchEvent: () => false,
} );

window.gratora = {
    styling: {
        presets:    [ { id: 'bold', name: 'Bold' }, { id: 'quiet', name: 'Quiet' } ],
        default_id: 'bold',
        catalogue:  {
            'gratora-accent': { label: 'Accent' },
            'gratora-bg':     { label: 'Background' },
        },
    },
};

const { render } = require( 'preact' );
const { StylePresetField } = require( '../../assets/admin/forms/Editor' );

const campaign = {
    id:    4,
    title: 'Spring Gala',
    style: { tokens: { 'gratora-accent': '#c62828', 'gratora-bg': '#fff8f0' } },
};

beforeEach( () => { document.body.innerHTML = '<div id="root"></div>'; } );

const mount = ( props ) => {
    render( <StylePresetField onChange={ () => {} } { ...props } />, document.getElementById( 'root' ) );
    return document.body.textContent;
};

it( 'warns what a preset would cost while the form still inherits', () => {
    const text = mount( { value: '', campaign } );

    expect( text ).toContain( 'Spring Gala overrides Accent, Background' );
    expect( text ).toContain( 'Picking a preset here stops those overrides reaching this form' );
} );

it( 'says they are already gone once the form names a preset', () => {
    const text = mount( { value: 'bold', campaign } );

    expect( text ).toContain( 'This form is on a preset of its own' );
} );

it( 'stays quiet about a preset nothing answers to, which the resolver ignores', () => {
    const text = mount( { value: 'deleted-preset', campaign } );

    expect( text ).toContain( 'Picking a preset here stops those overrides reaching this form' );
    expect( text ).not.toContain( 'This form is on a preset of its own' );
} );

it( 'says nothing at all about a campaign that overrides nothing', () => {
    const text = mount( { value: 'bold', campaign: { id: 4, title: 'Spring Gala', style: {} } } );

    expect( text ).not.toContain( 'Spring Gala overrides' );
} );
