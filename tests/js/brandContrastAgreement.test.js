/**
 * The panel measures a ground while the picker is still moving; the server
 * measures the same ground when it renders. A disagreement means the screen
 * promises a contrast the page does not have.
 */

import { inkOn, ratio, bestOn } from '../../assets/admin/settings/panels/contrast';

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
