/**
 * The shared UI package ships its strings under its own text domain, which no
 * consumer declares, so 84 admin strings could never be translated: the runtime
 * lookup misses and the wp.org language pack never carries them.
 */

const { rewrite } = require( '../../build-tools/fundkitUiDomain.cjs' );

it( 'rewrites the package domain to the one this plugin declares', () => {
    expect( rewrite( "__( 'Close', 'fundkit-fundraising-campaigns' )" ) )
        .toBe( "__( 'Close', 'fundraising-toolkit' )" );
} );

it( 'handles double quotes too, since the dist build emits both', () => {
    expect( rewrite( '__("Close", "fundkit-fundraising-campaigns")' ) )
        .toBe( '__("Close", "fundraising-toolkit")' );
} );

it( 'leaves a string that is not the domain alone', () => {
    const src = "const slug = 'fundkit-fundraising-campaigns-something';";

    expect( rewrite( src ) ).toBe( src );
} );

it( 'leaves this plugin\'s own strings alone', () => {
    const src = "__( 'Donors', 'fundraising-toolkit' )";

    expect( rewrite( src ) ).toBe( src );
} );
