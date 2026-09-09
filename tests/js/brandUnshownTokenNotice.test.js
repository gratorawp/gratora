/**
 * theme.json writes a button radius in whatever unit it likes. The slider that
 * edits it reads whole pixels and clamps to its own range, so a theme that set
 * 1rem is shown as 1 and a pill as the maximum. The row now takes such a value
 * as text; this names it above the panel, where every group but the first is
 * collapsed.
 */

import { render } from 'preact';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

jest.mock( '@wordpress/api-fetch', () => jest.fn( () => new Promise( () => {} ) ) );
jest.mock( '@wordpress/components', () => ( {
	__esModule: true,
	Button: ( { children } ) => <button type="button">{ children }</button>,
} ) );
jest.mock( '../../assets/admin/_shared/styling/TokenEditor', () => ( { __esModule: true, default: () => null } ) );
jest.mock( '../../assets/admin/_shared/styling/StylePreview', () => ( { __esModule: true, default: () => null } ) );

import { PresetEditor } from '../../assets/admin/settings/panels/BrandPanel';

const CATALOGUE = {
    'gratora-radius-sm': {
        label: 'Small corner radius', control: 'range', min: 0, max: 16, step: 1, default: '8px',
    },
};

beforeEach( () => { window.gratora = { styling: { catalogue: CATALOGUE } }; } );
afterEach( () => { delete window.gratora; } );

function mount( tokens ) {
    document.body.innerHTML = '<div id="root"></div>';
    const host = document.getElementById( 'root' );

    render(
        <PresetEditor
            preset={ { id: 'theme', name: 'Site theme', tokens } }
            resetDefaults={ { 'gratora-radius-sm': '8px' } }
            isDefault
            onRename={ () => {} }
            onTokens={ () => {} }
            onMakeDefault={ () => {} }
            onClone={ () => {} }
            onDelete={ () => {} }
        />,
        host
    );

    return host;
}

it( 'names a radius the slider would read as 1', () => {
    const host = mount( { 'gratora-radius-sm': '1rem' } );

    expect( host.textContent ).toContain( 'Small corner radius' );
    expect( host.textContent ).toContain( '1rem' );
} );

it( 'names a pill the slider would clamp to its maximum', () => {
    expect( mount( { 'gratora-radius-sm': '9999px' } ).textContent ).toContain( '9999px' );
} );

it( 'says nothing about a value the slider shows honestly', () => {
    const host = mount( { 'gratora-radius-sm': '8px' } );

    expect( host.querySelector( '.gratora-preset-editor__unshown' ) ).toBeNull();
} );
