/**
 * The campaign's own appearance card edits the same tokens through the same
 * slider, so a theme-derived radius is misread there too.
 */

import { render } from 'preact';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

jest.mock( '@wordpress/api-fetch', () => jest.fn( () => new Promise( () => {} ) ) );
jest.mock( '../../assets/admin/_shared/styling/TokenEditor', () => ( { __esModule: true, default: () => null } ) );

import { AppearancePanel } from '../../assets/admin/campaigns/Detail';

const CATALOGUE = {
    'fundkit-radius-sm': {
        label: 'Small corner radius', control: 'range', min: 0, max: 16, step: 1, default: '8px',
    },
};

beforeEach( () => {
    window.fundkit = {
        styling: {
            catalogue: CATALOGUE,
            groups:    {},
            defaults:  { 'fundkit-radius-sm': '8px' },
            presets:   [ { id: 'theme', name: 'Site theme', tokens: { 'fundkit-radius-sm': '1rem' } } ],
        },
    };
} );
afterEach( () => { delete window.fundkit; } );

function mount( style ) {
    document.body.innerHTML = '<div id="root"></div>';
    const host = document.getElementById( 'root' );

    const saved = { id: 3, style };
    const c = {
        record: { ...saved },
        savedRecord: saved,
        edits: {},
        isEdited: () => false,
        value: ( k, f = '' ) => c.record[ k ] ?? f,
        edit: () => {},
    };

    render( <AppearancePanel c={ c } />, host );
    return host;
}

it( 'names the theme radius the slider would read as 1', () => {
    const host = mount( { preset_id: 'theme', tokens: {} } );

    expect( host.textContent ).toContain( 'Small corner radius' );
    expect( host.textContent ).toContain( '1rem' );
} );

it( 'says nothing once the campaign overrides it with a pixel size', () => {
    const host = mount( { preset_id: 'theme', tokens: { 'fundkit-radius-sm': '6px' } } );

    expect( host.querySelector( '.fundkit-preset-editor__unshown' ) ).toBeNull();
} );
