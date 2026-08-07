/** @jsxImportSource preact */

import { render } from 'preact';
import { useCallback, useMemo, useReducer, useRef, useState, useEffect } from 'preact/hooks';

import { reducer, initialState, validateStep, buildPayload, fieldSteps } from './state/store';
import { visibleGateways } from './util/gateways';
import AmountStep   from './steps/AmountStep';
import DonorStep    from './steps/DonorStep';
import ConfirmStep  from './steps/ConfirmStep';
import ProgressBar  from './components/ProgressBar';
import ErrorBoundary from './components/ErrorBoundary';
import GatewaySelect from './components/GatewaySelect';
import CurrencySwitcher from './components/CurrencySwitcher';
import StripePayment from './components/StripePayment';
import PayPalPayment from './components/PayPalPayment';
import { detectStripeReturn, resolveStripeReturn, clearStripeReturnParams } from './util/stripe';
import { interpolateLabel } from './util/interpolate';
import { decodeEntities } from './util/entities';
import { setActiveNumberFormat, formatAmount, frequencyLabel } from './util/format';
import { evaluateCondition } from './state/conditions';
import './runtime.scss';

const STEP_RENDERERS = {
    amount: AmountStep,
    donor:  DonorStep,
    // The submit step draws nothing of its own. It carries the button's label
    // and alignment, which FormBody reads directly, and it used to draw the
    // recap as well - that is the dono/donation-summary block now, rendered
    // wherever the author dropped it. Mapped rather than omitted so the step
    // does not fall through to the unknown-step message.
    submit: () => null,
};

/**
 * Payment steps for gateways that ship outside core. An add-on registers with
 * window.dono.formGateways.register( id, { component, ready } ) before the
 * runtime mounts; `ready` gets the gateway's own slice of the form config and
 * answers whether it can actually collect a payment.
 */
/**
 * Where a redirect gateway picks the donation back up. Session-scoped, so it
 * dies with the tab rather than following the donor around.
 */
export const PENDING_KEY = 'dono:pending-donation';

/**
 * Fired on window once per donation, the moment this browser learns the money
 * moved. Carries the reference and its status token and nothing else: a
 * listener that needs the amount reads it back from the status endpoint, which
 * is server-authoritative and returns no donor data.
 */
export const COMPLETED_EVENT = 'dono:donation:completed';

function rememberPending( data, values ) {
    try {
        window.sessionStorage.setItem( PENDING_KEY, JSON.stringify( {
            reference:   data.reference,
            statusToken: data.status_token,
            gateway:     data.gateway || '',
            // Enough to tell the donor what they gave when they land back here
            // from their bank on a fresh page, where nothing else survives.
            amountCents: data.amount_cents,
            currency:    data.currency,
            frequency:   values?.frequency || '',
            email:       values?.email || '',
        } ) );
    } catch ( e ) {
        // Private browsing can refuse storage. The donation is still made and
        // the webhook still settles it; only the return screen is lost.
    }
}

function readPending() {
    try {
        const raw = window.sessionStorage.getItem( PENDING_KEY );
        return raw ? JSON.parse( raw ) : {};
    } catch ( e ) {
        return {};
    }
}

/**
 * The token lands in a different place on each payment path: in the submit
 * response for auto-confirmed gateways, on the payment step for the ones that
 * mount a component, and only in session storage for a gateway that navigated
 * away and came back.
 */
function statusTokenFor( reference, state ) {
    const stored = readPending();

    return state.submission?.status_token
        || state.payment?.statusToken
        || ( stored.reference === reference ? stored.statusToken : '' )
        || '';
}

/** Which component collects payment for the gateway this donation is using. */
function paymentComponentFor( payment ) {
    if ( payment?.paypal ) return PayPalPayment;
    if ( payment?.gateway ) {
        return registeredGateway( payment.gateway )?.component || StripePayment;
    }
    return StripePayment;
}

/**
 * Whether the author placed a gateway block anywhere, at any nesting depth.
 * Read from the same config the form renders from rather than published as a
 * separate server flag, so the two cannot disagree about a form that was edited
 * between render and submit.
 */
function hasGatewayBlock( config ) {
    const seen = ( items ) => ( Array.isArray( items ) ? items : [] ).some( ( it ) => {
        if ( ! it ) return false;
        if ( it.kind === 'payment-gateways' ) return true;
        return seen( it.children );
    } );

    return ( Array.isArray( config?.steps ) ? config.steps : [] )
        .some( ( step ) => seen( step?.items ) || seen( step?.decorations ) );
}

/**
 * What the donor actually gave, for the card they land on afterwards.
 *
 * Read server-first: the submit response is the only number that matches what
 * was charged. A redirect gateway brings the donor back on a fresh page where
 * none of that survives, so the stash written at submit is the fallback, and
 * the form's own values are last because a reset would have cleared them.
 */
function receiptOf( state ) {
    const stash  = readPending();
    const sub    = state.submission || {};
    const paid   = state.payment || {};
    const values = state.values   || {};

    const amountCents = sub.amount_cents ?? paid.amountCents ?? stash.amountCents;

    return {
        amountCents,
        currency:  sub.currency ?? paid.currency ?? stash.currency ?? '',
        frequency: stash.frequency || values.frequency || '',
        email:     values.email || stash.email || '',
        reference: sub.reference || stash.reference || '',
    };
}

/**
 * Reaching your giving from the thank-you card.
 *
 * The donation did not prove the address: the form takes one on trust and a
 * card need not match it. So this sends the same link the portal sends, and the
 * mailbox does the proving. It never reports whether the address is known, for
 * the same reason the portal's own endpoint does not.
 */
