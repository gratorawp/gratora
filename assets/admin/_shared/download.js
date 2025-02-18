import apiFetch from '@wordpress/api-fetch';

/**
 * Trigger a file download through the WP REST API.
 *
 * Plain anchor links bypass wp-api-fetch's nonce middleware; apiFetch with
 * `parse: false` applies the nonce and returns the raw Response, which we
 * read as a blob and trigger via a hidden anchor.
 */
export async function downloadFile( path, fallbackFilename = 'download' ) {
    const res = await apiFetch( { path, parse: false } );
    if ( ! res.ok ) {
        let detail = '';
        try { detail = ( await res.json() )?.message || ''; } catch ( _ ) {}
        throw new Error( detail || `Download failed (${ res.status })` );
    }
    const blob = await res.blob();

    const cd = res.headers.get( 'Content-Disposition' ) || '';
    const m  = cd.match( /filename="?([^";]+)"?/i );
    const filename = m ? m[ 1 ] : fallbackFilename;

    const url = URL.createObjectURL( blob );
    const a = document.createElement( 'a' );
    a.href = url;
    a.download = filename;
    document.body.appendChild( a );
    a.click();
    a.remove();
    // Defer revoke so Safari/iOS pick the blob up before we free it.
    setTimeout( () => URL.revokeObjectURL( url ), 1500 );
}
