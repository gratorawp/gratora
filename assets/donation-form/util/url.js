/** Allow safe schemes and relative URLs; Preact does not reject javascript: hrefs. */
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
