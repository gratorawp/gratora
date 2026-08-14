/** @jsxImportSource preact */

import { useEffect, useRef, useState } from 'preact/hooks';
import { loadPayPalSdk } from '../util/paypal';
import { formatAmount } from '../util/format';

/**
 * Renders PayPal's own buttons for a donation that is already on file as
 * pending. The donor pays in PayPal's popup and never leaves the page.
 *
 * One-time approves the Order that the server created at createIntent, then
 * asks the server to capture it. Recurring opens a Subscription against the
 * plan the server provisioned; PayPal takes the first payment on approval, and
 * the server records the plan while the webhook confirms the money.
 *
 * The server is the authority in both cases: this component never decides that
 * a donation is paid, it only reports what the donor approved.
 */
export default function PayPalPayment( { config, payment, dispatch } ) {
    const mountRef = useRef( null );
    const renderedRef = useRef( false );
    const [ ready, setReady ] = useState( false );
    const [ error, setError ] = useState( '' );

    const i18n = config.i18n || {};
    const clientId = config.paypal?.clientId || '';
    const currency = payment?.currency || config.paypal?.currency || 'USD';
    const isSubscription = payment?.paypal?.kind === 'subscription';
    const restBase = ( config.rest || '' ).replace( /donations\/?$/, '' );

    useEffect( () => {
        let cancelled = false;

        if ( ! clientId || ! payment?.paypal ) {
            setError( i18n.error );
            return undefined;
        }
        // Buttons re-render badly if mounted twice under StrictMode-ish churn.
        if ( renderedRef.current ) return undefined;

        const post = async ( path, body ) => {
            const headers = { 'Content-Type': 'application/json' };
            if ( config.nonce ) headers[ 'X-WP-Nonce' ] = config.nonce;
            // A dropped connection rejects with the engine's own untranslated
            // wording, and the callers show err.message to the donor.
            const res = await fetch( restBase + path, {
                method: 'POST',
                headers,
                body: JSON.stringify( body ),
            } ).catch( () => {
                throw new Error( i18n.error );
            } );
            const data = await res.json().catch( () => ( {} ) );
            if ( ! res.ok ) {
                throw new Error( data.message || i18n.error );
            }
            return data;
        };

        loadPayPalSdk( { clientId, currency, vault: isSubscription } )
            .then( ( sdk ) => {
                if ( cancelled || ! mountRef.current ) return;

                const common = {
                    style: { layout: 'vertical', shape: 'rect', label: 'donate' },
                    onError: () => { if ( ! cancelled ) setError( i18n.error ); },
                    onCancel: () => {
                        // Not a failure: the donation stays pending and the
                        // donor can try again with the same buttons.
                        if ( ! cancelled ) setError( '' );
                    },
                };

                const buttons = isSubscription
                    ? sdk.Buttons( {
                        ...common,
                        createSubscription: ( data, actions ) => actions.subscription.create( {
                            plan_id: payment.paypal.plan_id,
                            // Bound to the donation so the server can prove this
                            // subscription belongs to it before recording.
                            custom_id: payment.reference,
                        } ),
                        onApprove: async ( data ) => {
                            try {
                                const out = await post( 'gateways/paypal/subscription', {
                                    reference: payment.reference,
                                    status_token: payment.statusToken,
                                    subscription_id: data.subscriptionID,
                                } );
                                if ( ! cancelled ) {
                                    // The first payment is taken, so the donor
                                    // is finished even though the record only
                                    // settles when the webhook lands.
                                    dispatch( out.status === 'paid'
                                        ? { type: 'SUBMIT_SUCCESS', data: out }
                                        : { type: 'SUBMIT_PENDING', data: out, processing: true } );
                                }
                            } catch ( err ) {
                                if ( ! cancelled ) setError( err.message || i18n.error );
                            }
                        },
                    } )
                    : sdk.Buttons( {
                        ...common,
                        // The order already exists server-side; approving it is
                        // all the browser is trusted to do.
                        createOrder: () => payment.paypal.order_id,
                        onApprove: async () => {
                            try {
                                const out = await post( 'gateways/paypal/capture', {
                                    // Proves this browser is the one that
                                    // submitted the donation. References are
                                    // sequential, so without it a guess is
                                    // enough to make someone else pay.
                                    reference: payment.reference,
                                    status_token: payment.statusToken,
                                } );
                                if ( ! cancelled ) {
                                    dispatch( {
                                        type: out.status === 'paid' ? 'SUBMIT_SUCCESS' : 'SUBMIT_PENDING',
                                        data: out,
                                    } );
                                }
                            } catch ( err ) {
                                if ( ! cancelled ) setError( err.message || i18n.error );
                            }
                        },
                    } );

                if ( ! buttons.isEligible() ) {
                    setError( i18n.error );
                    return;
                }

                renderedRef.current = true;
                buttons.render( mountRef.current ).then( () => {
                    if ( ! cancelled ) setReady( true );
                } ).catch( () => {
                    if ( ! cancelled ) setError( i18n.error );
                } );
            } )
            .catch( () => { if ( ! cancelled ) setError( i18n.error ); } );

        return () => { cancelled = true; };
        // Deliberately keyed on the payment identity only. config, dispatch and
        // i18n are recreated on most renders, and re-running would tear down
        // and re-render PayPal's iframe mid-approval.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [ clientId, currency, isSubscription, payment?.reference ] );

    return (
        <div className="dono-form__paypal">
            { payment?.amountCents ? (
                <p className="dono-form__paypal-amount">
                    { formatAmount( payment.amountCents, payment.currency, config ) }
                </p>
            ) : null }

            { error ? (
                <div className="dono-form__error" role="alert">{ error }</div>
            ) : null }

            { ! ready && ! error ? (
                <p className="dono-form__paypal-loading">{ i18n.paymentLoading || 'Loading secure payment…' }</p>
            ) : null }

            <div ref={ mountRef } className="dono-form__paypal-buttons" />
        </div>
    );
}
