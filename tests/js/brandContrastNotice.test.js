/**
 * Text colours follow the ground on their own now, so the one thing an org can
 * still get wrong is a ground no ink carries. A colour picker cannot show that
 * and the preview only shows it to an eye that is looking for it.
 */

import { render } from 'preact';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

jest.mock( '@wordpress/api-fetch', () => jest.fn( () => new Promise( () => {} ) ) );
// The real Button drags in ariakit, which does not mount under preact here.
jest.mock( '@wordpress/components', () => ( {
	__esModule: true,
	Button: ( { children } ) => <button type="button">{ children }</button>,
} ) );
jest.mock( '../../assets/admin/_shared/styling/TokenEditor', () => ( { __esModule: true, default: () => null } ) );
jest.mock( '../../assets/admin/_shared/styling/StylePreview', () => ( { __esModule: true, default: () => null } ) );

import { PresetEditor } from '../../assets/admin/settings/panels/BrandPanel';

function mount( tokens ) {
    document.body.innerHTML = '<div id="root"></div>';
    const host = document.getElementById( 'root' );

    render(
        <PresetEditor
            preset={ { id: 'classic', name: 'Classic', tokens } }
            resetDefaults={ { 'fundkit-bg': '#ffffff', 'fundkit-bg-soft': '#f8fafb', 'fundkit-accent': '#211d3f' } }
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

it( 'says nothing about a palette that reads', () => {
    expect( mount( {} ).querySelector( '.fundkit-preset-editor__contrast' ) ).toBeNull();
} );

it( 'names a ground no ink can carry, and what it measures', () => {
    const text = mount( { 'fundkit-bg': '#ed1212' } ).textContent;

    expect( text ).toContain( '#ED1212' );
    expect( text ).toContain( '4.0:1' );
} );

it( 'names each failing ground once', () => {
    const items = mount( { 'fundkit-bg': '#ed1212', 'fundkit-bg-soft': '#767676' } )
        .querySelectorAll( '.fundkit-preset-editor__contrast li' );

    expect( items ).toHaveLength( 2 );
} );

/** The shipped ink was chosen against the shipped ground, so it must stay quiet. */
it( 'stays quiet on the palette that ships', () => {
    expect( mount( { 'fundkit-accent': '#211d3f' } ).querySelector( '.fundkit-preset-editor__contrast' ) ).toBeNull();
} );

it( 'says nothing about a colour it cannot read', () => {
    expect( mount( { 'fundkit-bg': 'inherit' } ).querySelector( '.fundkit-preset-editor__contrast' ) ).toBeNull();
} );
