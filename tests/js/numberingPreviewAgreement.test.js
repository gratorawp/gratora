/**
 * The preview promises the operator a reference. The generator mints one. A
 * value stored before the settings boundary started refusing bad characters is
 * where the two can disagree, and the screen is the half that is wrong.
 */

const { asRefToken } = require( '../../assets/admin/settings/panels/NumberingPanel' );

/** Mirrors ReferenceGenerator::sanitizeToken. */
const generatorWouldMint = ( raw, fallback ) => {
    const clean = String( raw ?? '' ).replace( /[^A-Za-z0-9_-]/g, '' );
    return clean !== '' ? clean : fallback;
};

const cases = [
    [ 'AC/DC',        'DONATION' ],
    [ 'HOPE 2026',    'DONATION' ],
    [ '.',            '-' ],
    [ '',             'DONATION' ],
    [ 'GIFT',         'DONATION' ],
    [ 'a-b_c',        'DONATION' ],
    [ 'Ünïcode',      'DONATION' ],
];

test.each( cases )( 'the preview of %p is what the generator mints', ( raw, fallback ) => {
    expect( asRefToken( raw, fallback ) ).toBe( generatorWouldMint( raw, fallback ) );
} );

test( 'a prefix stored before the boundary refused it still previews truthfully', () => {
    // The exact case: the panel used to draw DONATION here while the generator
    // minted ACDC, so the operator read a reference that never existed.
    expect( asRefToken( 'AC/DC', 'DONATION' ) ).toBe( 'ACDC' );
} );
