// Canvas-authored labels arrive HTML-entity-encoded (RichText storage) and the
// runtime renders them as plain text nodes, so decode them for display. Never
// apply to strings rendered via innerHTML - those are already HTML and
// decoding would reintroduce markup.

const NAMED = { amp: '&', lt: '<', gt: '>', quot: '"' };

export function decodeEntities( s ) {
    if ( typeof s !== 'string' || s.indexOf( '&' ) === -1 ) return s;
    return s.replace( /&(amp|lt|gt|quot|#\d+);/g, ( match, code ) => {
        if ( code[ 0 ] !== '#' ) return NAMED[ code ];
        const n = parseInt( code.slice( 1 ), 10 );
        return n >= 0 && n <= 0x10FFFF ? String.fromCodePoint( n ) : match;
    } );
}
