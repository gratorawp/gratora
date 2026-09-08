/**
 * Settings > Brand previews a campaign page, and nothing in that preview was a
 * field, so Field background and the ink measured against it painted nothing an
 * admin could see. A dark page putting white ink in white fields passed review
 * and reached donors.
 */

import path from 'path';
import * as sass from 'sass';
import { render } from 'preact';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

import StylePreview from '@fundkit/ui/styling/StylePreview';

const pkg = path.dirname( require.resolve( '@fundkit/ui/package.json' ) );
const PREVIEW = sass.compile( path.join( pkg, 'src/scss/components/_style-preview.scss' ) ).css;

function mount( tokens = {} ) {
    document.body.innerHTML = '<div id="root"></div>';
    const host = document.getElementById( 'root' );

    render( <StylePreview tokens={ tokens } layer="brand" styling={ {} } />, host );

    return host;
}

it( 'shows boxes a donor would type in', () => {
    const host = mount();

    expect( host.querySelectorAll( '.fundkit-style-preview__field-box' ).length ).toBeGreaterThan( 1 );
    expect( host.textContent ).toContain( 'Other amount' );
    expect( host.textContent ).toContain( 'Full name' );
} );

it( 'draws them under the element the token map is declared on', () => {
    const frame = mount( { 'fundkit-field-bg': '#101828' } )
        .querySelector( '.fundkit-style-preview__frame' );

    expect( frame.style.getPropertyValue( '--fundkit-field-bg' ).trim() ).toBe( '#101828' );
    expect( frame.querySelector( '.fundkit-style-preview__field-box' ) ).not.toBeNull();
} );

// jsdom loads no stylesheet, so what the box is painted with is checked against
// the compiled sheet the admin screen actually ships.
it( 'paints them with the field ground and the ink measured against it', () => {
    const box = PREVIEW.slice( PREVIEW.indexOf( '.fundkit-style-preview__field-box' ) );
    const rule = box.slice( 0, box.indexOf( '}' ) );

    expect( rule ).toContain( 'var(--fundkit-field-bg' );
    expect( rule ).toContain( 'var(--fundkit-on-field' );
} );

/** A tabbable control here would be editable-looking, and the panel around it finds its own buttons by scanning. */
it( 'stays a picture, not a form the admin can tab into', () => {
    expect( mount().querySelector( 'input, textarea, select, button, [tabindex]' ) ).toBeNull();
} );
