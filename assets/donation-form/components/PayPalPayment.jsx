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
    const buttonsRef = useRef( null );
    const [ ready, setReady ] = useState( false );
    const [ error, setError ] = useState( '' );
    // Cancel is safe only while nothing is approved and no round trip is in
    // flight. popupOpen covers the seconds PayPal's window sits over ours;
    // approved covers the wait for the capture or the plan record, which
    // completes server-side whether or not this browser is still here.
    const [ popupOpen, setPopupOpen ] = useState( false );
    const [ approved, setApproved ] = useState( false );

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
                    onClick: () => { if ( ! cancelled ) setPopupOpen( true ); },
                    onError: () => {
                        if ( cancelled ) return;
                        setPopupOpen( false );
                        setError( i18n.error );
                    },
                    onCancel: () => {
                        // Not a failure: the donation stays pending and the
                        // donor can try again with the same buttons.
                        if ( cancelled ) return;
                        setPopupOpen( false );
                        setError( '' );
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
                            // PayPal's window is gone by the time it approves.
                            setPopupOpen( false );
                            // A refusal is not true of the attempt that follows
                            // it, and a screen that says both stalls the donor.
                            setError( '' );
                            setApproved( true );
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
                                if ( cancelled ) return;
                                // The subscription exists at PayPal and its
                                // first payment is taken, so approving again
                                // would mint a second one: the buttons stay
                                // down and Cancel is the way out.
                                setError( err.message || i18n.error );
                            }
                        },
                    } )
                    : sdk.Buttons( {
                        ...common,
                        // The order already exists server-side; approving it is
                        // all the browser is trusted to do.
                        createOrder: () => payment.paypal.order_id,
                        onApprove: async () => {
                            setPopupOpen( false );
                            setError( '' );
                            setApproved( true );
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
                                if ( cancelled ) return;
                                setError( err.message || i18n.error );
                                // A capture PayPal refused took no money, and
                                // INSTRUMENT_DECLINED is the common refusal, so
                                // the buttons come back for another funding
                                // source. The order is still the same one.
                                setApproved( false );
                            }
                        },
                    } );

                if ( ! buttons.isEligible() ) {
                    setError( i18n.error );
                    return;
                }

                renderedRef.current = true;
                buttonsRef.current = buttons;
                buttons.render( mountRef.current ).then( () => {
                    if ( ! cancelled ) setReady( true );
                } ).catch( () => {
                    if ( ! cancelled ) setError( i18n.error );
                } );
            } )
            .catch( () => { if ( ! cancelled ) setError( i18n.error ); } );

        return () => {
            cancelled = true;
            // Cancel takes this component off screen while the SDK is live, and
            // zoid outlives the container Preact removes: its iframe and its
            // window listeners stay behind unless it is told to close.
            try {
                buttonsRef.current?.close();
            } catch ( e ) {
                // Already torn down, which is the outcome either way.
            }
            buttonsRef.current = null;
        };
        // Deliberately keyed on the payment identity only. config, dispatch and
        // i18n are recreated on most renders, and re-running would tear down
        // and re-render PayPal's iframe mid-approval.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [ clientId, currency, isSubscription, payment?.reference ] );

    return (
        // The form's own shell, the same one StripePayment declares, so the
        // reset and the themed typography reach this component on the path
        // where a form has no gateway block and nothing else supplies them.
        <div className="dono-form dono-form--payment">
            { payment?.amountCents ? (
                <p className="dono-form__payment-amount">
                    { formatAmount( payment.amountCents, payment.currency, config ) }
                </p>
            ) : null }

            { error ? (
                <div className="dono-form__error" role="alert">{ error }</div>
            ) : null }

            { ! ready && ! error ? (
                <p className="dono-form__payment-loading">{ i18n.paymentLoading || 'Loading secure payment…' }</p>
            ) : null }

            { /* Approving is not the end of the work: the capture or the plan
               record still has to come back. PayPal's buttons stay mounted and
               live through that, and on the recurring path a second press mints
               a second subscription and takes a second first payment, which the
               recorder then refuses as belonging to another plan. Hidden rather
               than unmounted, because tearing the SDK down mid-round-trip loses
               the callbacks that finish it. */ }
            <div
                ref={ mountRef }
                className="dono-form__paypal-buttons"
                hidden={ approved }
            />

            { approved && ! error ? (
                <p className="dono-form__payment-loading" role="status">
                    { i18n.processing || i18n.paymentLoading }
                </p>
            ) : null }

            { /* An approval whose round trip failed is not progress, so the way
               out is offered again even though the approval stands. */ }
            { ! approved || error ? (
                <div className="dono-form__nav dono-form__nav--align-left">
                    <button
                        type="button"
                        className="dono-form__button dono-form__button--secondary"
                        disabled={ popupOpen }
                        onClick={ () => dispatch( { type: 'CANCEL_PAYMENT' } ) }
                    >
                        { i18n.cancel || 'Cancel' }
                    </button>
                </div>
            ) : null }
        </div>
    );
}
