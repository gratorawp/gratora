/**
 * Loads the PayPal JS SDK on demand.
 *
 * The SDK URL carries the client id, currency and enabled flows, so a form for
 * a different currency needs a different script. Loads are cached per URL and
 * the in-flight promise is shared, so two mounts never inject the tag twice.
 */

const loaded = new Map();

function sdkUrl( { clientId, currency, vault } ) {
    const params = new URLSearchParams( {
        'client-id': clientId,
        currency,
        // Subscriptions need vault=true and the subscription intent; one-time
        // uses a plain capture. The two cannot share one SDK load.
        ...( vault
            ? { vault: 'true', intent: 'subscription' }
            : { intent: 'capture' } ),
        components: 'buttons',
        // Venmo is off unless asked for. PayPal still only shows it to eligible
        // buyers (US, USD), so requesting it is safe everywhere else.
        'enable-funding': 'venmo',
    } );
    return `https://www.paypal.com/sdk/js?${ params.toString() }`;
}

export function loadPayPalSdk( { clientId, currency, vault = false } ) {
    if ( ! clientId ) {
        return Promise.reject( new Error( 'Missing PayPal client id' ) );
    }

    const url = sdkUrl( { clientId, currency, vault } );
    if ( loaded.has( url ) ) {
        return loaded.get( url );
    }

    const promise = new Promise( ( resolve, reject ) => {
        const script = document.createElement( 'script' );
        script.src = url;
        script.async = true;
        // Distinct namespace per URL so the one-time and subscription SDKs can
        // coexist: the second load would otherwise overwrite window.paypal.
        const ns = 'donoPayPal' + ( vault ? 'Sub' : 'One' );
        script.setAttribute( 'data-namespace', ns );
        script.onload = () => {
            const sdk = window[ ns ];
            if ( sdk ) resolve( sdk );
            else reject( new Error( 'PayPal SDK loaded but exposed nothing' ) );
        };
        script.onerror = () => {
            // Let a later attempt retry rather than caching the failure.
            loaded.delete( url );
            reject( new Error( 'PayPal SDK failed to load' ) );
        };
        document.head.appendChild( script );
    } );

    loaded.set( url, promise );
    return promise;
}
