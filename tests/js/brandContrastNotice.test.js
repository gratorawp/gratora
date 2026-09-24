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
            resetDefaults={ { 'gratora-bg': '#ffffff', 'gratora-bg-soft': '#f8fafb', 'gratora-accent': '#211d3f' } }
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
    expect( mount( {} ).querySelector( '.gratora-preset-editor__contrast' ) ).toBeNull();
} );

/** White reaches 4.47:1 on this red, the better of the two inks. */
it( 'names a ground no ink can carry, and what it measures', () => {
    const text = mount( { 'gratora-bg': '#ed1212' } ).textContent;

    expect( text ).toContain( '#ED1212' );
    expect( text ).toContain( '4.4:1' );
} );

/** White reaches 4.50, 4.51 and 4.54:1 on these, where the dark ink stops under 4.0. */
it( 'says nothing about a ground white text reads on', () => {
    for ( const ground of [ '#0072f0', '#006ffa', '#767676' ] ) {
        expect( mount( { 'gratora-accent': ground, 'gratora-bg': ground } ).querySelector( '.gratora-preset-editor__contrast' ) ).toBeNull();
    }
} );

it( 'names each failing ground once', () => {
    const items = mount( { 'gratora-bg': '#ed1212', 'gratora-bg-soft': '#777777' } )
        .querySelectorAll( '.gratora-preset-editor__contrast li' );

    expect( items ).toHaveLength( 2 );
} );

/** The shipped ink was chosen against the shipped ground, so it must stay quiet. */
it( 'stays quiet on the palette that ships', () => {
    expect( mount( { 'gratora-accent': '#211d3f' } ).querySelector( '.gratora-preset-editor__contrast' ) ).toBeNull();
} );

it( 'says nothing about a colour it cannot read', () => {
    expect( mount( { 'gratora-bg': 'inherit' } ).querySelector( '.gratora-preset-editor__contrast' ) ).toBeNull();
} );

/** Red measures 4.497:1 with dark ink. Rounded, it would read as the very bar it misses. */
it( 'never states a figure that meets the bar it says is missed', () => {
    const text = mount( { 'gratora-accent': '#ff0000' } ).textContent;

    expect( text ).toContain( 'Accent (#FF0000) reaches 4.4:1, under the 4.5:1 that text needs.' );
} );
