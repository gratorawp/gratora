/**
 * Three catalogue tokens tell the admin to leave the colour empty to inherit,
 * and the divider block says the same about its line. The colour control only
 * ever sets a colour, so the one instruction the help text gives has no control
 * behind it. Empty is what the server stores as absent: Tokens::sanitize drops
 * an empty value, and the stylesheet's var() fallback is what then paints.
 */

import { render } from 'preact';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

// The real Dropdown mounts ariakit, which does not survive preact's renderer.
jest.mock( '@wordpress/components', () => ( {
    __esModule: true,
    Dropdown:      ( { renderToggle } ) => renderToggle( { isOpen: false, onToggle: () => {} } ),
    ColorPicker:   () => null,
    PanelBody:     ( { title, children } ) => <div><h2>{ title }</h2>{ children }</div>,
    Button:        ( { children, onClick } ) => <button type="button" onClick={ onClick }>{ children }</button>,
    RangeControl:  () => null,
    SelectControl: () => null,
    TextControl:   () => null,
} ) );

jest.mock( '@wordpress/block-editor', () => ( {
    useBlockProps: ( p ) => p || {},
    InspectorControls: ( { children } ) => children,
    InnerBlocks: Object.assign( () => null, { ButtonBlockAppender: () => null, Content: () => null } ),
} ) );

// The condition panel reads the block-editor store, which is not what these
// cases are about and needs the whole editor to exist.
jest.mock( '../../assets/admin/forms/blocks/_shared/condition', () => ( {
    DEFAULT_CONDITION: { enabled: false },
    ConditionPanel: () => null,
} ) );

import ColorInput from '../../assets/admin/_shared/components/ColorInput';
import TokenEditor from '../../assets/admin/_shared/styling/TokenEditor';

const CATALOGUE = {
    'fundkit-button-bg': {
        group: 'buttons', label: 'Button background', control: 'color', default: '',
        help: 'Leave empty to use the accent color.',
    },
    'fundkit-button-fg': {
        group: 'buttons', label: 'Button text color', control: 'color', default: '',
        help: 'Leave empty to use white on filled buttons.',
    },
};

const GROUPS = { buttons: 'Buttons' };

function mount( node ) {
    document.body.innerHTML = '<div id="root"></div>';
    const host = document.getElementById( 'root' );
    render( node, host );
    return host;
}

const named = ( host, name ) =>
    [ ...host.querySelectorAll( 'button' ) ].find( ( b ) => b.getAttribute( 'aria-label' ) === name );

afterEach( () => { document.body.innerHTML = ''; } );

it( 'sets a colour back to empty', () => {
    const seen = [];
    const host = mount(
        <ColorInput label="Line colour" value="#ff0000" onChange={ ( v ) => seen.push( v ) } />
    );

    named( host, 'Clear Line colour' ).click();

    expect( seen ).toEqual( [ '' ] );
} );

it( 'offers nothing to clear on a colour that is already empty', () => {
    const host = mount( <ColorInput label="Line colour" value="" onChange={ () => {} } /> );

    expect( host.querySelector( '.fundkit-color__clear' ) ).toBeNull();
    expect( named( host, 'Line colour' ) ).toBeTruthy();
} );

it( 'names the colour it clears', () => {
    const labelled = mount( <ColorInput label="Line colour" value="#ff0000" onChange={ () => {} } /> );
    expect( named( labelled, 'Clear Line colour' ) ).toBeTruthy();

    const bare = mount( <ColorInput value="#ff0000" onChange={ () => {} } /> );
    expect( named( bare, 'Clear color' ) ).toBeTruthy();
} );

/**
 * A control inside the swatch button would be unreachable: a button cannot
 * carry another, and the picker would open on the way to it.
 */
it( 'clears from a button of its own, beside the swatch', () => {
    const host = mount( <ColorInput label="Line colour" value="#ff0000" onChange={ () => {} } /> );

    const clear = host.querySelector( '.fundkit-color__clear' );

    expect( clear.tagName ).toBe( 'BUTTON' );
    expect( clear.getAttribute( 'type' ) ).toBe( 'button' );
    expect( clear.disabled ).toBe( false );
    expect( clear.closest( '.fundkit-color' ) ).toBeNull();
} );

