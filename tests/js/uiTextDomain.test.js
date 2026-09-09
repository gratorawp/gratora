/**
 * The shared UI package ships its strings under its own text domain, which no
 * consumer declares, so 84 admin strings could never be translated: the runtime
 * lookup misses and the wp.org language pack never carries them.
 */

const { rewrite } = require( '../../build-tools/gratoraUiDomain.cjs' );

it( 'rewrites the package domain to the one this plugin declares', () => {
    expect( rewrite( "__( 'Close', 'gratora-fundraising-campaigns' )" ) )
        .toBe( "__( 'Close', 'gratora' )" );
} );

it( 'handles double quotes too, since the dist build emits both', () => {
    expect( rewrite( '__("Close", "gratora-fundraising-campaigns")' ) )
        .toBe( '__("Close", "gratora")' );
} );

it( 'leaves a string that is not the domain alone', () => {
    const src = "const slug = 'gratora-fundraising-campaigns-something';";

    expect( rewrite( src ) ).toBe( src );
} );

it( 'leaves this plugin\'s own strings alone', () => {
    const src = "__( 'Donors', 'gratora' )";

    expect( rewrite( src ) ).toBe( src );
} );
