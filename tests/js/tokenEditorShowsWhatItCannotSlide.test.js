/**
 * theme.json writes a corner radius in whatever unit it likes. The slider that
 * edits it reads whole pixels and clamps to its own range, so a row that shows
 * a slider for 1rem states a size the site does not use, and the first drag
 * saves that invented size over the theme's.
 */

import { render } from 'preact';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

// The real PanelBody drags in ariakit, which does not mount under preact here.
jest.mock( '@wordpress/components', () => ( {
    __esModule: true,
    PanelBody: ( { children } ) => <div>{ children }</div>,
    RangeControl: ( { value, onChange, min, max } ) => (
        <input type="range" value={ String( value ) } min={ min } max={ max }
            onInput={ ( e ) => onChange( Number( e.target.value ) ) } />
    ),
    TextControl: ( { value, onChange, help } ) => (
        <label>
            <input type="text" value={ String( value ) } onInput={ ( e ) => onChange( e.target.value ) } />
            <span>{ help }</span>
        </label>
    ),
    SelectControl: () => <select />,
    Button: ( { children, onClick } ) => <button type="button" onClick={ onClick }>{ children }</button>,
    ColorPicker: () => null,
    Dropdown: () => null,
} ) );

import TokenEditor from '@fundkit/ui/styling/TokenEditor';

const CATALOGUE = {
    'fundkit-radius-sm': {
        group: 'radius', label: 'Small corner radius', control: 'range', min: 0, max: 16, step: 1, default: '8px',
    },
};

function mount( defaults, onChange = () => {} ) {
    document.body.innerHTML = '<div id="root"></div>';
    const host = document.getElementById( 'root' );

    render(
        <TokenEditor
            value={ {} }
            defaults={ defaults }
            onChange={ onChange }
            catalogue={ CATALOGUE }
            groups={ { radius: 'Radius' } }
        />,
        host
    );

    return host;
}

it( 'shows a theme rem instead of a slider reading 1', () => {
    const host = mount( { 'fundkit-radius-sm': '1rem' } );

    expect( host.querySelector( 'input[type=range]' ) ).toBeNull();
    expect( host.querySelector( 'input[type=text]' ).value ).toBe( '1rem' );
    expect( host.textContent ).toContain( '0px to 16px' );
} );

it( 'shows a pill instead of a slider clamped to the maximum', () => {
    const host = mount( { 'fundkit-radius-sm': '9999px' } );

    expect( host.querySelector( 'input[type=range]' ) ).toBeNull();
    expect( host.querySelector( 'input[type=text]' ).value ).toBe( '9999px' );
} );

it( 'keeps the slider for a size it can hold', () => {
    const host = mount( { 'fundkit-radius-sm': '8px' } );

    expect( host.querySelector( 'input[type=text]' ) ).toBeNull();
    expect( host.querySelector( 'input[type=range]' ).value ).toBe( '8' );
} );

it( 'keeps the slider when nothing is set', () => {
    expect( mount( {} ).querySelector( 'input[type=range]' ) ).not.toBeNull();
} );

it( 'writes what was typed over an unslidable value', () => {
    let out = null;
    const host = mount( { 'fundkit-radius-sm': '1rem' }, ( v ) => { out = v; } );

    const field = host.querySelector( 'input[type=text]' );
    field.value = '0.5rem';
    field.dispatchEvent( new Event( 'input', { bubbles: true } ) );

    expect( out ).toEqual( { 'fundkit-radius-sm': '0.5rem' } );
} );

it( 'still writes pixels from the slider', () => {
    let out = null;
    const host = mount( { 'fundkit-radius-sm': '8px' }, ( v ) => { out = v; } );

    const slider = host.querySelector( 'input[type=range]' );
    slider.value = '6';
    slider.dispatchEvent( new Event( 'input', { bubbles: true } ) );

    expect( out ).toEqual( { 'fundkit-radius-sm': '6px' } );
} );