it( 'drops the token from the map rather than storing an empty one', () => {
    const seen = [];
    const host = mount(
        <TokenEditor
            value={ { 'fundkit-button-bg': '#ff0000' } }
            defaults={ { 'fundkit-button-bg': '', 'fundkit-button-fg': '' } }
            catalogue={ CATALOGUE }
            groups={ GROUPS }
            onChange={ ( v ) => seen.push( v ) }
        />
    );

    named( host, 'Clear Button background' ).click();

    expect( seen ).toHaveLength( 1 );
    expect( 'fundkit-button-bg' in seen[ 0 ] ).toBe( false );
    expect( seen[ 0 ] ).toEqual( {} );
} );

it( 'leaves the other tokens where they are', () => {
    const seen = [];
    const host = mount(
        <TokenEditor
            value={ { 'fundkit-button-bg': '#ff0000', 'fundkit-button-fg': '#ffffff' } }
            defaults={ { 'fundkit-button-bg': '', 'fundkit-button-fg': '' } }
            catalogue={ CATALOGUE }
            groups={ GROUPS }
            onChange={ ( v ) => seen.push( v ) }
        />
    );

    named( host, 'Clear Button background' ).click();

    expect( seen[ 0 ] ).toEqual( { 'fundkit-button-fg': '#ffffff' } );
} );

/**
 * Every colour row otherwise announces itself as its own hex, or as 'Pick a
 * color' before it has one, so a panel of them is a list of controls a screen
 * reader cannot tell apart.
 */
it( 'gives each colour row the name of the token it edits', () => {
    const host = mount(
        <TokenEditor
            value={ {} }
            defaults={ { 'fundkit-button-bg': '', 'fundkit-button-fg': '' } }
            catalogue={ CATALOGUE }
            groups={ GROUPS }
            onChange={ () => {} }
        />
    );

    expect( named( host, 'Button background' ) ).toBeTruthy();
    expect( named( host, 'Button text color' ) ).toBeTruthy();
} );

/**
 * Three colours in one inspector, and Field names none of them: without a label
 * of its own every clear button answers to 'Clear color', which is the panel a
 * screen reader cannot navigate.
 */
describe( 'the section block', () => {
    const register = () => require( '../../assets/admin/forms/blocks/section/index' ).default;

    const ATTRS = {
        background: '#ff0000',
        textColor:  '#00ff00',
        border:     { color: '#0000ff', width: 1, style: 'solid', radius: 0 },
        padding:    { top: 0, right: 0, bottom: 0, left: 0 },
        margin:     { top: 0, right: 0, bottom: 0, left: 0 },
        shadow:     '',
        minHeight:  0,
    };

    function mountBlock( registerDefault, initial ) {
        let settings = null;
        registerDefault( { register: ( name, s ) => { settings = s; } } );

        let attributes = { ...initial };
        document.body.innerHTML = '<div id="root"></div>';
        const host = document.getElementById( 'root' );
        const Edit = settings.edit;
        const draw = () => render(
            <Edit
                attributes={ attributes }
                setAttributes={ ( patch ) => { attributes = { ...attributes, ...patch }; draw(); } }
            />,
            host
        );
        draw();

        return { host, attrs: () => attributes };
    }

    it( 'names each of the section colours in the button that clears it', () => {
        const { host } = mountBlock( register(), ATTRS );

        expect( named( host, 'Clear Background color' ) ).toBeTruthy();
        expect( named( host, 'Clear Text color' ) ).toBeTruthy();
        expect( named( host, 'Clear Border color' ) ).toBeTruthy();
    } );

    it( 'sets a section colour back to unset', () => {
        const { host, attrs } = mountBlock( register(), ATTRS );

        named( host, 'Clear Background color' ).click();

        expect( attrs().background ).toBe( '' );
    } );
} );
