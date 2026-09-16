/** @jsxImportSource preact */

import { derivedInk } from '../_shared/ink';
import { render } from 'preact';
import { useCallback, useMemo, useReducer, useRef, useState, useEffect } from 'preact/hooks';

import { reducer, initialState, validateStep, buildPayload, fieldSteps, embedOf } from './state/store';
import { visibleGateways, emptyMessage, keepGatewayValid } from './util/gateways';
import { backGlyph } from './util/direction';
import AmountStep   from './steps/AmountStep';
import DonorStep    from './steps/DonorStep';
import ConfirmStep  from './steps/ConfirmStep';
import ProgressBar  from './components/ProgressBar';
import ErrorBoundary from './components/ErrorBoundary';
import GatewaySelect from './components/GatewaySelect';
import CurrencySwitcher from './components/CurrencySwitcher';
import StripePayment from './components/StripePayment';
import PayPalPayment from './components/PayPalPayment';
import { detectStripeReturn, resolveStripeReturn, clearStripeReturnParams, returnOutcome } from './util/stripe';
import { rememberPending, readPending, clearPending, ownsPendingReturn } from './util/pending';
import { interpolateLabel } from './util/interpolate';
import { decodeEntities } from './util/entities';
import { setActiveNumberFormat, formatAmount, frequencyLabel } from './util/format';
import { maxAmountFor, roundToCurrency } from './util/fx';
import { evaluateCondition } from './state/conditions';
import './runtime.scss';

const STEP_RENDERERS = {
    amount: AmountStep,
    donor:  DonorStep,
    // The submit step draws nothing of its own: it carries the button's label
    // and alignment, which FormBody reads directly, and the recap is the
    // gratora/donation-summary block. Mapped rather than omitted so the step does
    // not fall through to the unknown-step message.
    submit: () => null,
};

// Fired on window once per donation, the moment this browser learns the money
// moved. It carries the reference and its status token and nothing else: a
// listener that needs the amount reads it back from the status endpoint, which
// is server-authoritative and returns no donor data.
export const COMPLETED_EVENT = 'gratora:donation:completed';

// How long a thank-you redirect waits for listeners that asked to be waited
// for. Long enough for a same-origin round trip to the status endpoint, short
// enough that a listener which never settles cannot strand the donor on the
// form.
export const COMPLETED_HOLD_MS = 2000;

// Announce the runtime’s return claim so modal code reveals its outcome without repeating
// ownership checks.
export const RETURN_CLAIMED_EVENT = 'gratora:donation:return-claimed';

// The token lands in a different place on each payment path: the submit
// response for auto-confirmed gateways, the payment step for the ones that
// mount a component, and only session storage for a gateway that navigated away
// and came back.
function statusTokenFor( reference, state ) {
    const stored = readPending();

    return state.submission?.status_token
        || state.payment?.statusToken
        || ( stored.reference === reference ? stored.statusToken : '' )
        || '';
}

/**
 * Buy a form token for this host document. The key is public, sitting in the
 * partner page's source, so nothing here is a secret and the server decides
 * what the key is worth.
 *
 * @since 1.1.0
 */
async function exchangeEmbedToken( embed ) {
    const res = await fetch( String( embed.tokenUrl || '' ), {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify( { key: embed.key || '' } ),
    } );
    if ( ! res.ok ) return '';

    const data = await res.json().catch( () => null );

    return ( data && typeof data.token === 'string' ) ? data.token : '';
}

/**
 * Whether the server says this donation is paid.
 *
 * @since 1.1.0
 */
function confirmPaid( config, reference ) {
    const token = String( readPending().statusToken || '' );
    if ( ! token || ! reference ) return Promise.resolve( false );

    const base = String( config.rest || '' ).replace( /\/+$/, '' );
    // rest_url is the ?rest_route= form when permalinks are plain.
    const sep  = base.includes( '?' ) ? '&' : '?';
    const url  = `${ base }/${ encodeURIComponent( reference ) }`
        + `${ sep }status_token=${ encodeURIComponent( token ) }`;

    return fetch( url, { headers: { Accept: 'application/json' } } )
        .then( ( res ) => ( res.ok ? res.json() : null ) )
        .then( ( data ) => !! data && data.status === 'paid' );
}

/**
 * Outbound only, and only to the one origin the host document names.
 *
 * @since 1.1.0
 */
function postEmbedHeight( embed ) {
    try {
        const height = Math.ceil( document.documentElement.getBoundingClientRect().height );
        // A key may name a www/apex pair, and the document cannot know which of
        // the two framed it. The browser drops the post that does not match.
        const targets = Array.isArray( embed.origins ) && embed.origins.length
            ? embed.origins
            : [ embed.origin ];

        targets.forEach( ( target ) => {
            if ( ! target ) return;
            window.parent.postMessage(
                { source: 'gratora', v: 1, type: 'height', key: embed.key || '', height },
                target
            );
        } );
    } catch ( e ) {
        // A parent that has gone, or an origin the browser will not post to.
    }
}

function paymentComponentFor( payment ) {
    if ( payment?.paypal ) return PayPalPayment;
    if ( payment?.gateway ) {
        return registeredGateway( payment.gateway )?.component || StripePayment;
    }
    return StripePayment;
}

const hasGatewayItem = ( items ) => ( Array.isArray( items ) ? items : [] ).some( ( it ) => {
    if ( ! it ) return false;
    if ( it.kind === 'payment-gateways' ) return true;
    return hasGatewayItem( it.children );
} );

const gatewaysIn = ( steps ) => ( Array.isArray( steps ) ? steps : [] )
    .some( ( step ) => hasGatewayItem( step?.items ) || hasGatewayItem( step?.decorations ) );

// The gateway section is the only thing on the form that says why it cannot
// take money. Where it is not on screen beside the submit, whether the author
// removed the block or put it on another page, the submit says so itself.
function gatewaysExplainedBeside( steps, config ) {
    return gatewaysIn( steps ) || hasGatewayItem( config?.preamble );
}

