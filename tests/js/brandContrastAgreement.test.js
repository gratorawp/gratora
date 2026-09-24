/**
 * The panel measures a ground while the picker is still moving; the server
 * measures the same ground when it renders. A disagreement means the screen
 * promises a contrast the page does not have.
 */

import { rgb, inkOn, inkPair, mix, ratio, bestOn, derivedInk } from '../../assets/_shared/ink';

/** The cases InkTest pins on the PHP side, with the ink it chooses. */
const cases = [
    [ '#000000', '#ffffff' ],
    [ '#211d3f', '#ffffff' ],
    [ '#14425f', '#ffffff' ],
    [ '#2563eb', '#ffffff' ],
    [ '#101828', '#ffffff' ],
    [ '#ffffff', '#10162a' ],
    [ '#ffe066', '#10162a' ],
    [ '#d6f5e3', '#10162a' ],
    [ '#fff', '#10162a' ],
    [ '#f8fafb', '#10162a' ],
    [ '#05a2f0', '#10162a' ],
    [ '#ed1212', '#10162a' ],
    [ 'rgb(20, 66, 95)', '#ffffff' ],
    [ 'rgba(255, 224, 102, 0.9)', '#10162a' ],
];

test.each( cases )( 'the ink on %p is the ink the server picks', ( ground, ink ) => {
    expect( inkOn( ground ) ).toBe( ink );
} );

test( 'a colour the control never stores measures nothing', () => {
    expect( inkOn( 'var(--wp--preset--color--x)' ) ).toBeNull();
    expect( ratio( '#fff', 'inherit' ) ).toBeNull();
    expect( bestOn( 'transparent' ) ).toBeNull();
} );

test( 'white and black are the extremes the scale is anchored on', () => {
    expect( ratio( '#ffffff', '#000000' ) ).toBeCloseTo( 21, 1 );
    expect( ratio( '#ffffff', '#ffffff' ) ).toBeCloseTo( 1, 5 );
} );

/**
 * The reading the panel exists to show: a mid-luminance ground carries no body
 * text whichever ink is drawn on it, and only a measurement says so.
 */
test( 'a mid red carries no body text either way', () => {
    expect( bestOn( '#ed1212' ) ).toBeLessThan( 4.5 );
} );

test( 'the shipped page ground carries it comfortably', () => {
    expect( bestOn( '#ffffff' ) ).toBeGreaterThan( 4.5 );
} );

/**
 * The preview iframe is handed the authored map with the server's derived inks
 * stripped out, so it has to produce the same three families Ink.php emits.
 * These are the values InkTest pins on the PHP side.
 */
describe( 'the inks the server would have emitted', () => {
    it( 'measures a pale accent the way the published form does', () => {
        const out = derivedInk( { 'gratora-accent': '#ffd400' } );

        expect( out[ '--gratora-on-accent' ] ).toBe( '#10162a' );
        expect( out[ '--gratora-on-accent-muted' ] ).toBe( 'rgba(16,22,42,.62)' );
        expect( out[ '--gratora-on-accent-line' ] ).toBe( 'rgba(16,22,42,.16)' );
    } );

    it( 'measures a dark accent the same way', () => {
        expect( derivedInk( { 'gratora-accent': '#211d3f' } )[ '--gratora-on-accent' ] ).toBe( '#ffffff' );
    } );

    it( 'keeps the accent on the total where it reads and stands it down where it does not', () => {
        expect( derivedInk( { 'gratora-bg-soft': '#f8fafb', 'gratora-accent': '#211d3f' } )[ '--gratora-on-soft-accent' ] )
            .toBe( '#211d3f' );
        expect( derivedInk( { 'gratora-bg-soft': '#05a2f0', 'gratora-accent': '#452ef5' } )[ '--gratora-on-soft-accent' ] )
            .toBe( '#10162a' );
    } );

    it( 'gives the fields ink of their own', () => {
        expect( derivedInk( { 'gratora-field-bg': '#ffffff' } )[ '--gratora-on-field' ] ).toBe( '#10162a' );
        expect( derivedInk( { 'gratora-field-bg': '#101828' } )[ '--gratora-on-field' ] ).toBe( '#ffffff' );
    } );

    /** Only the ground tokens remain, each naming a token, so the stylesheet chain stands. */
    it( 'measures nothing for a ground it cannot read', () => {
        for ( const out of [ derivedInk( { 'gratora-accent': 'inherit' } ), derivedInk( {} ) ] ) {
            expect( Object.keys( out ).sort() ).toEqual( Object.keys( GROUND_TOKENS ).sort() );
            Object.values( out ).forEach( ( v ) => expect( v ).toMatch( /^var\(--gratora-[a-z-]+\)$/ ) );
        }
    } );
} );

/**
 * Muted ink is the ink at the lowest alpha that still reaches 4.5:1 on its
 * ground, and the ink itself where none below opaque does. InkTest pins the
 * same table.
 */
test.each( [
    [ '#ffffff', 'rgba(16,22,42,.62)' ],
    [ '#15142b', 'rgba(255,255,255,.72)' ],
    [ '#221f3d', 'rgba(255,255,255,.72)' ],
    [ '#fde68a', 'rgba(16,22,42,.62)' ],
    [ '#f55151', 'rgba(16,22,42,.86)' ],
    [ '#452ef5', 'rgba(255,255,255,.74)' ],
    [ '#2563eb', 'rgba(255,255,255,.91)' ],
    [ '#ed1212', '#10162a' ],
    [ '#777777', '#10162a' ],
] )( 'the muted ink on %p is the one the server measures', ( ground, muted ) => {
    expect( inkPair( ground )[ 1 ] ).toBe( muted );
} );

