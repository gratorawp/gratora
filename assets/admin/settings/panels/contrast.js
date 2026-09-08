/**
 * What the org's colours measure, for the one screen that chooses them.
 *
 * The server picks ink against a ground (Ink.php) and the panel has to say the
 * same thing while a colour picker is still being dragged, so the rule lives
 * here too. brandContrastAgreement.test.js is what keeps the two the same.
 */

const FLIP = 0.1791;

const ON_DARK  = '#ffffff';
const ON_LIGHT = '#10162a';

/** @return {[number,number,number]|null} */
export function rgb( value ) {
    const v = String( value ?? '' ).trim();

    const hex = v.match( /^#([0-9a-fA-F]{3,8})$/ );
    if ( hex ) {
        let h = hex[ 1 ];
        if ( h.length === 3 || h.length === 4 ) h = h[ 0 ] + h[ 0 ] + h[ 1 ] + h[ 1 ] + h[ 2 ] + h[ 2 ];
        if ( h.length < 6 ) return null;

        return [ parseInt( h.slice( 0, 2 ), 16 ), parseInt( h.slice( 2, 4 ), 16 ), parseInt( h.slice( 4, 6 ), 16 ) ];
    }

    const fn = v.match( /^rgba?\(([^)]*)\)$/i );
    if ( fn ) {
        const parts = fn[ 1 ].split( /[\s,/]+/ ).filter( Boolean ).slice( 0, 3 );
        if ( parts.length < 3 ) return null;
        const out = parts.map( ( p ) => {
            const n = parseFloat( p );
            if ( Number.isNaN( n ) ) return null;
            return Math.round( p.includes( '%' ) ? n * 2.55 : n );
        } );

        return out.some( ( n ) => n === null ) ? null : out;
    }

    return null;
}

export function luminance( value ) {
    const c = rgb( value );
    if ( ! c ) return null;

    const [ r, g, b ] = c.map( ( raw ) => {
        const x = Math.max( 0, Math.min( 255, raw ) ) / 255;
        return x <= 0.03928 ? x / 12.92 : Math.pow( ( x + 0.055 ) / 1.055, 2.4 );
    } );

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** The ink the server will draw on this ground, or null when it cannot read it. */
export function inkOn( ground ) {
    const l = luminance( ground );
    return l === null ? null : ( l > FLIP ? ON_LIGHT : ON_DARK );
}

/** WCAG contrast, or null when either colour cannot be read. */
export function ratio( a, b ) {
    const la = luminance( a );
    const lb = luminance( b );
    if ( la === null || lb === null ) return null;

    return ( Math.max( la, lb ) + 0.05 ) / ( Math.min( la, lb ) + 0.05 );
}

/**
 * The best any ink can do on a ground. Below 4.5 no choice of text colour
 * carries body copy on it, which is the one thing the picker cannot show.
 */
export function bestOn( ground ) {
    const ink = inkOn( ground );
    return ink === null ? null : ratio( ground, ink );
}
