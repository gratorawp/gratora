/**
 * The Donate button is the largest accent ground on the Develop canvas, and it
 * paints that ground itself. Resolving the declarations it renders against the
 * canvas token map is what the browser does, so this is the ink an author sees.
 */

import { render } from 'preact';

let painted = null;

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

jest.mock( '@wordpress/block-editor', () => ( {
    useBlockProps: () => ( {} ),
    InspectorControls: () => null,
    RichText: ( props ) => {
        painted = props.style;
        return <span>{ props.value }</span>;
    },
} ) );

jest.mock( '@wordpress/components', () => ( { PanelBody: () => null } ) );

window.matchMedia = () => ( {
    matches: false, addListener: () => {}, removeListener: () => {},
    addEventListener: () => {}, removeEventListener: () => {},
} );

const registerSubmitButton = require( '../../assets/admin/forms/blocks/submit-button' ).default;

let Edit;
registerSubmitButton( { register: ( name, settings ) => { Edit = settings.edit; } } );
const { canvasStyle } = require( '../../assets/admin/forms/Editor' );

// What a browser does with a var() chain: first property that is set wins,
// otherwise the fallback, recursively.
function resolve( value, tokens ) {
    const m = /^var\(\s*(--[\w-]+)\s*(?:,\s*([\s\S]+))?\)$/.exec( String( value ).trim() );
    if ( ! m ) return String( value ).trim();
    if ( tokens[ m[ 1 ] ] !== undefined && tokens[ m[ 1 ] ] !== '' ) return tokens[ m[ 1 ] ];
    return m[ 2 ] === undefined ? '' : resolve( m[ 2 ], tokens );
}

const styling = {
    defaults:   { 'fundkit-accent': '#211d3f', 'fundkit-accent-soft': '#efedf8' },
    presets:    [ { id: 'sunny', tokens: { 'fundkit-accent': '#ffd400' } } ],
    default_id: 'classic',
};

function buttonStyle() {
    painted = null;
    const host = document.createElement( 'div' );
    render( <Edit attributes={ {} } setAttributes={ () => {} } />, host );
    return painted;
}

it( 'draws a dark label on a pale accent, as the published button does', () => {
    const tokens = canvasStyle( { style: { preset_id: 'sunny' } }, null, styling );
    const style  = buttonStyle();

    expect( resolve( style.background, tokens ) ).toBe( '#ffd400' );
    expect( resolve( style.color, tokens ) ).toBe( '#10162a' );
} );

it( 'and a white one on a dark accent', () => {
    const tokens = canvasStyle( { style: { preset_id: '' } }, null, styling );
    const style  = buttonStyle();

    expect( resolve( style.color, tokens ) ).toBe( '#ffffff' );
} );

it( 'lets an author-set button colour win over the measured ink', () => {
    const tokens = canvasStyle(
        { style: { preset_id: 'sunny', tokens: { 'fundkit-button-fg': '#004400' } } },
        null,
        styling
    );

    expect( resolve( buttonStyle().color, tokens ) ).toBe( '#004400' );
} );
