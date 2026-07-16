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
                    appearance: { theme: 'stripe', variables: stripeVars() },
                } );
                elementsRef.current = elements;
                const el = elements.create( 'payment', { layout: 'tabs' } );
                el.on( 'ready', () => { if ( ! cancelled ) setReady( true ); } );
                el.mount( mountRef.current );
            } )
            .catch( () => { if ( ! cancelled ) setError( i18n.error ); } );

        return () => { cancelled = true; };
    }, [ pk, payment?.clientSecret ] );

    const onPay = async () => {
        const stripe = stripeRef.current;
        const elements = elementsRef.current;
        if ( ! stripe || ! elements || paying ) return;

        setPaying( true );
        setError( '' );

        const { error: confirmErr, paymentIntent } = await stripe.confirmPayment( {
            elements,
            confirmParams: { return_url: buildReturnUrl( payment ) },
            redirect: 'if_required',
        } );

        if ( confirmErr ) {
            // card_error / validation_error are shown to the donor; the intent
            // is still good, so they can correct and retry on this same step.
            setError( confirmErr.message || i18n.error );
            setPaying( false );
            return;
        }

        if ( paymentIntent && ( paymentIntent.status === 'succeeded' || paymentIntent.status === 'processing' ) ) {
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
        <div class="dono-form dono-form--payment">
            <h3 class="dono-form__payment-title">{ i18n.paymentTitle || 'Complete your donation' }</h3>
            { amountLine && (
                <p class="dono-form__payment-amount">{ amountLine }</p>
            ) }

            <div ref={ mountRef } class="dono-form__payment-element" />

            { ! ready && ! error && (
                <p class="dono-form__payment-loading">{ i18n.paymentLoading || 'Loading secure payment…' }</p>
            ) }

            { error && (
                <div class="dono-form__error" role="alert">{ error }</div>
            ) }

            <div class="dono-form__nav dono-form__nav--align-left">
                <button
                    type="button"
                    class="dono-form__button dono-form__button--primary"
                    disabled={ ! ready || paying }
                    onClick={ onPay }
                >
                    { paying ? ( i18n.processing || 'Processing…' ) : ( i18n.payNow || 'Pay' ) }
                </button>
                <button
                    type="button"
                    class="dono-form__button dono-form__button--secondary"
                    disabled={ paying }
                    onClick={ () => dispatch( { type: 'CANCEL_PAYMENT' } ) }
                >
                    { i18n.cancel || 'Cancel' }
                </button>
            </div>
        </div>
    );
}

/** Map the form's themed CSS vars onto the Payment Element where they exist. */
function stripeVars() {
    try {
        const cs = getComputedStyle( document.documentElement );
        const accent = cs.getPropertyValue( '--dono-accent' ).trim();
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
    url.searchParams.set( 'dono_return', '1' );
    if ( payment?.reference ) url.searchParams.set( 'dono_ref', payment.reference );
    return url.toString();
}
