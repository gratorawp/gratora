/** @jsxImportSource preact */

import { useCallback, useEffect, useRef, useState } from 'preact/hooks';
import { loadRazorpayCheckout } from '../util/razorpay';
import { formatAmount } from '../util/format';

/**
 * Opens Razorpay Checkout for a donation that is already on file as pending.
 * The donor pays by UPI, card, netbanking or wallet in Razorpay's own modal and
 * never leaves the page.
 *
 * The order or subscription was created server-side, so the browser only
 * reports back what the donor authorised: the payment id and Razorpay's
 * signature over it. The server verifies that signature against the id it
 * stored before any money is recorded.
 */
export default function RazorpayPayment( { config, payment, dispatch } ) {
    const openedRef = useRef( false );
    const [ error, setError ] = useState( '' );
    const [ busy, setBusy ] = useState( false );

    const i18n = config.i18n || {};
    const keyId = config.razorpay?.keyId || '';
    const isSubscription = payment?.razorpay?.kind === 'subscription';
    const restBase = ( config.rest || '' ).replace( /donations\/?$/, '' );

    const open = useCallback( () => {
        if ( ! keyId || ! payment?.razorpay ) {
            setError( i18n.error );
            return;
        }

        setBusy( true );
        setError( '' );

        const post = async ( path, body ) => {
            const headers = { 'Content-Type': 'application/json' };
            if ( config.nonce ) headers[ 'X-WP-Nonce' ] = config.nonce;
            const res = await fetch( restBase + path, {
                method: 'POST',
                headers,
                body: JSON.stringify( body ),
            } );
            const data = await res.json().catch( () => ( {} ) );
            if ( ! res.ok ) {
                throw new Error( data.message || i18n.error );
            }
            return data;
        };

        loadRazorpayCheckout()
            .then( ( Razorpay ) => {
                const checkout = new Razorpay( {
                    key: keyId,
                    // No `name`: Razorpay falls back to the merchant's own
                    // registered name, which is who is actually taking the money.
                    description: i18n.paymentTitle || '',
                    // Amount and currency ride on the order or subscription, so
                    // they are not repeated here. That keeps the browser out of
                    // deciding what gets charged.
                    ...( isSubscription
                        ? { subscription_id: payment.razorpay.subscription_id }
                        : { order_id: payment.razorpay.order_id } ),
                    // Checkout asks for these anyway; the donor already typed
                    // them on the form.
                    prefill: {
                        name: payment.donorName || '',
                        email: payment.donorEmail || '',
                        contact: payment.donorPhone || '',
                    },
                    modal: {
                        ondismiss: () => {
                            // Not a failure: the donation stays pending and the
                            // donor can reopen Checkout and try again.
                            setBusy( false );
                        },
                    },
                    handler: async ( response ) => {
                        try {
                            const out = await post(
                                isSubscription
                                    ? 'gateways/razorpay/subscription'
                                    : 'gateways/razorpay/capture',
                                {
                                    reference: payment.reference,
                                    payment_id: response.razorpay_payment_id,
                                    signature: response.razorpay_signature,
                                }
                            );
                            dispatch( {
                                type: out.status === 'paid' ? 'SUBMIT_SUCCESS' : 'SUBMIT_PENDING',
                                data: out,
                            } );
                        } catch ( err ) {
                            setError( err.message || i18n.error );
                            setBusy( false );
                        }
                    },
                } );

                checkout.on( 'payment.failed', ( resp ) => {
                    setError( resp?.error?.description || i18n.error );
                    setBusy( false );
                } );

                checkout.open();
            } )
            .catch( () => {
                setError( i18n.error );
                setBusy( false );
            } );
        // Deliberately keyed on the payment identity only: config and dispatch
        // are recreated on most renders and would reopen Checkout mid-payment.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [ keyId, isSubscription, payment?.reference ] );

    // Open once on mount: the donor has already pressed donate, so making them
    // press a second button to see Checkout is a step for nothing.
    useEffect( () => {
        if ( openedRef.current ) return;
        openedRef.current = true;
        open();
    }, [ open ] );

    return (
        <div className="dono-form__razorpay">
            { payment?.amountCents ? (
                <p className="dono-form__razorpay-amount">
                    { formatAmount( payment.amountCents, payment.currency, config ) }
                </p>
            ) : null }

            { error ? (
                <div className="dono-form__error" role="alert">{ error }</div>
            ) : null }

            { /* Checkout is a modal, so the page behind it needs a way back in
                 after a dismissal or a declined card. */ }
            <button
                type="button"
                className="dono-form__submit"
                onClick={ open }
                disabled={ busy }
            >
                { busy ? ( i18n.processing || '' ) : ( i18n.payNow || '' ) }
            </button>
        </div>
    );
}
