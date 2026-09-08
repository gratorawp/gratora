/**
 * What the org's colours measure.
 *
 * The server picks ink against a ground (Ink.php). Two clients have to say the
 * same thing without asking it: the brand panel, while a colour picker is still
 * being dragged, and the donation form inside a preview iframe, which is handed
 * a token map with the derived inks stripped out of it.
 * brandContrastAgreement.test.js is what keeps all three the same.
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

const ON_LIGHT_MUTED = 'rgba(16,22,42,.62)';
const ON_DARK_MUTED  = 'rgba(255,255,255,.72)';
const ON_LIGHT_LINE  = 'rgba(16,22,42,.16)';
const ON_DARK_LINE   = 'rgba(255,255,255,.26)';

const pair = ( ground ) => {
    const ink = inkOn( ground );
    if ( ink === null ) return null;

    return ink === ON_DARK
        ? [ ink, ON_DARK_MUTED, ON_DARK_LINE ]
        : [ ink, ON_LIGHT_MUTED, ON_LIGHT_LINE ];
};

/**
 * The properties Ink.php derives and emits alongside the authored map, keyed
 * the way a style attribute wants them. A ground it cannot read contributes
 * nothing, so the stylesheet's own fallback stands.
 *
 * @param {Record<string,string>} tokens the authored map, without the leading --
 * @return {Record<string,string>}
 */
export function derivedInk( tokens = {} ) {
    const out = {};

    const accent = pair( tokens[ 'fundkit-accent' ] );
    if ( accent ) {
        out[ '--fundkit-on-accent' ] = accent[ 0 ];
        out[ '--fundkit-on-accent-muted' ] = accent[ 1 ];
        out[ '--fundkit-on-accent-line' ] = accent[ 2 ];
    }

    const soft = pair( tokens[ 'fundkit-bg-soft' ] );
    if ( soft ) {
        out[ '--fundkit-on-soft' ] = soft[ 0 ];
        out[ '--fundkit-on-soft-muted' ] = soft[ 1 ];

        const accentValue = tokens[ 'fundkit-accent' ];
        const carries = ratio( accentValue, tokens[ 'fundkit-bg-soft' ] );
        out[ '--fundkit-on-soft-accent' ] = carries !== null && carries >= 4.5 ? accentValue : soft[ 0 ];
    }

    const field = pair( tokens[ 'fundkit-field-bg' ] );
    if ( field ) {
        out[ '--fundkit-on-field' ] = field[ 0 ];
        out[ '--fundkit-on-field-muted' ] = field[ 1 ];
    }

    return out;
}