function PortalLink( { email, config } ) {
    const [ sent, setSent ]     = useState( false );
    const [ failed, setFailed ] = useState( false );
    const [ busy, setBusy ]     = useState( false );
    const portal = config.portal || {};

    if ( ! portal.url || ! email ) return null;

    // The same element the donor pressed, still where they left it, saying
    // what happened. Swapping it for a line of grey text read as the button
    // having failed and vanished.
    if ( sent ) {
        return (
            <button
                type="button"
                class="dono-form__button dono-form__button--secondary dono-form__portal-link is-sent"
                disabled
            >
                { config.i18n.portalLinkSent }
            </button>
        );
    }

    // The request never got there. Saying "check your email" anyway would send
    // the donor to wait for something nobody sent, so the portal is offered
    // plainly instead and they can ask for the link themselves.
    if ( failed ) {
        return (
            <a class="dono-form__button dono-form__button--secondary" href={ portal.url }>
                { config.i18n.manageGiving }
            </a>
        );
    }

    const send = () => {
        setBusy( true );
        fetch( portal.sendLink, {
            method:      'POST',
            credentials: 'same-origin',
            headers:     { 'Content-Type': 'application/json' },
            body:        JSON.stringify( { email, token: portal.token || '' } ),
        } )
            // The endpoint answers the same for an address it knows and one it
            // does not, so a 200 is reported as sent without asking which.
            .then( ( res ) => { setSent( res.ok ); setFailed( ! res.ok ); } )
            .catch( () => setFailed( true ) )
            .finally( () => setBusy( false ) );
    };

    return (
        <button
            type="button"
            class="dono-form__button dono-form__button--secondary dono-form__portal-link"
            disabled={ busy }
            onClick={ send }
        >
            { config.i18n.manageGiving }
        </button>
    );
}

/** The few facts worth repeating back: what, how often, and where the receipt went. */
function DonationReceipt( { receipt, config } ) {
    const i18n  = config.i18n || {};
    const freq  = frequencyLabel( receipt.frequency, i18n );
    const known = Number.isFinite( receipt.amountCents ) && receipt.currency;

    if ( ! known && ! receipt.email ) return null;

    return (
        <dl class="dono-form__summary dono-form__summary--receipt">
            { known && (
                <div class="dono-form__summary-row">
                    <dt>{ freq ? i18n.amount : i18n.total }</dt>
                    <dd class="dono-form__summary-amount">
                        { formatAmount( receipt.amountCents, receipt.currency ) }
                    </dd>
                </div>
            ) }
            { freq && (
                <div class="dono-form__summary-row">
                    <dt>{ i18n.frequency }</dt>
                    <dd>{ freq }</dd>
                </div>
            ) }
            { receipt.email && (
                <div class="dono-form__summary-row">
                    <dt>{ i18n.email }</dt>
                    <dd>{ receipt.email }</dd>
                </div>
            ) }
        </dl>
    );
}

function registeredGateway( id ) {
    if ( ! id ) return null;
    const reg = typeof window !== 'undefined' ? window.dono?.formGateways : null;
    const entry = reg && typeof reg.get === 'function' ? reg.get( id ) : null;

    return entry && typeof entry.component === 'function' ? entry : null;
}

// After a failed validation the invalid field may be off-screen (long or paged
// form), so clicking the button looks like a dead click. Move focus and scroll
// to the first invalid field once the error re-render has committed.
function focusFirstInvalid() {
    requestAnimationFrame( () => {
        const el = document.querySelector( '.dono-donation-form [aria-invalid="true"]' );
        if ( el && typeof el.focus === 'function' ) {
            el.focus( { preventScroll: true } );
            el.scrollIntoView( { behavior: 'smooth', block: 'center' } );
        }
    } );
}

function readConfig( form ) {
    const node = form.querySelector( 'script[type="application/json"][data-dono-form-config]' );
    if ( ! node ) return null;
    try {
        return JSON.parse( node.textContent || '{}' );
    } catch {
        return null;
    }
}

// A donor step's items interleave fields and content in authored order. Render
// each maximal run of consecutive field-items as one DonorStep (so a row grid
// stays intact), and each decoration inline between them.
function StepItems( { items, state, dispatch, config } ) {
    const list = Array.isArray( items ) ? items : [];
    const out  = [];
    let run    = [];
    let fkey   = 0;
    const flush = () => {
        if ( ! run.length ) return;
        const fields = run;
        run = [];
        out.push(
            <ErrorBoundary key={ `f${ fkey++ }` }>
                <DonorStep fields={ fields } state={ state } dispatch={ dispatch } config={ config } />
            </ErrorBoundary>
        );
    };
    list.forEach( ( it, i ) => {
        if ( it && it.t === 'field' ) { run.push( it ); return; }
        // A condition-hidden decoration doesn't split the surrounding field run.
        if ( it && evaluateCondition( it.condition, state.values ) ) {
            flush();
            out.push( renderDecorationItem( it, `d${ i }`, state.values, { state, dispatch, config } ) );
        }
    } );
    flush();
    return out;
}

function StepView( { step, state, dispatch, config } ) {
    const type = step?.type;
    if ( type === 'donor' ) {
        return (
            <div class="dono-form__step" data-step="donor">
                <StepItems items={ step?.items } state={ state } dispatch={ dispatch } config={ config } />
            </div>
        );
    }
    const StepComp = STEP_RENDERERS[ type ];
    return (
        <div class="dono-form__step" data-step={ type }>
            { StepComp ? (
                <ErrorBoundary>
                    <StepComp step={ step } state={ state } dispatch={ dispatch } config={ config } />
                </ErrorBoundary>
            ) : (
                <p>{ `Unknown step: ${ type }` }</p>
            ) }
        </div>
    );
}

