/** @jsxImportSource preact */

import { render } from 'preact';
import { useCallback, useReducer, useRef, useState, useEffect } from 'preact/hooks';

import { reducer, initialState, validateStep, buildPayload } from './state/store';
import AmountStep   from './steps/AmountStep';
import DonorStep    from './steps/DonorStep';
import ConfirmStep  from './steps/ConfirmStep';
import ProgressBar  from './components/ProgressBar';
import ErrorBoundary from './components/ErrorBoundary';
import GatewaySelect from './components/GatewaySelect';
import CurrencySwitcher from './components/CurrencySwitcher';
import StripePayment from './components/StripePayment';
import { detectStripeReturn, resolveStripeReturn, clearStripeReturnParams } from './util/stripe';
import { interpolateLabel } from './util/interpolate';
import { setActiveNumberFormat } from './util/format';
import { evaluateCondition } from './state/conditions';
import './runtime.scss';

const STEP_RENDERERS = {
    amount: AmountStep,
    donor:  DonorStep,
    submit: ConfirmStep,
};

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
    const honeypotName = config.spam?.honeypotName || 'website';
    const formToken    = config.spam?.formToken || '';
    const [ honeypot, setHoneypot ] = useState( '' );

    const onSubmit = useCallback( async () => {
        for ( const s of state.steps ) {
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
        try {
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
                dispatch( { type: 'SUBMIT_ERROR', message: data.message || config.i18n.error } );
                return;
            }
            if ( data.redirect_url ) {
                window.location.assign( data.redirect_url );
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
        }
    }, [ state, config, dispatch, formToken, honeypot ] );

    if ( state.status === 'payment' ) {
        return (
            <ErrorBoundary>
                <StripePayment config={ config } payment={ state.payment } dispatch={ dispatch } />
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

    if ( state.status === 'pending' ) {
        // Donation recorded but not yet paid (e.g. offline / bank transfer):
        // no money has moved, so do not honor the completed-donation redirect
        // or show the paid thank-you. Instructions are emailed on submit.
        return (
            <div class="dono-form__success dono-form__success--pending" role="status">
                <div class="dono-form__success-icon dono-form__success-icon--pending" aria-hidden="true">⏳</div>
                <h3>{ config.i18n.pendingTitle }</h3>
                <p class="dono-form__thank-you">{ config.i18n.pendingMessage }</p>
                { state.submission?.reference && (
                    <p class="dono-form__reference">{ state.submission.reference }</p>
                ) }
                <button
                    type="button"
                    class="dono-form__button dono-form__button--secondary"
                    onClick={ () => dispatch( { type: 'RESET' } ) }
                >
                    { config.i18n.donateAgain }
                </button>
            </div>
        );
    }

    if ( state.status === 'success' ) {
        // Form-level redirect takes precedence: an admin who configured a
        // thank-you URL wants the donor on that page, not on the inline
        // success card.
        if ( config.thanks?.redirect ) {
            window.location.assign( config.thanks.redirect );
        }
        const message = config.thanks?.message || '';
        return (
            <div class="dono-form__success" role="status">
                <div class="dono-form__success-icon" aria-hidden="true">✓</div>
                <h3>{ config.i18n.thanks }</h3>
                { message && (
                    <p class="dono-form__thank-you">{ message }</p>
                ) }
                { state.submission?.reference && (
                    <p class="dono-form__reference">{ state.submission.reference }</p>
                ) }
                <button
                    type="button"
                    class="dono-form__button dono-form__button--secondary"
                    onClick={ () => dispatch( { type: 'RESET' } ) }
                >
                    { config.i18n.donateAgain }
                </button>
            </div>
        );
    }

    const honeypotInput = (
        <div class="dono-form__hp" aria-hidden="true">
            <label>
                Website (leave blank)
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
            { ! config.paymentGatewaysPositioned && (
                <ErrorBoundary>
                    <GatewaySelect state={ state } dispatch={ dispatch } config={ config } />
                </ErrorBoundary>
            ) }
            { state.status === 'error' && state.message && (
                <div class="dono-form__error" role="alert">{ state.message }</div>
            ) }
            <div class={ `dono-form__nav dono-form__nav--align-${ submitStep?.align || 'left' }` }>
                <button
                    type="button"
                    class="dono-form__button dono-form__button--primary"
                    disabled={ state.status === 'submitting' }
                    onClick={ onSubmit }
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

    const page = pages[ current ] || {};
    const pageTitle = page.title || '';
    const showPageTitle = page.showTitle !== false && pageTitle !== '';

    const onPrev = useCallback( () => dispatch( { type: 'PREV' } ), [ dispatch ] );

    const onNext = useCallback( () => {
        const errors = {};
        for ( const s of pageSteps ) {
            Object.assign( errors, validateStep( s, state ) );
        }
        dispatch( { type: 'NEXT', errors } );
        if ( Object.keys( errors ).length > 0 ) focusFirstInvalid();
    }, [ pageSteps, state, dispatch ] );

    const submit = useCallback( () => {
        const errors = {};
        for ( const s of pageSteps ) {
            Object.assign( errors, validateStep( s, state ) );
        }
        if ( Object.keys( errors ).length > 0 ) {
            dispatch( { type: 'SET_ERRORS', errors } );
            focusFirstInvalid();
            return;
        }
        onSubmit();
    }, [ pageSteps, state, dispatch, onSubmit ] );

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
            disabled={ state.status === 'submitting' }
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

    // Fallback only: when no payment-gateways block is placed, render with the
    // summary on the final page.
    const gatewaySection = isLast && ! config.paymentGatewaysPositioned && (
        <ErrorBoundary>
            <GatewaySelect state={ state } dispatch={ dispatch } config={ config } />
        </ErrorBoundary>
    );

    if ( progressStyle === 'bar' ) {
        const pct = pages.length > 1
            ? Math.round( ( ( current + 1 ) / pages.length ) * 100 )
            : 100;
        return (
            <div class="dono-form dono-form--paged-bar">
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
                    { gatewaySection }
                    { error }
                    <div class={ `dono-form__nav dono-form__nav--align-${ ( isLast ? submitStep?.align : null ) || 'end' }` }>{ primary }</div>
                </div>
            </div>
        );
    }

    // Default: dots-at-bottom variant.
    return (
        <div class="dono-form">
            { showPageTitle && (
                <h3 class="dono-form__page-title">{ pageTitle }</h3>
            ) }
            { pageSteps.map( ( s, i ) => (
                <StepView key={ i } step={ s } state={ state } dispatch={ dispatch } config={ config } />
            ) ) }
            { gatewaySection }
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
                    labels={ pages.map( ( p ) => p.title || '' ) }
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
        const prev = document.activeElement;
        const first = el.querySelector( FOCUSABLE );
        if ( first ) first.focus();

        const onTab = ( e ) => {
            if ( e.key !== 'Tab' ) return;
            const nodes = [ ...el.querySelectorAll( FOCUSABLE ) ];
            if ( ! nodes.length ) return;
            const first = nodes[ 0 ];
            const last  = nodes[ nodes.length - 1 ];
            if ( e.shiftKey && document.activeElement === first ) {
                e.preventDefault();
                last.focus();
            } else if ( ! e.shiftKey && document.activeElement === last ) {
                e.preventDefault();
                first.focus();
            }
        };
        el.addEventListener( 'keydown', onTab );
        return () => {
            el.removeEventListener( 'keydown', onTab );
            if ( prev && typeof prev.focus === 'function' ) prev.focus();
        };
    }, [ active ] );
}

function ModalShell( { children, openLabel, config } ) {
    const [ open, setOpen ] = useState( false );
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
                    <div class="dono-modal__backdrop" onClick={ () => setOpen( false ) } />
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
        const ret = detectStripeReturn();
        if ( ! ret || ! config.stripe?.publishableKey ) return;
        dispatch( { type: 'CONFIRMING' } );
        clearStripeReturnParams();
        resolveStripeReturn( config.stripe.publishableKey, ret.clientSecret )
            .then( ( status ) => {
                if ( status === 'succeeded' || status === 'processing' ) {
                    dispatch( { type: 'SUBMIT_SUCCESS', data: { reference: ret.reference } } );
                } else {
                    dispatch( { type: 'SUBMIT_ERROR', message: config.i18n.error } );
                }
            } )
            .catch( () => dispatch( { type: 'SUBMIT_ERROR', message: config.i18n.error } ) );
    }, [] );

    const body = <FormBody state={ state } dispatch={ dispatch } config={ config } />;

    if ( config.layout === 'modal' ) {
        return (
            <ModalShell openLabel={ config.i18n.donateNow } config={ config }>
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
                { d.text }
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
    if ( d.kind === 'payment-gateways' ) {
        return (
            <ErrorBoundary key={ i }>
                <GatewaySelect
                    state={ ctx?.state }
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
            if ( ! list.find( ( p ) => ( typeof p === 'number' ? p : p?.cents ) === cents ) ) {
                amount.presets = [ ...list, { cents, impact: '' } ];
            }
            config.__prefillAmount = cents;
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
        window.addEventListener( 'message', ( event ) => {
            // Same-origin only: the styling editor previews the form on the same
            // site. Without this, any page that frames the public donation form
            // could push --dono-* CSS vars (UI-redress on a payment form).
            if ( event.origin !== window.location.origin ) return;
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