const GROUND_TOKENS = {
    '--gratora-text-accent':    'var(--gratora-text)',
    '--gratora-on-bg':          'var(--gratora-text)',
    '--gratora-on-bg-muted':    'var(--gratora-text-muted)',
    '--gratora-on-bg-accent':   'var(--gratora-accent)',
    '--gratora-on-accent-soft': 'var(--gratora-accent)',
};

const pick = ( out ) => Object.fromEntries( Object.keys( GROUND_TOKENS ).map( ( k ) => [ k, out[ k ] ] ) );

const PAGE_INK = { 'gratora-text': '#111827', 'gratora-text-muted': '#6b7280' };

/** The card, the selected tint and the accent as page text, as Ink::groundDeclarations emits them. */
describe( 'the ink for each ground the server would have emitted', () => {
    it( 'names only tokens for the palette that ships, which it already paints', () => {
        expect( pick( derivedInk( { ...PAGE_INK, 'gratora-accent': '#211d3f', 'gratora-accent-soft': '#efedf8', 'gratora-bg': '#ffffff' } ) ) )
            .toEqual( { ...GROUND_TOKENS, '--gratora-text-accent': 'var(--gratora-accent)' } );
    } );

    it( 'measures a dark card under a pale accent', () => {
        expect( mix( '#fde68a', '#15142b', 0.12 ) ).toBe( '#312d36' );
        expect( pick( derivedInk( { ...PAGE_INK, 'gratora-accent': '#fde68a', 'gratora-bg': '#15142b', 'gratora-bg-soft': '#221f3d' } ) ) ).toEqual( {
            '--gratora-text-accent':    'var(--gratora-text)',
            '--gratora-on-bg':          '#ffffff',
            '--gratora-on-bg-muted':    'rgba(255,255,255,.72)',
            '--gratora-on-bg-accent':   'var(--gratora-accent)',
            '--gratora-on-accent-soft': 'var(--gratora-accent)',
        } );
    } );

    it( 'keeps page ink on a card it reads on, and measures the muted ink it does not', () => {
        const out = derivedInk( { ...PAGE_INK, 'gratora-accent': '#211d3f', 'gratora-bg': '#f55151' } );

        expect( out[ '--gratora-on-bg' ] ).toBe( 'var(--gratora-text)' );
        expect( out[ '--gratora-on-bg-muted' ] ).toBe( 'rgba(16,22,42,.86)' );
    } );

    it( 'stands a pale accent down on its own tint', () => {
        const out = derivedInk( { ...PAGE_INK, 'gratora-accent': '#ffee58', 'gratora-accent-soft': '#fffdeb', 'gratora-bg': '#ffffff' } );

        expect( out[ '--gratora-on-accent-soft' ] ).toBe( '#10162a' );
    } );

    it( 'reverses out of a dark tint mixed from the accent and the card', () => {
        const out = derivedInk( { ...PAGE_INK, 'gratora-accent': '#452ef5', 'gratora-bg': '#804242' } );

        expect( out[ '--gratora-on-accent-soft' ] ).toBe( '#ffffff' );
    } );
} );

/** The required marker on the page and on the card, as Ink::requiredDeclarations emits it. */
describe( 'the required marker the server would have emitted', () => {
    it( 'keeps the mix where it reads', () => {
        const out = derivedInk( { ...PAGE_INK, 'gratora-bg': '#15142b' } );

        expect( out[ '--gratora-text-required' ] ).toBe( '#9f2b6a' );
        expect( out[ '--gratora-on-bg-required' ] ).toBe( '#e16ca6' );
    } );

    it( 'takes more ink on a card that defeats the mix', () => {
        expect( derivedInk( { ...PAGE_INK, 'gratora-bg': '#f55151' } )[ '--gratora-on-bg-required' ] ).toBe( '#321d37' );
    } );
} );

test( 'a malformed number is a colour to neither side', () => {
    expect( rgb( 'hsl(1.2.3, 50%, 50%)' ) ).toBeNull();
} );

test( 'the channels are the channels the server reads', () => {
    expect( rgb( 'rgb(50%, 50%, 50%)' ) ).toEqual( [ 128, 128, 128 ] );
    expect( rgb( 'rgb(70%, 90%, 60%)' ) ).toEqual( [ 179, 230, 153 ] );
    expect( rgb( 'hsl(0, 100%, 5%)' ) ).toEqual( [ 26, 0, 0 ] );
    expect( rgb( 'hsl(0, 100%, 95%)' ) ).toEqual( [ 255, 230, 230 ] );
} );

// The theme preset lifts palette colours out of theme.json, which takes any CSS
// colour, so a ground the server measures and the preview cannot read leaves the
// admin looking at black on black while the page renders white on it.
test( 'an hsl ground carries the same ink on both sides', () => {
    expect( inkOn( 'hsl(249, 37%, 18%)' ) ).toBe( '#ffffff' );
    expect( inkOn( 'hsl(210deg 40% 92%)' ) ).toBe( '#10162a' );
    expect( derivedInk( { 'gratora-accent': 'hsl(249, 37%, 18%)' } )[ '--gratora-on-accent' ] ).toBe( '#ffffff' );
} );
