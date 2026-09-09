/**
 * The shared UI package ships its strings under its own text domain, and it is
 * consumed by several plugins that each declare a different one, so no domain
 * hardcoded upstream can be right. Rewriting at build time is what makes the
 * runtime lookup and the wp.org language pack agree.
 */
const FROM = 'gratora-fundraising-campaigns';
const TO   = 'gratora';

function rewrite( source ) {
    return source.split( `'${ FROM }'` ).join( `'${ TO }'` )
        .split( `"${ FROM }"` ).join( `"${ TO }"` );
}

module.exports = function ( source ) {
    return rewrite( source );
};

module.exports.rewrite = rewrite;
