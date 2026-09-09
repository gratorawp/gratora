/** @jsxImportSource preact */

import { useEffect, useRef, useState } from 'preact/hooks';
import { loadStripeJs } from '../util/stripe';
import { formatAmount } from '../util/format';

/**
 * Mounts the Payment Element against the PaymentIntent client_secret and confirms
 * client-side; cards complete inline, redirect methods resolve on boot via
 * resolveStripeReturn(). The webhook stays the server-side source of truth.
 */
export default function StripePayment( { config, payment, dispatch } ) {
    const mountRef = useRef( null );
    const stripeRef = useRef( null );
    const elementsRef = useRef( null );
    const elRef = useRef( null );
    const [ ready, setReady ] = useState( false );
    const [ paying, setPaying ] = useState( false );
    const [ error, setError ] = useState( '' );

    const i18n = config.i18n || {};
    const pk = config.stripe?.publishableKey || '';

    useEffect( () => {
        let cancelled = false;

        if ( ! pk || ! payment?.clientSecret ) {
            setError( i18n.error );
            return undefined;
        }

        loadStripeJs()
            .then( ( Stripe ) => {
                if ( cancelled ) return;
                const stripe = Stripe( pk );
                stripeRef.current = stripe;
                const elements = stripe.elements( {
                    clientSecret: payment.clientSecret,
                    appearance: { theme: 'stripe', variables: stripeVars( mountRef.current ) },
                } );
                elementsRef.current = elements;
                // Prefilling is Stripe's recommended Link integration: without
                // it the Element asks for an email the donor already gave us,
                // and Link's prefill tool scrapes the surrounding page for it.
                // Link itself is a Dashboard setting, not something Elements
                // can turn off.
                const billingDetails = {};
                if ( payment.donorEmail ) billingDetails.email = payment.donorEmail;
                if ( payment.donorName )  billingDetails.name  = payment.donorName;

                const el = elements.create( 'payment', {
                    layout: 'tabs',
                    ...( Object.keys( billingDetails ).length
                        ? { defaultValues: { billingDetails } }
                        : {} ),
                } );
                elRef.current = el;
                el.on( 'ready', () => { if ( ! cancelled ) setReady( true ); } );
                el.mount( mountRef.current );
            } )
            .catch( () => { if ( ! cancelled ) setError( i18n.error ); } );

        return () => {
            cancelled = true;
            // Tear down the Stripe iframe so a cancel/retry doesn't orphan it.
            try { elRef.current?.destroy(); } catch { /* already gone */ }
            elRef.current = null;
        };
    }, [ pk, payment?.clientSecret ] );

    const onPay = async () => {
        const stripe = stripeRef.current;
        const elements = elementsRef.current;
        if ( ! stripe || ! elements || paying ) return;

        setPaying( true );
        setError( '' );

        let confirmErr;
        let paymentIntent;

        try {
            ( { error: confirmErr, paymentIntent } = await stripe.confirmPayment( {
                elements,
                confirmParams: { return_url: buildReturnUrl( payment ) },
                redirect: 'if_required',
            } ) );
        } catch ( e ) {
            // A throw rather than a returned error: Stripe.js does that on a
            // client_secret and elements mismatch. Leaving `paying` set
            // disables Pay and Cancel both, on a donation nothing has charged.
            setError( i18n.error );
            setPaying( false );
            return;
        }

        if ( confirmErr ) {
            // card_error / validation_error are shown to the donor; the intent
            // is still good, so they can correct and retry on this same step.
            setError( confirmErr.message || i18n.error );
            setPaying( false );
            return;
        }

        // processing is not succeeded. Stripe is still working on it, and some
        // methods take days and can still fail. The completed screen would tell
        // the donor their donation had gone through and leave the org counting
        // one that has not settled. The reducer already has a processing state
        // and the form already has a screen for it.
        if ( paymentIntent && paymentIntent.status === 'processing' ) {
            dispatch( {
                type: 'SUBMIT_PENDING',
                data: { reference: payment.reference, status: 'processing' },
            } );
            return;
        }

        if ( paymentIntent && paymentIntent.status === 'succeeded' ) {
            dispatch( { type: 'SUBMIT_SUCCESS', data: { reference: payment.reference } } );
            return;
        }

        setError( i18n.error );
        setPaying( false );
    };

    const amountLine = payment?.amountCents > 0
        ? formatAmount( payment.amountCents, payment.currency )
        : '';

    return (
        <div class="gratora-form gratora-form--payment">
            <h3 class="gratora-form__payment-title">{ i18n.paymentTitle || 'Complete your donation' }</h3>
            { amountLine && (
                <p class="gratora-form__payment-amount">{ amountLine }</p>
            ) }

            <div ref={ mountRef } class="gratora-form__payment-element" />

            { ! ready && ! error && (
                <p class="gratora-form__payment-loading">{ i18n.paymentLoading || 'Loading secure payment…' }</p>
            ) }

            { error && (
                <div class="gratora-form__error" role="alert">{ error }</div>
            ) }

            <div class="gratora-form__nav gratora-form__nav--align-left">
                <button
                    type="button"
                    class="gratora-form__button gratora-form__button--primary"
                    disabled={ ! ready || paying }
                    onClick={ onPay }
                >
                    { paying ? ( i18n.processing || 'Processing…' ) : ( i18n.payNow || 'Pay' ) }
                </button>
                <button
                    type="button"
                    class="gratora-form__button gratora-form__button--secondary"
                    disabled={ paying }
                    onClick={ () => dispatch( { type: 'CANCEL_PAYMENT' } ) }
                >
                    { i18n.cancel || 'Cancel' }
                </button>
            </div>
        </div>
    );
}

function stripeVars( el ) {
    try {
        // --gratora-accent is set on the .gratora-donation-form element and inherits
        // down to the mount node; documentElement wouldn't see the override.
        const cs = getComputedStyle( el || document.documentElement );
        const accent = cs.getPropertyValue( '--gratora-accent' ).trim();
        return accent ? { colorPrimary: accent } : {};
    } catch {
        return {};
    }
}

/**
 * return_url for redirect-based methods: the current page with markers the
 * boot-time resolver reads. Stripe appends payment_intent_client_secret +
 * redirect_status on the way back.
 */
function buildReturnUrl( payment ) {
    const url = new URL( window.location.href );
    url.searchParams.set( 'gratora_return', '1' );
    if ( payment?.reference ) url.searchParams.set( 'gratora_ref', payment.reference );
    return url.toString();
}
