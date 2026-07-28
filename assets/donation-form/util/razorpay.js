/**
 * Loads the Razorpay Checkout script on demand.
 *
 * One script for every mode and currency (the key id passed at open time
 * decides both), so a single cached load serves the whole page.
 */

const SRC = 'https://checkout.razorpay.com/v1/checkout.js';

let loading = null;

export function loadRazorpayCheckout() {
    if ( window.Razorpay ) {
        return Promise.resolve( window.Razorpay );
    }
    if ( loading ) {
        return loading;
    }

    loading = new Promise( ( resolve, reject ) => {
        const script = document.createElement( 'script' );
        script.src = SRC;
        script.async = true;
        script.onload = () => {
            if ( window.Razorpay ) resolve( window.Razorpay );
            else reject( new Error( 'Razorpay Checkout loaded but exposed nothing' ) );
        };
        script.onerror = () => {
            // Let a later attempt retry rather than caching the failure.
            loading = null;
            reject( new Error( 'Razorpay Checkout failed to load' ) );
        };
        document.head.appendChild( script );
    } );

    return loading;
}
