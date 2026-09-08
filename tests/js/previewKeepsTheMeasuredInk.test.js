/**
 * The styling editor posts the authored token map into the preview iframe. The
 * server's derived inks are not in it, and the strip that clears the previous
 * preset takes the old ones with it, so a pale accent previewed as a white
 * label on a white button and an admin rejected a look the published form would
 * in fact render in dark ink.
 */

import { applyPreviewTokens } from '../../assets/donation-form/runtime';

function form() {
    document.body.innerHTML = '<form class="fundkit-donation-form"></form>';

    return document.querySelector( '.fundkit-donation-form' );
}

const read = ( el, name ) => el.style.getPropertyValue( name ).trim();

it( 'measures the ink the server would have sent', () => {
    const el = form();

    applyPreviewTokens( el, { 'fundkit-accent': '#ffd400' } );

    expect( read( el, '--fundkit-accent' ) ).toBe( '#ffd400' );
    expect( read( el, '--fundkit-on-accent' ) ).toBe( '#10162a' );
} );

it( 'gives the fields ink of their own', () => {
    const el = form();

    applyPreviewTokens( el, { 'fundkit-bg': '#0f172a', 'fundkit-field-bg': '#ffffff' } );

    expect( read( el, '--fundkit-on-field' ) ).toBe( '#10162a' );
} );

/** A preset that omits a token must not leave the previous preset's value behind. */
it( 'clears what the previous preset set', () => {
    const el = form();

    applyPreviewTokens( el, { 'fundkit-accent': '#211d3f', 'fundkit-bg': '#101828' } );
    applyPreviewTokens( el, { 'fundkit-accent': '#ffd400' } );

    expect( read( el, '--fundkit-bg' ) ).toBe( '' );
    expect( read( el, '--fundkit-on-accent' ) ).toBe( '#10162a' );
} );

it( 'leaves the stylesheet its fallback for a ground it cannot read', () => {
    const el = form();

    applyPreviewTokens( el, { 'fundkit-accent': 'inherit' } );

    expect( read( el, '--fundkit-on-accent' ) ).toBe( '' );
} );
