/**
 * The panel measures a ground while the picker is still moving; the server
 * measures the same ground when it renders. A disagreement means the screen
 * promises a contrast the page does not have.
 */

import { rgb, inkOn, ratio, bestOn, derivedInk } from '../../assets/_shared/ink';

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
        const out = derivedInk( { 'fundkit-accent': '#ffd400' } );

        expect( out[ '--fundkit-on-accent' ] ).toBe( '#10162a' );
        expect( out[ '--fundkit-on-accent-muted' ] ).toBe( 'rgba(16,22,42,.62)' );
        expect( out[ '--fundkit-on-accent-line' ] ).toBe( 'rgba(16,22,42,.16)' );
    } );

    it( 'measures a dark accent the same way', () => {
        expect( derivedInk( { 'fundkit-accent': '#211d3f' } )[ '--fundkit-on-accent' ] ).toBe( '#ffffff' );
    } );

    it( 'keeps the accent on the total where it reads and stands it down where it does not', () => {
        expect( derivedInk( { 'fundkit-bg-soft': '#f8fafb', 'fundkit-accent': '#211d3f' } )[ '--fundkit-on-soft-accent' ] )
            .toBe( '#211d3f' );
        expect( derivedInk( { 'fundkit-bg-soft': '#05a2f0', 'fundkit-accent': '#452ef5' } )[ '--fundkit-on-soft-accent' ] )
            .toBe( '#10162a' );
    } );

    it( 'gives the fields ink of their own', () => {
        expect( derivedInk( { 'fundkit-field-bg': '#ffffff' } )[ '--fundkit-on-field' ] ).toBe( '#10162a' );
        expect( derivedInk( { 'fundkit-field-bg': '#101828' } )[ '--fundkit-on-field' ] ).toBe( '#ffffff' );
    } );

    it( 'contributes nothing for a ground it cannot read', () => {
        expect( derivedInk( { 'fundkit-accent': 'inherit' } ) ).toEqual( {} );
        expect( derivedInk( {} ) ).toEqual( {} );
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
    expect( derivedInk( { 'fundkit-accent': 'hsl(249, 37%, 18%)' } )[ '--fundkit-on-accent' ] ).toBe( '#ffffff' );
} );
