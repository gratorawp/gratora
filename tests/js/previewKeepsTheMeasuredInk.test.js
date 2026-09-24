/**
 * The styling editor posts the authored token map into the preview iframe. The
 * server's derived inks are not in it, and the strip that clears the previous
 * preset takes the old ones with it, so a pale accent previewed as a white
 * label on a white button and an admin rejected a look the published form would
 * in fact render in dark ink.
 */

import { applyPreviewTokens } from '../../assets/donation-form/runtime';

function form() {
    document.body.innerHTML = '<form class="gratora-donation-form"></form>';

    return document.querySelector( '.gratora-donation-form' );
}

const read = ( el, name ) => el.style.getPropertyValue( name ).trim();

it( 'measures the ink the server would have sent', () => {
    const el = form();

    applyPreviewTokens( el, { 'gratora-accent': '#ffd400' } );

    expect( read( el, '--gratora-accent' ) ).toBe( '#ffd400' );
    expect( read( el, '--gratora-on-accent' ) ).toBe( '#10162a' );
} );

it( 'gives the fields ink of their own', () => {
    const el = form();

    applyPreviewTokens( el, { 'gratora-bg': '#0f172a', 'gratora-field-bg': '#ffffff' } );

    expect( read( el, '--gratora-on-field' ) ).toBe( '#10162a' );
} );

/** The page ink is the page's; the card a dark map chooses takes ink of its own. */
it( 'measures the card ink and leaves the page ink alone', () => {
    const el = form();

    applyPreviewTokens( el, { 'gratora-bg': '#15142b', 'gratora-text': '#111827' } );

    expect( read( el, '--gratora-text' ) ).toBe( '#111827' );
    expect( read( el, '--gratora-on-bg' ) ).toBe( '#ffffff' );
} );

/** On a red card the pink mixed toward the ink reads 2.04:1, so the preview measures the marker as the server does. */
it( 'measures the required marker on the card', () => {
    const el = form();

    applyPreviewTokens( el, { 'gratora-bg': '#f55151', 'gratora-text': '#111827' } );

    expect( read( el, '--gratora-text-required' ) ).toBe( '#9f2b6a' );
    expect( read( el, '--gratora-on-bg-required' ) ).toBe( '#321d37' );
} );

/** A preset that omits a token must not leave the previous preset's value behind. */
it( 'clears what the previous preset set', () => {
    const el = form();

    applyPreviewTokens( el, { 'gratora-accent': '#211d3f', 'gratora-bg': '#101828' } );
    applyPreviewTokens( el, { 'gratora-accent': '#ffd400' } );

    expect( read( el, '--gratora-bg' ) ).toBe( '' );
    expect( read( el, '--gratora-on-accent' ) ).toBe( '#10162a' );
} );

it( 'leaves the stylesheet its fallback for a ground it cannot read', () => {
    const el = form();

    applyPreviewTokens( el, { 'gratora-accent': 'inherit' } );

    expect( read( el, '--gratora-on-accent' ) ).toBe( '' );
} );
