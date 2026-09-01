/**
 * A URL that is safe to put in an href.
 *
 * Preact does not filter URL schemes the way React 19 does, so a
 * `javascript:` value bound to an href runs on click. The server escapes what
 * it sends, and this is the second half of that: anything reaching a link in
 * this form passes here first, so a new caller cannot reintroduce the hole by
 * binding a value the server did not clean.
 *
 * Relative URLs are kept - they carry no scheme and cannot execute.
 */
const SAFE_SCHEMES = [ 'http:', 'https:', 'mailto:', 'tel:' ];

export function safeUrl( value ) {
    const url = String( value ?? '' ).trim();
    if ( url === '' ) {
        return '';
    }

    // No scheme means relative, which is safe. Parsing against a base is what
    // resolves "//evil.example" and other scheme-relative forms honestly.
    let parsed;
    try {
        parsed = new URL( url, window.location.origin );
    } catch ( e ) {
        return '';
    }

    return SAFE_SCHEMES.includes( parsed.protocol ) ? url : '';
}

export default safeUrl;