function FormBody( { state, dispatch, config } ) {
    const pages   = Array.isArray( config.pages ) ? config.pages : [];
    const isPaged = pages.length > 1;

    // Honeypot hidden via SCSS; token is HMAC-signed so scripted submissions
    // can't mint one. Both gates enforced server-side in AntiSpamGuard.
    const honeypotName = config.spam?.honeypotName || 'form_ref';
    const formToken    = config.spam?.formToken || '';
    const [ honeypot, setHoneypot ] = useState( '' );

    // Re-entrancy guard: a double-click fires onSubmit twice in the same tick
    // (the disabled re-render lands later), creating two pending donations.
    // The ref flips synchronously; the finally re-arms on every exit, and the
    // 'submitting' status keeps the button disabled while the fetch is out.
    const inFlight = useRef( false );

    const onSubmit = useCallback( async () => {
        if ( inFlight.current ) return;
        inFlight.current = true;
        try {
            for ( const s of fieldSteps( state ) ) {
                const errors = validateStep( s, state );
                if ( Object.keys( errors ).length > 0 ) {
                    // Jump to the page that owns the errored field so the message is
                    // visible; otherwise a paged form dead-ends with no feedback when
                    // the error is on an earlier page than the submit button.
                    dispatch( { type: 'SET_ERRORS', errors, step: s.page || 0 } );
                    focusFirstInvalid();
                    return;
                }
            }
            dispatch( { type: 'SUBMIT_START' } );
            // Send X-WP-Nonce only when present (logged-in users). Anonymous
            // donors omit it so a page-cached form never sends a stale nonce
            // the REST layer would reject with a 403.
            const headers = { 'Content-Type': 'application/json' };
            if ( config.nonce ) headers[ 'X-WP-Nonce' ] = config.nonce;
            const res = await fetch( config.rest, {
                method:  'POST',
                headers,
                body:    JSON.stringify( {
                    ...buildPayload( state ),
                    ...( config.extra ? { extra: config.extra } : {} ),
                    _ft: formToken,
                    _hp: honeypot,
                } ),
            } );
            const data = await res.json();
            if ( ! res.ok ) {
                // The trap is invisible, so a donor who somehow has a value in
                // it cannot see or clear one, and every retry would be refused
                // the same way. Clearing it here means a second attempt is a
                // real attempt. A bot refilling it on retry is refused again,
                // which is the point.
                setHoneypot( '' );
                dispatch( { type: 'SUBMIT_ERROR', message: data.message || config.i18n.error } );
                return;
            }
            // Stashed on every path, not just the redirecting one. The status
            // token is not in the return URL, deliberately, so anything that
            // outlives this closure (a gateway that navigates away, a listener
            // reading the amount back after completion) has no other source.
            rememberPending( data, state.values );

            if ( data.redirect_url ) {
                window.location.assign( data.redirect_url );
                return;
            }
            // PayPal hands back its own buttons step instead of a secret. Same
            // rule as Stripe: without a client id we cannot collect payment, so
            // fail rather than fall through to a thank-you with no money taken.
            if ( data.paypal ) {
                if ( config.paypal?.clientId ) {
                    dispatch( {
                        type: 'AWAIT_PAYMENT',
                        payment: {
                            paypal:      data.paypal,
                            reference:   data.reference,
                            intentId:    data.intent_id,
                            amountCents: data.amount_cents,
                            currency:    data.currency,
                        },
                    } );
                } else {
                    dispatch( { type: 'SUBMIT_ERROR', message: config.i18n.error } );
                }
                return;
            }
            // A gateway that ships outside core: it declared a browser payload
            // server-side and registered a component here. Same rule as the
            // three above, with the gateway itself deciding what "ready" means.
            const extra = registeredGateway( data.gateway );
            if ( extra && data[ data.gateway ] ) {
                if ( ! extra.ready || extra.ready( config[ data.gateway ] || {} ) ) {
                    dispatch( {
                        type: 'AWAIT_PAYMENT',
                        payment: {
                            gateway:     data.gateway,
                            data:        data[ data.gateway ],
                            reference:   data.reference,
                            // The per-donation secret a confirm route needs to
                            // prove this browser is the one that submitted.
                            statusToken: data.status_token,
                            intentId:    data.intent_id,
                            amountCents: data.amount_cents,
                            currency:    data.currency,
                            donorName:   [ state.values.profile?.first_name, state.values.profile?.last_name ]
                                .filter( Boolean ).join( ' ' ),
                            donorEmail:  state.values.email || '',
                            donorPhone:  state.values.profile?.phone || '',
                        },
                    } );
                } else {
                    dispatch( { type: 'SUBMIT_ERROR', message: config.i18n.error } );
                }
                return;
            }
            // A client_secret means the gateway needs a client-side payment
            // step. Without a publishable key we cannot collect it, so this is
            // an error - never fall through to a "thank you" with no payment.
            if ( data.client_secret ) {
                if ( config.stripe?.publishableKey ) {
                    dispatch( {
                        type: 'AWAIT_PAYMENT',
                        payment: {
                            clientSecret: data.client_secret,
                            reference:    data.reference,
                            intentId:     data.intent_id,
                            amountCents:  data.amount_cents,
                            currency:     data.currency,
                            // Handed to the Payment Element as defaultValues so
                            // Stripe stops asking for details this form already
                            // collected a step earlier.
                            donorName:   [ state.values.profile?.first_name, state.values.profile?.last_name ]
                                .filter( Boolean ).join( ' ' ),
                            donorEmail:  state.values.email || '',
                        },
                    } );
                } else {
                    dispatch( { type: 'SUBMIT_ERROR', message: config.i18n.error } );
                }
                return;
            }
            // Money has actually moved only when the server reports 'paid'
            // (auto-confirmed gateways). An offline / otherwise-pending donation
            // returns here with no redirect or secret - show a distinct pending
            // state, never the completed thank-you.
            dispatch( {
                type: data.status === 'paid' ? 'SUBMIT_SUCCESS' : 'SUBMIT_PENDING',
                data,
            } );
        } catch ( err ) {
            dispatch( { type: 'SUBMIT_ERROR', message: err?.message || config.i18n.error } );
        } finally {
            inFlight.current = false;
        }
    }, [ state, config, dispatch, formToken, honeypot ] );

    // Announced once per reference. `pending` is excluded on purpose: an
    // offline donation has been recorded, not paid, and may never be.
    // `processing` is included because a bank debit donor has finished and
    // their browser is gone by the time the webhook settles it.
    const announced = useRef( '' );
    useEffect( () => {
        if ( state.status !== 'success' && state.status !== 'processing' ) return;

        const reference = state.submission?.reference || state.payment?.reference || '';
        if ( ! reference || announced.current === reference ) return;
        announced.current = reference;

        window.dispatchEvent( new CustomEvent( COMPLETED_EVENT, {
            detail: {
                reference,
                statusToken: statusTokenFor( reference, state ),
                status:      state.status,
            },
        } ) );

        // After the announcement, never before: a thank-you URL replaces the
        // page, and a listener that has not run by then never will. This also
        // keeps the navigation out of render, where it used to fire on every
        // pass rather than once.
        if ( state.status === 'success' && config.thanks?.redirect ) {
            window.location.assign( config.thanks.redirect );
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [ state.status, state.submission, state.payment, config.thanks?.redirect ] );

    // The payment UI belongs where the author put the gateway block: the donor
    // picked how to pay there, so that is where paying happens, and the form
    // they filled in stays on screen behind it. Replacing the whole body is the
    // fallback for a form with no block, which readiness already warns about.
    if ( state.status === 'payment' && ! hasGatewayBlock( config ) ) {
        const PaymentStep = paymentComponentFor( state.payment );
        return (
            <ErrorBoundary>
                <PaymentStep config={ config } payment={ state.payment } dispatch={ dispatch } />
            </ErrorBoundary>
        );
    }

    if ( state.status === 'confirming' ) {
        return (
            <div class="dono-form__confirming" role="status">
                <span class="dono-form__spinner" aria-hidden="true" />
                <p>{ config.i18n.confirming || config.i18n.processing }</p>
            </div>
        );
    }

    // Bank debit, authorised and on its way. Distinct from pending: the donor
    // has done everything and no instructions are coming, so the message must
    // not ask them for anything.
    if ( state.status === 'processing' ) {
        return (
            <div class="dono-form__success dono-form__success--pending" role="status">
                <div class="dono-form__success-icon dono-form__success-icon--pending" aria-hidden="true">⏳</div>
                <h3>{ config.i18n.processingTitle || config.i18n.pendingTitle }</h3>
                <p class="dono-form__thank-you">{ config.i18n.processingMessage || config.i18n.pendingMessage }</p>
                <DonationReceipt receipt={ receiptOf( state ) } config={ config } />
                { state.submission?.reference && (
                    <p class="dono-form__reference">{ state.submission.reference }</p>
                ) }
                <div class="dono-form__success-actions">
                    <button
                        type="button"
                        class="dono-form__button dono-form__button--secondary"
                        onClick={ () => dispatch( { type: 'RESET' } ) }
                    >
                        { config.i18n.donateAgain }
                    </button>
                    <PortalLink email={ receiptOf( state ).email } config={ config } />
                </div>
            </div>
        );
    }

    if ( state.status === 'pending' ) {
        // Donation recorded but not yet paid (e.g. offline / bank transfer):
        // no money has moved, so do not honor the completed-donation redirect
        // or show the paid thank-you. Instructions are emailed on submit.
        return (
            <div class="dono-form__success dono-form__success--pending" role="status">
                <div class="dono-form__success-icon dono-form__success-icon--pending" aria-hidden="true">⏳</div>
                <h3>{ config.i18n.pendingTitle }</h3>
                <p class="dono-form__thank-you">{ config.i18n.pendingMessage }</p>
                <DonationReceipt receipt={ receiptOf( state ) } config={ config } />
                { state.submission?.reference && (
                    <p class="dono-form__reference">{ state.submission.reference }</p>
                ) }
                <div class="dono-form__success-actions">
                    <button
                        type="button"
                        class="dono-form__button dono-form__button--secondary"
                        onClick={ () => dispatch( { type: 'RESET' } ) }
                    >
                        { config.i18n.donateAgain }
                    </button>
                    <PortalLink email={ receiptOf( state ).email } config={ config } />
                </div>
            </div>
        );
    }

    if ( state.status === 'success' ) {
        // A configured thank-you URL takes precedence over this card, but the
        // navigation happens in the completion effect above so anything
        // listening for the donation gets to run first.
        const message = config.thanks?.message || '';
        return (
            <div class="dono-form__success" role="status">
                <div class="dono-form__success-icon" aria-hidden="true">✓</div>
                <h3>{ config.i18n.thanks }</h3>
                { message && (
                    <p class="dono-form__thank-you">{ message }</p>
                ) }
                <DonationReceipt receipt={ receiptOf( state ) } config={ config } />
                { state.submission?.reference && (
                    <p class="dono-form__reference">{ state.submission.reference }</p>
                ) }
                <div class="dono-form__success-actions">
                    <button
                        type="button"
                        class="dono-form__button dono-form__button--secondary"
                        onClick={ () => dispatch( { type: 'RESET' } ) }
                    >
                        { config.i18n.donateAgain }
                    </button>
                    <PortalLink email={ receiptOf( state ).email } config={ config } />
                </div>
            </div>
        );
    }

    const honeypotInput = (
        <div class="dono-form__hp" aria-hidden="true">
            <label>
                Leave this field empty
                <input
                    type="text"
                    name={ honeypotName }
                    value={ honeypot }
                    onInput={ ( e ) => setHoneypot( e.target.value ) }
                    autoComplete="off"
                    tabIndex={ -1 }
                />
            </label>
        </div>
    );

    // Root content authored before a dono/steps wizard: rendered once above the
    // form, so it doesn't collapse onto the first page.
    const preamble = ( Array.isArray( config.preamble ) && config.preamble.length ) ? (
        <StepItems items={ config.preamble } state={ state } dispatch={ dispatch } config={ config } />
    ) : null;

    if ( ! isPaged ) {
        return (
            <>
                { honeypotInput }
                { preamble }
                <SinglePageView state={ state } dispatch={ dispatch } config={ config } onSubmit={ onSubmit } />
            </>
        );
    }

    return (
        <>
            { honeypotInput }
            { preamble }
            <PagedView pages={ pages } state={ state } dispatch={ dispatch } config={ config } onSubmit={ onSubmit } />
        </>
    );
}

function SinglePageView( { state, dispatch, config, onSubmit } ) {
    // Same rule as the paged view. Fixing only that one left every single-page
    // form showing a live Donate button under "No payment method accepts X".
    const noGateway  = visibleGateways( config, state ).length === 0;
    const submitStep = state.steps.find( ( s ) => s.type === 'submit' );
    const submitLabel = interpolateLabel(
        submitStep?.label || config.i18n.donateNow,
        state,
        config
    );
    return (
        <div class="dono-form dono-form--inline">
            { state.steps.map( ( s, i ) => (
                <StepView key={ i } step={ s } state={ state } dispatch={ dispatch } config={ config } />
            ) ) }
            { state.status === 'error' && state.message && (
                <div class="dono-form__error" role="alert">{ state.message }</div>
            ) }
            <div class={ `dono-form__nav dono-form__nav--align-${ submitStep?.align || 'left' }` }>
                <button
                    type="button"
                    class="dono-form__button dono-form__button--primary"
                    disabled={ state.status === 'submitting' || noGateway }
                    onClick={ () => { if ( ! noGateway ) onSubmit(); } }
                >
                    { state.status === 'submitting' ? config.i18n.processing : submitLabel }
                </button>
            </div>
        </div>
    );
}

function PagedView( { pages, state, dispatch, config, onSubmit } ) {
    const pageNav = config.pageNav || {};
    const progressStyle = pageNav.progressStyle || 'dots';
    const current = Math.max( 0, Math.min( state.step, pages.length - 1 ) );
    const isLast  = current === pages.length - 1;
    const pageSteps = state.steps.filter( ( s ) => ( s.page || 0 ) === current );
    // Validation walks fieldSteps so fields authored above the wizard (the
    // preamble, rendered once by FormBody, never as a page step) check with
    // the first page instead of bypassing validation entirely.
    const checkSteps = fieldSteps( state ).filter( ( s ) => ( s.page || 0 ) === current );

    const page = pages[ current ] || {};
    const pageTitle = decodeEntities( page.title || '' );
    const showPageTitle = page.showTitle !== false && pageTitle !== '';

    // Moving between pages replaced the whole form body with no announcement
    // and no focus move, so a screen reader carried on reading the button that
    // had just been swapped underneath it and a keyboard user was left at the
    // bottom of a page that no longer existed. Same pattern the onboarding
    // wizard already uses for the same reason.
    const pageMounted = useRef( false );
    useEffect( () => {
        if ( ! pageMounted.current ) { pageMounted.current = true; return; }
        const root = document.querySelector( '.dono-donation-form' );
        const h    = root?.querySelector( '.dono-form__page-title, .dono-form__bar-title' );
        if ( h ) {
            h.setAttribute( 'tabindex', '-1' );
            h.focus();
        }
    }, [ current ] );

    const onPrev = useCallback( () => dispatch( { type: 'PREV' } ), [ dispatch ] );

    const onNext = useCallback( () => {
        const errors = {};
        for ( const s of checkSteps ) {
            Object.assign( errors, validateStep( s, state ) );
        }
        dispatch( { type: 'NEXT', errors } );
        if ( Object.keys( errors ).length > 0 ) focusFirstInvalid();
    }, [ checkSteps, state, dispatch ] );

    // No enabled gateway takes the chosen currency. GatewaySelect says so
    // where the choice is made; this stops the donor reaching a server refusal
    // by pressing the button anyway.
    const noGateway = visibleGateways( config, state ).length === 0;

    const submit = useCallback( () => {
        if ( noGateway ) return;

        const errors = {};
        for ( const s of checkSteps ) {
            Object.assign( errors, validateStep( s, state ) );
        }
        if ( Object.keys( errors ).length > 0 ) {
            dispatch( { type: 'SET_ERRORS', errors } );
            focusFirstInvalid();
            return;
        }
        onSubmit();
    }, [ checkSteps, state, dispatch, onSubmit, noGateway ] );

    const submitStep = state.steps.find( ( s ) => s.type === 'submit' );
    const submitLabel = interpolateLabel(
        submitStep?.label || config.i18n.donateNow,
        state,
        config
    );
    const prevLabel = ( pageNav.prevLabel || '' ).trim() || config.i18n.back;
    const nextLabel = ( pageNav.nextLabel || '' ).trim() || config.i18n.next;

    const primary = (
        <button
            type="button"
            class="dono-form__button dono-form__button--primary"
            disabled={ state.status === 'submitting' || ( isLast && noGateway ) }
            onClick={ isLast ? submit : onNext }
        >
            { state.status === 'submitting'
                ? config.i18n.processing
                : ( isLast ? submitLabel : nextLabel ) }
        </button>
    );

    const error = state.status === 'error' && state.message && (
        <div class="dono-form__error" role="alert">{ state.message }</div>
    );

    // No gateway section here on purpose. The payment-gateways block decides
    // where the selector goes and whether there is one at all; rendering it as
    // a fallback meant removing the block in the editor did not remove it from
    // the form. A form that offers a choice without placing the block is caught
    // by the readiness check, where the author can see it.

    if ( progressStyle === 'bar' ) {
        const pct = pages.length > 1
            ? Math.round( ( ( current + 1 ) / pages.length ) * 100 )
            : 100;
        return (
            <div class={ `dono-form dono-form--paged-bar${ state.status === 'payment' ? ' dono-form--settled' : '' }` }>
                <header class="dono-form__bar-header">
                    { current > 0 ? (
                        <button
                            type="button"
                            class="dono-form__bar-back"
                            aria-label={ prevLabel }
                            onClick={ onPrev }
                        >
                            <span aria-hidden="true">←</span>
                        </button>
                    ) : (
                        <span class="dono-form__bar-back" aria-hidden="true" />
                    ) }
                    { showPageTitle && (
                        <h3 class="dono-form__bar-title">{ pageTitle }</h3>
                    ) }
                    <span class="dono-form__bar-spacer" aria-hidden="true" />
                </header>
                <div
                    class="dono-form__bar-track"
                    role="progressbar"
                    aria-valuemin="0"
                    aria-valuemax={ pages.length }
                    aria-valuenow={ current + 1 }
                >
                    <div class="dono-form__bar-fill" style={ { width: `${ pct }%` } } />
                </div>
                <div class="dono-form__body">
                    { pageSteps.map( ( s, i ) => (
                        <StepView key={ i } step={ s } state={ state } dispatch={ dispatch } config={ config } />
                    ) ) }
                    { error }
                    <div class={ `dono-form__nav dono-form__nav--align-${ ( isLast ? submitStep?.align : null ) || 'end' }` }>{ primary }</div>
                </div>
            </div>
        );
    }

    // Default: dots-at-bottom variant.
    return (
        <div class={ `dono-form${ state.status === 'payment' ? ' dono-form--settled' : '' }` }>
            { showPageTitle && (
                <h3 class="dono-form__page-title">{ pageTitle }</h3>
            ) }
            { pageSteps.map( ( s, i ) => (
                <StepView key={ i } step={ s } state={ state } dispatch={ dispatch } config={ config } />
            ) ) }
            { error }
            <div class={ `dono-form__nav${ isLast && submitStep?.align ? ` dono-form__nav--align-${ submitStep.align }` : '' }` }>
                { current > 0 ? (
                    <button
                        type="button"
                        class="dono-form__button dono-form__button--secondary"
                        onClick={ onPrev }
                    >
                        { prevLabel }
                    </button>
                ) : (
                    // Placeholder so flex space-between keeps the primary
                    // button anchored to the right on the first page.
                    <span aria-hidden="true" />
                ) }
                { primary }
            </div>
            { progressStyle === 'dots' && (
                <ProgressBar
                    current={ current }
                    total={ pages.length }
                    labels={ pages.map( ( p ) => decodeEntities( p.title || '' ) ) }
                />
            ) }
        </div>
    );
}

const FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

function useFocusTrap( ref, active ) {
    useEffect( () => {
        if ( ! active || ! ref.current ) return;
        const el = ref.current;
        const doc = el.ownerDocument;
        const prev = doc.activeElement;
        const first = el.querySelector( FOCUSABLE );
        if ( first ) first.focus();

        const onTab = ( e ) => {
            if ( e.key !== 'Tab' ) return;
            const nodes = [ ...el.querySelectorAll( FOCUSABLE ) ];
            if ( ! nodes.length ) return;
            const firstNode = nodes[ 0 ];
            const last  = nodes[ nodes.length - 1 ];
            if ( e.shiftKey && doc.activeElement === firstNode ) {
                e.preventDefault();
                last.focus();
            } else if ( ! e.shiftKey && doc.activeElement === last ) {
                e.preventDefault();
                firstNode.focus();
            }
        };
        el.addEventListener( 'keydown', onTab );
        return () => {
            el.removeEventListener( 'keydown', onTab );
            if ( prev && typeof prev.focus === 'function' ) prev.focus();
        };
    }, [ active ] );
}

function ModalShell( { children, openLabel, config, initiallyOpen = false } ) {
    // Read once, on the first render: a donor coming back from their bank did
    // not press the trigger, and the shut modal never mounts the body that
    // shows the outcome and fires the completion event.
    const [ open, setOpen ] = useState( !! initiallyOpen );
    const panelRef = useRef( null );

    useFocusTrap( panelRef, open );

    useEffect( () => {
        const onKey = ( e ) => { if ( e.key === 'Escape' ) setOpen( false ); };
        if ( open ) document.addEventListener( 'keydown', onKey );
        return () => document.removeEventListener( 'keydown', onKey );
    }, [ open ] );

    return (
        <div class="dono-modal-host">
            <button
                type="button"
                class="dono-form__button dono-form__button--primary dono-modal-trigger"
                onClick={ () => setOpen( true ) }
            >
                { openLabel }
            </button>
            { open && (
                <div class="dono-modal" role="dialog" aria-modal="true" aria-label={ config.i18n.formTitle || '' }>
                    { /* eslint-disable-next-line jsx-a11y/no-static-element-interactions, jsx-a11y/click-events-have-key-events -- decorative overlay; Escape and the close button provide keyboard dismissal */ }
                    <div class="dono-modal__backdrop" aria-hidden="true" onClick={ () => setOpen( false ) } />
                    <div class="dono-modal__panel" ref={ panelRef }>
                        <button
                            type="button"
                            class="dono-modal__close"
                            aria-label={ config.i18n.close || 'Close' }
                            onClick={ () => setOpen( false ) }
                        >×</button>
                        { children }
                    </div>
                </div>
            ) }
        </div>
    );
}

function App( { config } ) {
    const [ state, dispatch ] = useReducer( reducer, config, initialState );

    // Redirect-based methods (iDEAL, Bancontact) bounce back to return_url with
    // Stripe's markers. Resolve the PaymentIntent and show the outcome inline.
    useEffect( () => {
        // Scoped to this form's own submission: two forms on a page both read
        // the same URL, and the first to run strips the params from under the
        // other. readPending() is what this form stashed when it submitted.
        const ret = detectStripeReturn( readPending().reference || null );
        if ( ! ret || ! config.stripe?.publishableKey ) return;
        dispatch( { type: 'CONFIRMING' } );
        clearStripeReturnParams();
        resolveStripeReturn( config.stripe.publishableKey, ret.clientSecret )
            .then( ( status ) => {
                if ( status === 'processing' ) {
                    dispatch( {
                        type: 'SUBMIT_PENDING',
                        data: { reference: ret.reference, status: 'processing' },
                    } );
                } else if ( status === 'succeeded' ) {
                    dispatch( { type: 'SUBMIT_SUCCESS', data: { reference: ret.reference } } );
                } else {
                    dispatch( { type: 'SUBMIT_ERROR', message: config.i18n.error } );
                }
            } )
            .catch( () => dispatch( { type: 'SUBMIT_ERROR', message: config.i18n.error } ) );
    }, [] );

    const body = <FormBody state={ state } dispatch={ dispatch } config={ config } />;

    // Computed during the first render, before the effect above strips the
    // params, so a modal form can open itself on a return that is its own.
    const returningHere = useMemo(
        () => detectStripeReturn( readPending().reference || null ) !== null,
        []
    );

    if ( config.layout === 'modal' ) {
        return (
            <ModalShell
                openLabel={ config.i18n.donateNow }
                config={ config }
                initiallyOpen={ returningHere }
            >
                { body }
            </ModalShell>
        );
    }

    return body;
}

function renderDecorationItem( d, i, values, ctx ) {
    if ( d.kind === 'heading' ) {
        const Tag = `h${ d.level || 2 }`;
        return (
            <Tag
                key={ i }
                class={ `dono-form__heading dono-form__heading--${ d.align || 'left' }` }
            >
                { decodeEntities( d.text ) }
            </Tag>
        );
    }
    if ( d.kind === 'paragraph' ) {
        return (
            <p
                key={ i }
                class={ `dono-form__paragraph dono-form__paragraph--${ d.align || 'left' }` }
                dangerouslySetInnerHTML={ { __html: d.html || '' } }
            />
        );
    }
    if ( d.kind === 'divider' ) {
        return (
            <hr
                key={ i }
                class="dono-form__divider"
                style={ {
                    marginTop:      `${ d.marginTop ?? 16 }px`,
                    marginBottom:   `${ d.marginBottom ?? 16 }px`,
                    borderTopWidth: `${ d.thickness || 1 }px`,
                    ...( d.color ? { borderTopColor: d.color } : {} ),
                } }
            />
        );
    }
    if ( d.kind === 'html' ) {
        return (
            <div
                key={ i }
                class="dono-form__html"
                dangerouslySetInnerHTML={ { __html: d.html || '' } }
            />
        );
    }
    if ( d.kind === 'currency-switcher' ) {
        return (
            <CurrencySwitcher
                key={ i }
                currencies={ ctx?.config?.currencies }
                currency={ ctx?.state?.currency }
                onChange={ ( c ) => ctx?.dispatch?.( { type: 'SET_CURRENCY', currency: c } ) }
                variant={ d.variant }
                align={ d.align }
                label={ d.label }
                ariaLabel={ ctx?.config?.i18n?.currency }
            />
        );
    }
    if ( d.kind === 'summary' ) {
        return (
            <ErrorBoundary key={ i }>
                <ConfirmStep
                    state={ ctx?.state }
                    config={ ctx?.config }
                    showDonor={ d.showDonor !== false }
                    showGateway={ d.showGateway !== false }
                />
            </ErrorBoundary>
        );
    }
    if ( d.kind === 'payment-gateways' ) {
        const st = ctx?.state;
        if ( st?.status === 'payment' ) {
            const PaymentStep = paymentComponentFor( st.payment );
            return (
                <ErrorBoundary key={ i }>
                    <div class="dono-form__payment-mount">
                        <PaymentStep
                            config={ ctx?.config }
                            payment={ st.payment }
                            dispatch={ ctx?.dispatch }
                        />
                    </div>
                </ErrorBoundary>
            );
        }
        return (
            <ErrorBoundary key={ i }>
                <GatewaySelect
                    state={ st }
                    dispatch={ ctx?.dispatch }
                    config={ ctx?.config }
                />
            </ErrorBoundary>
        );
    }
    if ( d.kind === 'section' ) {
        const classes = Array.isArray( d.classes ) ? d.classes.join( ' ' ) : '';
        return (
            <div key={ i } class={ classes } style={ d.style || undefined }>
                <Decorations items={ d.children } values={ values } ctx={ ctx } />
            </div>
        );
    }
    if ( d.kind === 'columns' ) {
        // Grid children must be direct descendants for `grid-template-columns`
        // to assign them to cells, so skip the usual <Decorations> wrapper.
        const classes = Array.isArray( d.classes ) ? d.classes.join( ' ' ) : '';
        const kids = ( d.children || [] ).filter(
            ( c ) => evaluateCondition( c.condition, values )
        );
        return (
            <div key={ i } class={ classes } style={ d.style || undefined }>
                { kids.map( ( c, ci ) => renderDecorationItem( c, ci, values, ctx ) ) }
            </div>
        );
    }
    return null;
}

function Decorations( { items, values, ctx } ) {
    const visible = ( items || [] ).filter(
        ( d ) => evaluateCondition( d.condition, values )
    );
    if ( ! visible.length ) return null;
    return (
        <div class="dono-form__decorations">
            { visible.map( ( d, i ) => renderDecorationItem( d, i, values, ctx ) ) }
        </div>
    );
}

function applyUrlPrefills( config ) {
    const params = new URLSearchParams( window.location.search );
    const cents  = parseInt( params.get( 'dono_amount' ) || '', 10 );
    const freq   = params.get( 'dono_frequency' );

    if ( cents > 0 ) {
        const amount = config.steps.find( ( s ) => s.type === 'amount' );
        if ( amount ) {
            const list = amount.presets || [];
            const isPreset = !! list.find( ( p ) => ( typeof p === 'number' ? p : p?.cents ) === cents );
            // Presets-only forms reject non-preset amounts server-side, so a
            // non-preset prefill would preselect a tile that can never submit.
            // Only honor it when custom amounts are allowed or it already
            // matches an authored preset.
            if ( isPreset || amount.allowCustom !== false ) {
                if ( ! isPreset ) {
                    amount.presets = [ ...list, { cents, impact: '' } ];
                }
                config.__prefillAmount = cents;
            }
        }
    }
    if ( freq ) config.__prefillFrequency = freq;
}

// Token keys arrive from the server-side resolver without the leading "--".
function applyThemeTokens( form, theme ) {
    if ( ! theme ) return;
    const tokens = theme.tokens && typeof theme.tokens === 'object' ? theme.tokens : null;
    if ( ! tokens ) return;
    for ( const key in tokens ) {
        const value = tokens[ key ];
        if ( typeof value === 'string' && value !== '' ) {
            form.style.setProperty( `--${ key }`, value );
        }
    }
}

function mount( form ) {
    if ( form.dataset.donoMounted === 'true' ) return;

    // Uncloak on every exit path (success, no-op, failure) so the JS-gated
    // cloak can never leave the form permanently hidden.
    const reveal = () => { form.dataset.donoReady = 'true'; };

    const config = readConfig( form );
    if ( ! config ) { reveal(); return; }

    if ( ! Array.isArray( config.steps ) || config.steps.length === 0 ) {
        // Nothing to hydrate; leave the server-rendered fallback visible.
        reveal();
        return;
    }

    applyUrlPrefills( config );

    // Seed number-format from the server so amounts honour the org's choices,
    // not the visitor's browser locale.
    setActiveNumberFormat( config.numberFormat );

    const snapshot = form.innerHTML;
    form.innerHTML = '';
    form.dataset.donoMounted = 'true';

    // The host element is a real <form> and the Preact UI uses type="button",
    // so a native submit (Enter on a single-field step) would reload the page
    // and wipe all in-progress state. Block it; navigation is button-driven.
    form.addEventListener( 'submit', ( e ) => e.preventDefault() );

    applyThemeTokens( form, config.theme );

    try {
        render( <App config={ config } />, form );
    } catch ( err ) {
        // If the Preact app throws on first render, restore the static HTML
        // so the form is at least visible.
        // eslint-disable-next-line no-console
        console.error( '[dono] mount failed', err );
        form.innerHTML = snapshot;
        delete form.dataset.donoMounted;
    }
    reveal();
}

function bootAll() {
    document.querySelectorAll( '.dono-donation-form' ).forEach( mount );

    const inIframe = window.parent && window.parent !== window;

    // If we're inside a preview iframe (parent window is different), expose
    // a postMessage channel so the styling editor can push token updates
    // without re-fetching the whole document.
    if ( inIframe ) {
        // A srcdoc preview has an opaque origin: its document URL is
        // about:srcdoc, so location.origin is the string "null" and every
        // postMessage from the parent carries the parent's real origin. The
        // equality check below therefore rejected every token push, which is
        // why switching preset never repainted the preview.
        //
        // The guard still matters for the public form, which a hostile page
        // could frame by URL and push --dono-* vars into (UI-redress on a
        // payment form). That form is loaded from a real URL, so it keeps the
        // strict check; only a srcdoc document, whose entire HTML we wrote,
        // trusts its parent.
        const isSrcdocPreview = window.location.origin === 'null'
            || document.URL === 'about:srcdoc';

        window.addEventListener( 'message', ( event ) => {
            if ( ! isSrcdocPreview && event.origin !== window.location.origin ) return;
            if ( isSrcdocPreview && event.source !== window.parent ) return;
            const data = event.data;
            if ( ! data || typeof data !== 'object' ) return;
            if ( data.type !== 'dono:apply-tokens' || ! data.tokens ) return;
            document.querySelectorAll( '.dono-donation-form' ).forEach( ( form ) => {
                // Clear existing --dono-* inline vars first so a preset that
                // omits a token reverts to the stylesheet default, not a stale value.
                const st = form.style;
                const drop = [];
                for ( let i = 0; i < st.length; i++ ) {
                    const n = st[ i ];
                    if ( n && n.indexOf( '--dono-' ) === 0 ) drop.push( n );
                }
                drop.forEach( ( n ) => st.removeProperty( n ) );
                applyThemeTokens( form, { tokens: data.tokens } );
            } );
        } );

        try {
            window.parent.postMessage( { type: 'dono:preview-ready' }, '*' );
        } catch ( e ) {
            // Cross-origin parent: postMessage can throw. Safe to ignore.
        }
    }

    // The block editor injects the form after load via ServerSideRender, and its
    // canvas may be iframed (site editor) or inline in the page (post editor).
    // bootAll has already run, so watch for forms added later and mount them.
    // Scoped to editor/preview surfaces (iframe or block-editor-page) so the
    // public front end is untouched; mount() guards against double-mounts.
    const inEditor = inIframe
        || ( document.body && document.body.classList.contains( 'block-editor-page' ) );
    if ( inEditor ) {
        const observer = new MutationObserver( ( records ) => {
            for ( const rec of records ) {
                rec.addedNodes.forEach( ( node ) => {
                    if ( node.nodeType !== 1 ) return;
                    if ( node.matches && node.matches( '.dono-donation-form' ) ) mount( node );
                    if ( node.querySelectorAll ) {
                        node.querySelectorAll( '.dono-donation-form' ).forEach( mount );
                    }
                } );
            }
        } );
        observer.observe( document.body, { childList: true, subtree: true } );
    }
}

if ( document.readyState === 'loading' ) {
    document.addEventListener( 'DOMContentLoaded', bootAll );
} else {
    bootAll();
}
