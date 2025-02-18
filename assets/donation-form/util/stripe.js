/**
 * Lazy-loads Stripe.js from Stripe's CDN. Stripe requires the script be served
 * from js.stripe.com (PCI scope), so it is never bundled. Cached after the
 * first call; safe to call from multiple forms on one page.
 */

let loader = null;

export function loadStripeJs() {
    if ( window.Stripe ) return Promise.resolve( window.Stripe );
    if ( loader ) return loader;

    loader = new Promise( ( resolve, reject ) => {
        const done = () => ( window.Stripe ? resolve( window.Stripe ) : reject( new Error( 'stripe-unavailable' ) ) );

        const existing = document.querySelector( 'script[src^="https://js.stripe.com/"]' );
        if ( existing ) {
            if ( window.Stripe ) { resolve( window.Stripe ); return; }
            existing.addEventListener( 'load', done );
            existing.addEventListener( 'error', () => reject( new Error( 'stripe-load-failed' ) ) );
            return;
        }

        const s = document.createElement( 'script' );
        s.src = 'https://js.stripe.com/v3/';
        s.async = true;
        s.onload = done;
        s.onerror = () => reject( new Error( 'stripe-load-failed' ) );
        document.head.appendChild( s );
    } );

    return loader;
}

/**
 * Reads the markers a Stripe redirect appends to return_url. Returns null when
 * this is a normal page load (not a payment return).
 */
export function detectStripeReturn() {
    const params = new URLSearchParams( window.location.search );
    const clientSecret = params.get( 'payment_intent_client_secret' );
    if ( ! clientSecret || params.get( 'dono_return' ) !== '1' ) return null;
    return {
        clientSecret,
        reference: params.get( 'dono_ref' ) || '',
        redirectStatus: params.get( 'redirect_status' ) || '',
    };
}

/** Retrieves the PaymentIntent status after a redirect-based confirmation. */
export async function resolveStripeReturn( publishableKey, clientSecret ) {
    const Stripe = await loadStripeJs();
    const stripe = Stripe( publishableKey );
    const { paymentIntent } = await stripe.retrievePaymentIntent( clientSecret );
    return paymentIntent ? paymentIntent.status : null;
}

/** Strips the Stripe return markers from the address bar without reloading. */
export function clearStripeReturnParams() {
    try {
        const url = new URL( window.location.href );
        [ 'payment_intent', 'payment_intent_client_secret', 'redirect_status', 'dono_return', 'dono_ref' ]
            .forEach( ( k ) => url.searchParams.delete( k ) );
        window.history.replaceState( {}, '', url.toString() );
    } catch {
        // Older browsers / opaque origins: leaving the params is harmless.
    }
}