// Server-first: the submit response is the only number that matches what was
// charged. A redirect gateway returns the donor on a fresh page where none of
// that survives, so the stash written at submit is the fallback, and the form's
// own values come last because a reset would have cleared them.
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

// The donation did not prove the address: the form takes one on trust and a card
// need not match it, so this sends the same link the portal sends and the
// mailbox does the proving. It never reports whether the address is known, for
// the same reason the portal's own endpoint does not.
function PortalLink( { email, config } ) {
    const [ sent, setSent ]     = useState( false );
    const [ failed, setFailed ] = useState( false );
    const [ busy, setBusy ]     = useState( false );
    const portal = config.portal || {};

    if ( ! portal.url || ! email ) return null;

    // The same element the donor pressed, still where they left it, saying what
    // happened: a line of grey text instead reads as the button having failed
    // and vanished.
    if ( sent ) {
        return (
            <button
                type="button"
                class="gratora-form__button gratora-form__button--secondary gratora-form__portal-link is-sent"
                disabled
            >
                { config.i18n.portalLinkSent }
            </button>
        );
    }

    // Saying "check your email" after a failed request sends the donor to wait
    // for something nobody sent, so the portal is offered plainly instead.
    if ( failed ) {
        return (
            <a class="gratora-form__button gratora-form__button--secondary" href={ portal.url }>
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
            class="gratora-form__button gratora-form__button--secondary gratora-form__portal-link"
            disabled={ busy }
            onClick={ send }
        >
            { config.i18n.manageGiving }
        </button>
    );
}

function DonationReceipt( { receipt, config } ) {
    const known = Number.isFinite( receipt.amountCents ) && receipt.currency;

    if ( ! known && ! receipt.email ) return null;

    const i18n = config.i18n || {};
    const freq = frequencyLabel( receipt.frequency, i18n );

    return (
        <dl class="gratora-form__summary gratora-form__summary--receipt">
            { known && (
                <div class="gratora-form__summary-row">
                    <dt>{ i18n.total }</dt>
                    <dd class="gratora-form__summary-amount">
                        { formatAmount( receipt.amountCents, receipt.currency ) }
                    </dd>
                </div>
            ) }
            { freq && (
                <div class="gratora-form__summary-row">
                    <dt>{ i18n.frequency }</dt>
                    <dd>{ freq }</dd>
                </div>
            ) }
            { receipt.email && (
                <div class="gratora-form__summary-row">
                    <dt>{ i18n.email }</dt>
                    <dd>{ receipt.email }</dd>
                </div>
            ) }
        </dl>
    );
}

// Read transfer details before clearing the stash. Offline transfers await payment and must not
// become retry parents after reload.
function PendingScreen( { state, dispatch, config } ) {
    const [ receipt ] = useState( () => receiptOf( state ) );

    useEffect( () => {
        clearPending();
    }, [] );

    return (
        <div class="gratora-form__success gratora-form__success--pending" role="status">
            <div class="gratora-form__success-icon gratora-form__success-icon--pending" aria-hidden="true">⏳</div>
            <h3>{ config.i18n.pendingTitle }</h3>
            <p class="gratora-form__thank-you">{ config.i18n.pendingMessage }</p>
            <DonationReceipt receipt={ receipt } config={ config } />
            { state.submission?.reference && (
                <p class="gratora-form__reference">{ state.submission.reference }</p>
            ) }
            <div class="gratora-form__success-actions">
                <button
                    type="button"
                    class="gratora-form__button gratora-form__button--secondary"
                    onClick={ () => { clearPending(); dispatch( { type: 'RESET' } ); } }
                >
                    { config.i18n.donateAgain }
                </button>
                <PortalLink email={ receipt.email } config={ config } />
            </div>
        </div>
    );
}

/**
 * A donor is back from their bank and the browser could not find out what
 * happened. The pending stash is deliberately kept: it is what a retried check
 * reads, and the donation may yet turn out to be paid.
 *
 * No Donate again button. The one thing this screen knows is that a second
 * payment might be a second charge.
 */
function UnresolvedScreen( { state, dispatch, config } ) {
    const reference = String( state.submission?.reference || '' );
    const ret       = detectStripeReturn( reference || null );

    return (
        <div class="gratora-form__success gratora-form__success--pending" role="alert">
            <div class="gratora-form__success-icon gratora-form__success-icon--pending" aria-hidden="true">⏳</div>
            <h3>{ config.i18n.unresolvedTitle || config.i18n.pendingTitle }</h3>
            <p class="gratora-form__thank-you">{ state.message || config.i18n.returnUnresolved || config.i18n.error }</p>
            { reference && (
                <p class="gratora-form__reference">{ reference }</p>
            ) }
            { ret && config.stripe?.publishableKey && (
                <div class="gratora-form__success-actions">
                    <button
                        type="button"
                        class="gratora-form__button gratora-form__button--primary"
                        onClick={ () => resolveReturn( config, ret, dispatch ) }
                    >
                        { config.i18n.checkAgain }
                    </button>
                </div>
            ) }
        </div>
    );
}

// A gateway shipped outside core registers with
// window.gratora.formGateways.register( id, { component, ready } ) before the
// runtime mounts; `ready` gets that gateway's slice of the form config and
// answers whether it can actually collect a payment.
function registeredGateway( id ) {
    if ( ! id ) return null;
    const reg = typeof window !== 'undefined' ? window.gratora?.formGateways : null;
    const entry = reg && typeof reg.get === 'function' ? reg.get( id ) : null;

    return entry && typeof entry.component === 'function' ? entry : null;
}

// On a long or paged form the invalid field may be off-screen, so the button
// reads as a dead click. Runs after the error re-render has committed.
function formRoot( hostId ) {
    return hostId ? document.getElementById( hostId ) : null;
}

function focusFirstInvalid( hostId ) {
    requestAnimationFrame( () => {
        // This form, not the first one on the page: two forms on one page had
        // the second one's failed submit scroll the reader to a field in the
        // first, or to nothing at all.
        const root = formRoot( hostId );
        const el   = root
            ? root.querySelector( '[aria-invalid="true"]' )
            : document.querySelector( '.gratora-donation-form [aria-invalid="true"]' );
        if ( el && typeof el.focus === 'function' ) {
            el.focus( { preventScroll: true } );
            el.scrollIntoView( { behavior: 'smooth', block: 'center' } );
        }
    } );
}

function readConfig( form ) {
    // Not keyed on type: core's inline script filter lets an optimizer rewrite
    // it, and the config would then be lost.
    const node = form.querySelector( 'script[data-gratora-form-config]' );
    if ( ! node ) return null;
    try {
        return JSON.parse( node.textContent || '{}' );
    } catch {
        return null;
    }
}

// A donor step's items interleave fields and content in authored order. Each
// maximal run of consecutive field-items renders as one DonorStep, so a row
// grid stays intact, with decorations inline between the runs.
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
            <div class="gratora-form__step" data-step="donor">
                <StepItems items={ step?.items } state={ state } dispatch={ dispatch } config={ config } />
            </div>
        );
    }
    const StepComp = STEP_RENDERERS[ type ];
    return (
        <div class="gratora-form__step" data-step={ type }>
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

    // The honeypot is hidden in SCSS and the token is HMAC-signed, so a scripted
    // submission cannot mint one. Both gates are enforced in AntiSpamGuard.
    const honeypotName = config.spam?.honeypotName || 'form_ref';
    const formToken    = config.spam?.formToken || '';
    const embed        = embedOf( config );
    const [ honeypot, setHoneypot ] = useState( '' );

    // Re-entrancy guard: a double-click fires onSubmit twice in the same tick,
    // before the disabled re-render lands, which would create two pending
    // donations. The ref flips synchronously and re-arms on every exit.
    const inFlight = useRef( false );

    const onSubmit = useCallback( async () => {
        if ( inFlight.current ) return;
        inFlight.current = true;
        try {
            for ( const s of fieldSteps( state ) ) {
                const errors = validateStep( s, state );
                if ( Object.keys( errors ).length > 0 ) {
                    // Jump to the page owning the errored field, or a paged form
                    // dead-ends with no feedback when the error is on a page
                    // before the submit button.
                    dispatch( { type: 'SET_ERRORS', errors, step: s.page || 0 } );
                    focusFirstInvalid( config.hostId );
                    return;
                }
            }
            dispatch( { type: 'SUBMIT_START' } );
            // A host document carries no form token of its own: it trades its
            // key for one, and the in-flight ref covers the extra await.
            let embedToken = '';
            if ( embed ) {
                embedToken = await exchangeEmbedToken( embed );
                if ( ! embedToken ) {
                    dispatch( { type: 'SUBMIT_ERROR', message: config.i18n.error } );
                    return;
                }
            }
            // Retries spend the original attempt’s budget. Recover redirect state from the
            // stash only when this form owns it; exclude pending offline transfers.
            const prior  = state.submission
                || ( ownsPendingReturn( config.hostId ) ? readPending() : {} );
            const priorT = prior?.status_token || prior?.statusToken || '';
            const retry  = ( prior?.reference && priorT )
                ? { reference: prior.reference, status_token: priorT }
                : null;
            // X-WP-Nonce only when present (logged-in users), so a page-cached
            // form never sends a stale nonce the REST layer would 403.
            const body = JSON.stringify( {
                ...buildPayload( state ),
                ...( config.extra ? { extra: config.extra } : {} ),
                ...( retry ? { _retry: retry } : {} ),
                _ft: embed ? embedToken : formToken,
                _hp: honeypot,
                ...( embed ? { _ek: embed.key || '', _proof: 'embed' } : {} ),
            } );

            const post = ( nonce ) => fetch( config.rest, {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    ...( nonce ? { 'X-WP-Nonce': nonce } : {} ),
                },
                body,
            } );

            let res = await post( config.nonce );

            // Omit stale WordPress nonces on this public route; authentication rejects them
            // before donation handling.
            if ( res.status === 403 ) {
                const why = await res.clone().json().catch( () => null );
                if ( why?.code === 'rest_cookie_invalid_nonce' ) {
                    res = await post( '' );
                }
            }
            // A proxy or security plugin can answer with an HTML block page,
            // which parses to nothing. Reading it before the status is checked
            // would throw past every curated message below.
            const data = await res.json().catch( () => null );
            if ( ! res.ok || ! data ) {
                // The trap is invisible, so a donor who somehow has a value in it
                // cannot clear it and every retry would be refused the same way.
                // A bot that refills it on retry is refused again.
                setHoneypot( '' );
                dispatch( { type: 'SUBMIT_ERROR', message: ( data && data.message ) || config.i18n.error } );
                return;
            }
            // Stashed on every path, not just the redirecting one: the status
            // token is deliberately kept out of the return URL, so anything
            // outliving this closure has no other source for it.
            rememberPending( data, state.values, config.hostId, !! embed );

            if ( data.redirect_url ) {
                window.location.assign( data.redirect_url );
                return;
            }
            // PayPal hands back its own buttons step instead of a secret. Same
            // rule as Stripe: with no client id there is no way to collect
            // payment, so fail rather than thank a donor who paid nothing.
            if ( data.paypal ) {
                if ( config.paypal?.clientId ) {
                    dispatch( {
                        type: 'AWAIT_PAYMENT',
                        payment: {
                            paypal:      data.paypal,
                            reference:   data.reference,
                            // Capture refuses a reference on its own, so the
                            // approval has nothing to send without this.
                            statusToken: data.status_token,
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
            // A gateway shipped outside core, which declared a browser payload
            // server-side and registered a component here. Same rule as the
            // ones above, with the gateway deciding what "ready" means.
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
            // step, which a missing publishable key makes impossible: an error,
            // never a "thank you" with no payment.
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
                            // Passed to the Payment Element as defaultValues so
                            // Stripe does not ask again for details this form
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
            // Money has moved only when the server reports 'paid'. An offline or
            // otherwise pending donation arrives here with no redirect and no
            // secret, and gets its own state, never the completed thank-you.
            dispatch( {
                type: data.status === 'paid' ? 'SUBMIT_SUCCESS' : 'SUBMIT_PENDING',
                data,
            } );
        } catch {
            // The engine's own wording ("Failed to fetch") is untranslated and
            // tells a donor nothing, so the banner keeps the curated copy.
            dispatch( { type: 'SUBMIT_ERROR', message: config.i18n.error } );
        } finally {
            inFlight.current = false;
        }
    }, [ state, config, dispatch, formToken, honeypot, embed ] );

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

        const holds = [];

        window.dispatchEvent( new CustomEvent( COMPLETED_EVENT, {
            detail: {
                reference,
                statusToken: statusTokenFor( reference, state ),
                status:      state.status,
                // A listener whose work outlives the dispatch, anything that
                // has to reach the server, hands its promise here. Without it
                // a thank-you redirect replaces the page mid-flight and the
                // work is discarded with the document.
                waitUntil: ( promise ) => {
                    if ( promise && typeof promise.then === 'function' ) holds.push( promise );
                },
            },
        } ) );

        // After the announcement, never before: a thank-you URL replaces the
        // page, and a listener that has not run by then never will. Keeping it
        // in the effect also fires it once rather than on every render pass.
        if ( state.status === 'success' && config.thanks?.redirect ) {
            const go = () => window.location.assign( config.thanks.redirect );

            if ( holds.length === 0 ) {
                go();
            } else {
                Promise.race( [
                    Promise.allSettled( holds ),
                    new Promise( ( resolve ) => setTimeout( resolve, COMPLETED_HOLD_MS ) ),
                ] ).then( go, go );
            }
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [ state.status, state.submission, state.payment, config.thanks?.redirect ] );

    // Mount payment at the gateway block only if it is on the current page; otherwise use the
    // fallback.
    const gatewayOnScreen = gatewaysIn(
        ( state.steps || [] ).filter( ( step ) => ( step.page || 0 ) === state.step )
    );

    if ( state.status === 'payment' && ! gatewayOnScreen ) {
        const PaymentStep = paymentComponentFor( state.payment );
        return (
            <ErrorBoundary>
                <PaymentStep config={ config } payment={ state.payment } dispatch={ dispatch } />
            </ErrorBoundary>
        );
    }

    if ( state.status === 'confirming' ) {
        return (
            <div class="gratora-form__confirming" role="status">
                <span class="gratora-form__spinner" aria-hidden="true" />
                <p>{ config.i18n.confirming || config.i18n.processing }</p>
            </div>
        );
    }

    // Bank debit, authorised and on its way. Distinct from pending: the donor
    // has done everything and no instructions are coming, so the message must
    // not ask them for anything.
    if ( state.status === 'processing' ) {
        return (
            <div class="gratora-form__success gratora-form__success--pending" role="status">
                <div class="gratora-form__success-icon gratora-form__success-icon--pending" aria-hidden="true">⏳</div>
                <h3>{ config.i18n.processingTitle || config.i18n.pendingTitle }</h3>
                <p class="gratora-form__thank-you">{ config.i18n.processingMessage || config.i18n.pendingMessage }</p>
                <DonationReceipt receipt={ receiptOf( state ) } config={ config } />
                { state.submission?.reference && (
                    <p class="gratora-form__reference">{ state.submission.reference }</p>
                ) }
                <div class="gratora-form__success-actions">
                    <button
                        type="button"
                        class="gratora-form__button gratora-form__button--secondary"
                        onClick={ () => { clearPending(); dispatch( { type: 'RESET' } ); } }
                    >
                        { config.i18n.donateAgain }
                    </button>
                    <PortalLink email={ receiptOf( state ).email } config={ config } />
                </div>
            </div>
        );
    }

    if ( state.status === 'unresolved' ) {
        return <UnresolvedScreen state={ state } dispatch={ dispatch } config={ config } />;
    }

    if ( state.status === 'pending' ) {
        // Recorded but not paid, as with an offline or bank transfer: no
        // completed-donation redirect and no paid thank-you. Instructions go out
        // by email on submit.
        return <PendingScreen state={ state } dispatch={ dispatch } config={ config } />;
    }

    if ( state.status === 'success' ) {
        // A configured thank-you URL takes precedence over this card, but that
        // navigation happens in the completion effect above so anything
        // listening for the donation runs first.
        const message = config.thanks?.message || '';
        return (
            <div class="gratora-form__success" role="status">
                <div class="gratora-form__success-icon" aria-hidden="true">✓</div>
                <h3>{ config.i18n.thanks }</h3>
                { message && (
                    <p class="gratora-form__thank-you">{ message }</p>
                ) }
                <DonationReceipt receipt={ receiptOf( state ) } config={ config } />
                { state.submission?.reference && (
                    <p class="gratora-form__reference">{ state.submission.reference }</p>
                ) }
                <div class="gratora-form__success-actions">
                    <button
                        type="button"
                        class="gratora-form__button gratora-form__button--secondary"
                        onClick={ () => { clearPending(); dispatch( { type: 'RESET' } ); } }
                    >
                        { config.i18n.donateAgain }
                    </button>
                    <PortalLink email={ receiptOf( state ).email } config={ config } />
                </div>
            </div>
        );
    }

    const honeypotInput = (
        <div class="gratora-form__hp" aria-hidden="true">
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

    // Root content authored before a gratora/steps wizard, rendered once above the
    // form so it does not collapse onto the first page.
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

// Paying settles the form: the fields, the recap and the form's own Donate go
// quiet so the gateway's Pay is the only button on screen. Shared here rather
// than in each layout, where one could omit it and leave two buttons competing
// for the same money.
const formRootClass = ( state, variant ) => [
    'gratora-form',
    variant,
    state.status === 'payment' && 'gratora-form--settled',
].filter( Boolean ).join( ' ' );

function SinglePageView( { state, dispatch, config, onSubmit } ) {
    // Same rule as the paged view: without it a single-page form shows a live
    // Donate button under "No payment method accepts X".
    const noGateway  = visibleGateways( config, state ).length === 0;

    // Owned here rather than in the selector, which only renders where the
    // author placed its block.
    const gatewayIds = visibleGateways( config, state ).map( ( o ) => o.id ).join( ',' );
    useEffect( () => {
        keepGatewayValid( config, state, dispatch );
    }, [ gatewayIds, state.gateway ] );
    const unexplained = noGateway && ! gatewaysExplainedBeside( state.steps, config );
    const submitStep = state.steps.find( ( s ) => s.type === 'submit' );
    const submitLabel = interpolateLabel(
        submitStep?.label || config.i18n.donateNow,
        state,
        config
    );
    return (
        <div class={ formRootClass( state, 'gratora-form--inline' ) }>
            { state.steps.map( ( s, i ) => (
                <StepView key={ i } step={ s } state={ state } dispatch={ dispatch } config={ config } />
            ) ) }
            { state.status === 'error' && state.message && (
                <div class="gratora-form__error" role="alert">{ state.message }</div>
            ) }
            { unexplained && (
                <div class="gratora-form__gateways-empty" role="alert">{ emptyMessage( config, state ) }</div>
            ) }
            <div class={ `gratora-form__nav gratora-form__nav--align-${ submitStep?.align || 'left' }` }>
                <button
                    type="button"
                    class="gratora-form__button gratora-form__button--primary"
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
    // Validation walks fieldSteps so preamble fields, which FormBody renders
    // once and never as a page step, check with the first page instead of
    // bypassing validation entirely.
    const checkSteps = fieldSteps( state ).filter( ( s ) => ( s.page || 0 ) === current );

    const page = pages[ current ] || {};
    const pageTitle = decodeEntities( page.title || '' );
    const showPageTitle = page.showTitle !== false && pageTitle !== '';

    // Moving between pages replaces the whole form body, so without moving
    // focus a screen reader keeps reading a button that was swapped underneath
    // it and a keyboard user is left at the bottom of a page that is gone.
    const pageMounted = useRef( false );
    useEffect( () => {
        if ( ! pageMounted.current ) { pageMounted.current = true; return; }
        const root = formRoot( config.hostId ) || document.querySelector( '.gratora-donation-form' );
        const h    = root?.querySelector( '.gratora-form__page-title, .gratora-form__bar-title' );
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
        if ( Object.keys( errors ).length > 0 ) focusFirstInvalid( config.hostId );
    }, [ checkSteps, state, dispatch ] );

    // No enabled gateway takes the chosen currency. GatewaySelect says so where
    // the choice is made; this stops the donor reaching a server refusal by
    // pressing the button anyway.
    const noGateway = visibleGateways( config, state ).length === 0;

    // Owned here rather than in the selector, which only renders where the
    // author placed its block.
    const gatewayIds = visibleGateways( config, state ).map( ( o ) => o.id ).join( ',' );
    useEffect( () => {
        keepGatewayValid( config, state, dispatch );
    }, [ gatewayIds, state.gateway ] );
    const unexplained = isLast && noGateway && ! gatewaysExplainedBeside( pageSteps, config );

    const submit = useCallback( () => {
        if ( noGateway ) return;

        const errors = {};
        for ( const s of checkSteps ) {
            Object.assign( errors, validateStep( s, state ) );
        }
        if ( Object.keys( errors ).length > 0 ) {
            dispatch( { type: 'SET_ERRORS', errors } );
            focusFirstInvalid( config.hostId );
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
            class="gratora-form__button gratora-form__button--primary"
            disabled={ state.status === 'submitting' || ( isLast && noGateway ) }
            onClick={ isLast ? submit : onNext }
        >
            { state.status === 'submitting'
                ? config.i18n.processing
                : ( isLast ? submitLabel : nextLabel ) }
        </button>
    );

    const error = state.status === 'error' && state.message && (
        <div class="gratora-form__error" role="alert">{ state.message }</div>
    );

    // Not a selector: the payment-gateways block owns where that goes and
    // whether the form has one, and a fallback here would keep drawing one the
    // author removed. This is only the reason the button below cannot work, on
    // the pages where the section that would have said it is not on screen.
    const emptyNotice = unexplained && (
        <div class="gratora-form__gateways-empty" role="alert">{ emptyMessage( config, state ) }</div>
    );

    if ( progressStyle === 'bar' ) {
        const pct = pages.length > 1
            ? Math.round( ( ( current + 1 ) / pages.length ) * 100 )
            : 100;
        return (
            <div class={ formRootClass( state, 'gratora-form--paged-bar' ) }>
                <header class="gratora-form__bar-header">
                    { current > 0 && state.status !== 'payment' ? (
                        <button
                            type="button"
                            class="gratora-form__bar-back"
                            aria-label={ prevLabel }
                            // The primary button is disabled while a submit is
                            // in flight; leaving Back live let a donor walk off
                            // the page the payment step is on.
                            disabled={ state.status === 'submitting' }
                            onClick={ onPrev }
                        >
                            <span aria-hidden="true">{ backGlyph() }</span>
                        </button>
                    ) : (
                        <span class="gratora-form__bar-back" aria-hidden="true" />
                    ) }
                    { showPageTitle && (
                        <h3 class="gratora-form__bar-title">{ pageTitle }</h3>
                    ) }
                    <span class="gratora-form__bar-spacer" aria-hidden="true" />
                </header>
                <div
                    class="gratora-form__bar-track"
                    role="progressbar"
                    aria-valuemin="0"
                    aria-valuemax={ pages.length }
                    aria-valuenow={ current + 1 }
                >
                    <div class="gratora-form__bar-fill" style={ { width: `${ pct }%` } } />
                </div>
                <div class="gratora-form__body">
                    { pageSteps.map( ( s, i ) => (
                        <StepView key={ i } step={ s } state={ state } dispatch={ dispatch } config={ config } />
                    ) ) }
                    { error }
                    { emptyNotice }
                    <div class={ `gratora-form__nav gratora-form__nav--align-${ ( isLast ? submitStep?.align : null ) || 'end' }` }>{ primary }</div>
                </div>
            </div>
        );
    }

    return (
        <div class={ formRootClass( state ) }>
            { showPageTitle && (
                <h3 class="gratora-form__page-title">{ pageTitle }</h3>
            ) }
            { pageSteps.map( ( s, i ) => (
                <StepView key={ i } step={ s } state={ state } dispatch={ dispatch } config={ config } />
            ) ) }
            { error }
            { emptyNotice }
            <div class={ `gratora-form__nav${ isLast && submitStep?.align ? ` gratora-form__nav--align-${ submitStep.align }` : '' }` }>
                { current > 0 ? (
                    <button
                        type="button"
                        class="gratora-form__button gratora-form__button--secondary"
                        disabled={ state.status === 'submitting' }
                        onClick={ onPrev }
                    >
                        { prevLabel }
                    </button>
                ) : (
                    // Placeholder so space-between keeps the primary button
                    // anchored right on the first page.
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

export function useFocusTrap( ref, active ) {
    useEffect( () => {
        if ( ! active || ! ref.current ) return;
        const el = ref.current;
        const doc = el.ownerDocument;
        const prev = doc.activeElement;
        const first = el.querySelector( FOCUSABLE );
        if ( first ) first.focus();

        const onTab = ( e ) => {
            if ( e.key !== 'Tab' ) return;
            const node = ref.current;
            if ( ! node ) return;
            const nodes = [ ...node.querySelectorAll( FOCUSABLE ) ];
            if ( ! nodes.length ) return;
            const firstNode = nodes[ 0 ];
            const last  = nodes[ nodes.length - 1 ];
            // Disabling the focused control drops focus to the body, and the
            // dialog is aria-modal: from there Tab walks a page the reader is
            // told is not there. Listening on the document is what keeps the
            // handler reachable once focus has left the panel.
            const outside = ! node.contains( doc.activeElement );
            if ( e.shiftKey && ( outside || doc.activeElement === firstNode ) ) {
                e.preventDefault();
                last.focus();
            } else if ( ! e.shiftKey && ( outside || doc.activeElement === last ) ) {
                e.preventDefault();
                firstNode.focus();
            }
        };
        doc.addEventListener( 'keydown', onTab );
        return () => {
            doc.removeEventListener( 'keydown', onTab );
            if ( prev && typeof prev.focus === 'function' ) prev.focus();
        };
    }, [ active ] );
}

function ModalShell( { children, openLabel, config, initiallyOpen = false } ) {
    // Read once, on the first render: a donor coming back from their bank never
    // pressed the trigger, and a shut modal never mounts the body that shows
    // the outcome and fires the completion event.
    const [ open, setOpen ] = useState( !! initiallyOpen );
    const panelRef = useRef( null );

    useFocusTrap( panelRef, open );

    useEffect( () => {
        const onKey = ( e ) => { if ( e.key === 'Escape' ) setOpen( false ); };
        if ( open ) document.addEventListener( 'keydown', onKey );
        return () => document.removeEventListener( 'keydown', onKey );
    }, [ open ] );

    return (
        <div class="gratora-modal-host">
            <button
                type="button"
                class="gratora-form__button gratora-form__button--primary gratora-modal-trigger"
                onClick={ () => setOpen( true ) }
            >
                { openLabel }
            </button>
            { open && (
                <div class="gratora-modal" role="dialog" aria-modal="true" aria-label={ config.i18n.formTitle || '' }>
                    { /* eslint-disable-next-line jsx-a11y/no-static-element-interactions, jsx-a11y/click-events-have-key-events -- decorative overlay; Escape and the close button provide keyboard dismissal */ }
                    <div class="gratora-modal__backdrop" aria-hidden="true" onClick={ () => setOpen( false ) } />
                    <div class="gratora-modal__panel" ref={ panelRef }>
                        <button
                            type="button"
                            class="gratora-modal__close"
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

/**
 * Keep redirect markers until Stripe returns a terminal result so donors can retry status
 * checks.
 */
function resolveReturn( config, ret, dispatch ) {
    const embed = embedOf( config );
    const unresolved = () => dispatch( {
        type:    'RETURN_UNRESOLVED',
        data:    { reference: ret.reference },
        message: config.i18n.returnUnresolved || config.i18n.error,
    } );

    dispatch( { type: 'CONFIRMING' } );

    return resolveStripeReturn( config.stripe.publishableKey, ret.clientSecret )
        .then( ( status ) => {
            const outcome = returnOutcome( status );
            if ( outcome === 'unknown' ) {
                unresolved();
                return;
            }

            // A secret on the URL proves an intent was paid, never that this
            // donation was. Only the server holds both.
            if ( outcome === 'succeeded' && embed ) {
                return confirmPaid( config, ret.reference ).then( ( paid ) => {
                    if ( ! paid ) {
                        unresolved();
                        return;
                    }
                    clearStripeReturnParams();
                    dispatch( { type: 'SUBMIT_SUCCESS', data: { reference: ret.reference } } );
                } );
            }

            clearStripeReturnParams();

            if ( outcome === 'processing' ) {
                dispatch( {
                    type: 'SUBMIT_PENDING',
                    data: { reference: ret.reference, status: 'processing' },
                } );
            } else if ( outcome === 'succeeded' ) {
                dispatch( { type: 'SUBMIT_SUCCESS', data: { reference: ret.reference } } );
            } else {
                dispatch( {
                    type:    'SUBMIT_ERROR',
                    message: config.i18n.notCompleted || config.i18n.error,
                } );
            }
        } )
        .catch( unresolved );
}

function App( { config, host } ) {
    const [ state, dispatch ] = useReducer( reducer, config, initialState );

    const embed = embedOf( config );

    // Resolve only this form’s stashed return before stripping shared URL markers. Compare
    // references as strings.
    //
    // In a frame the party who wrote the return URL and the party who can block
    // storage are the same party, so neither an empty stash nor an unnamed
    // reference is read as this donor's own return: a replayed secret would
    // otherwise thank them for a payment somebody else made.
    const claimReturn = () => {
        const stashed = String( readPending().reference || '' );
        if ( embed ) {
            const onUrl = new URLSearchParams( window.location.search ).get( 'gratora_ref' ) || '';

            return ( stashed !== '' && stashed === onUrl ) ? detectStripeReturn( stashed ) : null;
        }

        return ownsPendingReturn( config.hostId ) ? detectStripeReturn( stashed || null ) : null;
    };

    // Claim each redirect once and share that claim with the resolver and modal; multiple forms
    // must not resolve the same payment.
    const returningHere = useMemo( () => {
        const ret = claimReturn();
        if ( ! ret || ! config.stripe?.publishableKey ) return false;

        const marked = document.querySelector( '.gratora-donation-form[data-gratora-returning]' );
        if ( marked && marked !== host ) return false;

        // The element this instance rendered into, never a lookup by id: a form
        // the shortcode gave no id still shows its donor an outcome, and the
        // modal around it still has to open.
        host.dataset.gratoraReturning = '1';
        window.dispatchEvent( new CustomEvent( RETURN_CLAIMED_EVENT, { detail: { host } } ) );

        return true;
    }, [] );

    // Redirect-based methods such as iDEAL and Bancontact bounce back to
    // return_url carrying Stripe's markers.
    useEffect( () => {
        if ( ! returningHere ) return;
        const ret = claimReturn();
        if ( ret ) resolveReturn( config, ret, dispatch );
    }, [] );

    const body = <FormBody state={ state } dispatch={ dispatch } config={ config } />;

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
                class={ `gratora-form__heading gratora-form__heading--${ d.align || 'left' }` }
            >
                { decodeEntities( d.text ) }
            </Tag>
        );
    }
    if ( d.kind === 'paragraph' ) {
        return (
            <p
                key={ i }
                class={ `gratora-form__paragraph gratora-form__paragraph--${ d.align || 'left' }` }
                dangerouslySetInnerHTML={ { __html: d.html || '' } }
            />
        );
    }
    if ( d.kind === 'divider' ) {
        return (
            <hr
                key={ i }
                class="gratora-form__divider"
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
                class="gratora-form__html"
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
                    <div class="gratora-form__payment-mount">
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
        <div class="gratora-form__decorations">
            { visible.map( ( d, i ) => renderDecorationItem( d, i, values, ctx ) ) }
        </div>
    );
}

function applyUrlPrefills( config ) {
    // A host document's address is written by the add-on, and what it may
    // preselect comes from the key record instead.
    if ( embedOf( config ) ) return;

    const params = new URLSearchParams( window.location.search );
    const raw    = parseInt( params.get( 'gratora_amount' ) || '', 10 );
    const freq   = params.get( 'gratora_frequency' );
    const asked  = String( params.get( 'gratora_currency' ) || '' ).trim().toUpperCase();

    const formCurrency = String( config.currency || '' ).toUpperCase();
    const offered      = Array.isArray( config.currencies ) ? config.currencies : [];
    // Minor units mean nothing without a currency, and a link that named one
    // this form cannot open in carries no figure this form can use. Dropping
    // the amount costs the donor a tap; preselecting the wrong one is money
    // they never decided to give, on a screen they followed the link to skip.
    // A link that names no currency is one written against this form.
    const currency = asked || formCurrency;
    const openable = currency === formCurrency || offered.includes( currency );
    const cents    = openable ? roundToCurrency( raw, currency ) : 0;

    if ( cents > 0 && cents <= maxAmountFor( currency ) ) {
        const amount = config.steps.find( ( s ) => s.type === 'amount' );
        if ( amount ) {
            const own  = currency === formCurrency;
            const list = amount.presets || [];
            // Presets are authored in the form's own currency, so only a
            // prefill in that currency can be one of them.
            const isPreset = own && !! list.find( ( p ) => ( typeof p === 'number' ? p : p?.cents ) === cents );
            // Presets-only forms reject non-preset amounts server-side, so a
            // non-preset prefill would preselect a tile that can never submit.
            if ( isPreset || amount.allowCustom !== false ) {
                if ( ! isPreset && own ) {
                    amount.presets = [ ...list, { cents, impact: '' } ];
                }
                config.__prefillAmount = cents;
                if ( ! own ) config.__prefillCurrency = currency;
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

/**
 * The styling editor posts the authored token map. The server's derived inks are
 * not in it and the strip below takes the previous ones with it, so they are
 * recomputed here: without them a pale accent previews as a white label on a
 * white button, which is not what the published form renders.
 *
 * @param {HTMLElement}           form
 * @param {Record<string,string>} tokens
 */
export function applyPreviewTokens( form, tokens ) {
    // Existing --gratora-* inline vars go first, so a preset that omits a token
    // reverts to the stylesheet default, not a stale value.
    const st = form.style;
    const drop = [];
    for ( let i = 0; i < st.length; i++ ) {
        const n = st[ i ];
        if ( n && n.indexOf( '--gratora-' ) === 0 ) drop.push( n );
    }
    drop.forEach( ( n ) => st.removeProperty( n ) );

    applyThemeTokens( form, { tokens } );

    const derived = derivedInk( tokens );
    for ( const name in derived ) {
        st.setProperty( name, derived[ name ] );
    }
}

/**
 * Detect foreign frames through the parent-origin access check. Exempt marked opaque-origin
 * admin previews.
 */
function framedByAnotherSite() {
    if ( window.gratoraFormPreview ) return false;
    if ( window.top === window.self ) return false;
    try {
        return window.top.location.origin !== window.self.location.origin;
    } catch {
        return true;
    }
}

/**
 * A form cropped inside somebody else's page is a payment screen whose address
 * bar says something other than where the money is going, and the amount can be
 * preset behind the crop. The donor is sent to the real page instead, where
 * they can read the address themselves.
 */
function FramedElsewhere( { i18n } ) {
    return (
        <div class="gratora-form__framed">
            <p>{ i18n.framedTitle || 'This donation form is being shown inside another website.' }</p>
            <a
                class="gratora-form__button gratora-form__button--primary"
                href={ window.location.href }
                target="_top"
                rel="noopener"
            >
                { i18n.framedAction || 'Open the donation page' }
            </a>
        </div>
    );
}

function mount( form ) {
    if ( form.dataset.gratoraMounted === 'true' ) return;

    // Called on every exit path, or the form stays cloaked until the failsafe
    // fires.
    const reveal = () => { form.dataset.gratoraReady = 'true'; };

    const config = readConfig( form );
    if ( ! config ) { reveal(); return; }

    // A host document is framed by definition, and its config says so because
    // this server built it. Nothing a framer controls can put that key here.
    if ( ! embedOf( config ) && framedByAnotherSite() ) {
        form.innerHTML = '';
        form.dataset.gratoraMounted = 'true';
        form.dataset.gratoraFramed  = 'true';
        render( <FramedElsewhere i18n={ config.i18n || {} } />, form );
        reveal();
        return;
    }

    if ( ! Array.isArray( config.steps ) || config.steps.length === 0 ) {
        // Nothing to hydrate; leave the server-rendered fallback visible.
        reveal();
        return;
    }

    // Which element this instance is, so a submission can be traced back to it
    // after a redirect gateway reloads the page.
    config.hostId = form.id || '';

    applyUrlPrefills( config );

    // Seeded from the server so amounts honour the org's choices rather than
    // the visitor's browser locale.
    // With the currency those choices describe: everything else on the form is
    // the donor's own selection and borrows none of it.
    setActiveNumberFormat( config.numberFormat, config.currency );

    const snapshot = form.innerHTML;
    form.innerHTML = '';
    form.dataset.gratoraMounted = 'true';

    // The host element is a real <form>, so a native submit (Enter on a
    // single-field step) would reload the page and wipe in-progress state.
    form.addEventListener( 'submit', ( e ) => e.preventDefault() );

    applyThemeTokens( form, config.theme );

    try {
        render( <App config={ config } host={ form } />, form );
    } catch ( err ) {
        // Restore the static HTML so the form is at least visible.
        // eslint-disable-next-line no-console
        console.error( '[gratora] mount failed', err );
        form.innerHTML = snapshot;
        delete form.dataset.gratoraMounted;
    }
    reveal();
}

function bootAll() {
    const first = document.querySelector( '.gratora-donation-form' );
    const embed = first ? embedOf( readConfig( first ) ) : null;

    // Before hydration, so a runtime error still lets the host's loader finish
    // its handshake and size the frame.
    if ( embed ) postEmbedHeight( embed );

    document.querySelectorAll( '.gratora-donation-form' ).forEach( mount );

    if ( embed ) {
        // Outbound height and nothing else: no message from the host is read,
        // and the preview channel below belongs to this site's own screens.
        if ( typeof ResizeObserver === 'function' ) {
            new ResizeObserver( () => postEmbedHeight( embed ) )
                .observe( document.documentElement );
        }
        return;
    }

    const inIframe = window.parent && window.parent !== window;

    // Inside a preview iframe, a postMessage channel lets the styling editor
    // push token updates without re-fetching the whole document.
    if ( inIframe ) {
        // Trust the parent window for our opaque-origin srcdoc preview; keep strict origin
        // checks on public forms.
        const isSrcdocPreview = window.location.origin === 'null'
            || document.URL === 'about:srcdoc';

        window.addEventListener( 'message', ( event ) => {
            if ( ! isSrcdocPreview && event.origin !== window.location.origin ) return;
            if ( isSrcdocPreview && event.source !== window.parent ) return;
            const data = event.data;
            if ( ! data || typeof data !== 'object' ) return;
            if ( data.type !== 'gratora:apply-tokens' || ! data.tokens ) return;
            document.querySelectorAll( '.gratora-donation-form' ).forEach( ( form ) => {
                applyPreviewTokens( form, data.tokens );
            } );
        } );

        try {
            window.parent.postMessage( { type: 'gratora:preview-ready' }, '*' );
        } catch ( e ) {
            // Cross-origin parent: postMessage can throw. Safe to ignore.
        }
    }

    // The block editor injects the form after load via ServerSideRender, once
    // bootAll has run, so forms arriving later need watching. Scoped to editor
    // and preview surfaces so the public front end is untouched; mount() guards
    // against double-mounts.
    const inEditor = inIframe
        || ( document.body && document.body.classList.contains( 'block-editor-page' ) );
    if ( inEditor ) {
        const observer = new MutationObserver( ( records ) => {
            for ( const rec of records ) {
                rec.addedNodes.forEach( ( node ) => {
                    if ( node.nodeType !== 1 ) return;
                    if ( node.matches && node.matches( '.gratora-donation-form' ) ) mount( node );
                    if ( node.querySelectorAll ) {
                        node.querySelectorAll( '.gratora-donation-form' ).forEach( mount );
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
