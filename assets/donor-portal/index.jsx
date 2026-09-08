/** @jsxImportSource preact */

import { render } from 'preact';
import { useEffect, useState, useCallback, useMemo, useRef } from 'preact/hooks';
import { __, _n, sprintf } from '@wordpress/i18n';
import { parseTimestamp } from '@fundkit/ui/utils/format';
import { formatAmount } from '../_shared/money';
import { localizedCountries } from '../_shared/countries';
import AmountInput from '../donation-form/components/AmountInput';
import { loadStripeJs } from '../donation-form/util/stripe';
import { recurringStatusLabel, isTerminalPlan } from './statusLabels';
import './portal.scss';

const cfg = window.fundkitPortal || { rest: '/wp-json/fundkit/v1/portal/', nonce: '' };

const FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
function useFocusTrap( ref, active, onClose ) {
    useEffect( () => {
        if ( ! active ) return;

        // Not gated on ref.current: the panel is a child that may not be
        // attached yet when this runs, and bailing out here left the sheet with
        // no trap and no Escape at all until something happened to remount it.
        const doc  = ( ref.current && ref.current.ownerDocument ) || document;
        const prev = doc.activeElement;
        const first = ref.current && ref.current.querySelector( FOCUSABLE );
        if ( first ) first.focus();
        const onKey = ( e ) => {
            // Read the ref rather than the element captured above: the sheet
            // swaps its contents as the donor moves between stages, and a
            // handler bound to the element of the first stage goes with it.
            const node = ref.current;
            if ( ! node ) return;

            if ( e.key === 'Escape' && typeof onClose === 'function' ) {
                e.preventDefault();
                onClose();
                return;
            }
            if ( e.key !== 'Tab' ) return;
            const nodes = [ ...node.querySelectorAll( FOCUSABLE ) ];
            if ( ! nodes.length ) return;
            if ( e.shiftKey && doc.activeElement === nodes[ 0 ] ) {
                e.preventDefault();
                nodes[ nodes.length - 1 ].focus();
            } else if ( ! e.shiftKey && doc.activeElement === nodes[ nodes.length - 1 ] ) {
                e.preventDefault();
                nodes[ 0 ].focus();
            }
        };

        // On the document, not the sheet: focus that has escaped the sheet
        // still has to be brought back, and Escape still has to close it.
        doc.addEventListener( 'keydown', onKey );
        return () => {
            doc.removeEventListener( 'keydown', onKey );
            if ( prev && typeof prev.focus === 'function' ) prev.focus();
        };
    }, [ active ] );
}

// In memory only, populated from /portal/me or /portal/exchange. State-changing
// endpoints reject a request without a matching `X-FundKit-Csrf` header.
let csrfToken = '';

function setCsrfFromResponse( payload ) {
    if ( payload && typeof payload === 'object' && typeof payload.csrf === 'string' && payload.csrf ) {
        csrfToken = payload.csrf;
    }
}

// A 401/403 from any per-tab request bounces the whole app back to sign-in
// rather than leaving one tab stuck on a raw "Request failed".
let onSessionExpired = null;

// The server writes prose for the refusals it means (a withdrawn receipt, a
// renderer whose extension is gone), and the donor is owed it rather than a
// house sentence. Every path that can meet a refusal builds its error here.
async function refusal( r, fallback ) {
    const data = await r.json().catch( () => ({}) );
    return Object.assign( new Error( data.message || fallback ), { status: r.status, data } );
}

// Portal routes use session cookies and X-FundKit-Csrf. Omit WP nonces so stale ones cannot
// block authentication.
function nonceWasRefused( r, err ) {
    return !! cfg.nonce && r.status === 403 && err.data?.code === 'rest_cookie_invalid_nonce';
}

function api( path, init = {} ) {
    // FormData sets its own content type, boundary and all. Declaring JSON over
    // it makes the body unparseable at the other end.
    const isForm = typeof FormData !== 'undefined' && init.body instanceof FormData;

    const send = ( nonce ) => {
        const headers = {
            ...( isForm ? {} : { 'Content-Type': 'application/json' } ),
            ...( nonce ? { 'X-WP-Nonce': nonce } : {} ),
            ...( init.headers || {} ),
        };
        if ( csrfToken ) headers[ 'X-FundKit-Csrf' ] = csrfToken;

        return fetch( `${ cfg.rest }${ path }`, {
            credentials: 'same-origin',
            headers,
            ...init,
        } );
    };

    return ( async () => {
        let r = await send( cfg.nonce );

        if ( ! r.ok ) {
            let err = await refusal( r, __( 'Request failed', 'fundraising-toolkit' ) );

            if ( nonceWasRefused( r, err ) ) {
                r = await send( '' );
                // Proven dead. Clearing it spares every later request the same
                // wasted round trip, and stops the add-on tabs being handed it
                // through extContext.
                if ( r.ok ) cfg.nonce = '';
                else err = await refusal( r, __( 'Request failed', 'fundraising-toolkit' ) );
            }

            if ( ! r.ok ) {
                if ( ( r.status === 401 || r.status === 403 ) && typeof onSessionExpired === 'function' ) {
                    onSessionExpired();
                }
                throw err;
            }
        }

        const ct = r.headers.get( 'content-type' ) || '';
        if ( ct.includes( 'application/pdf' ) ) return r.blob();
        const json = await r.json();
        setCsrfFromResponse( json );
        return json;
    } )();
}

// A document link carries its own single-purpose token, so a refusal here says
// nothing about the portal session and must not sign the donor out of it.
function fetchDocument( url, fallback ) {
    return fetch( url, { credentials: 'same-origin' } ).then( async ( r ) => {
        if ( ! r.ok ) throw await refusal( r, fallback );
        return r.blob();
    } );
}

// Preserves the page's intent params (e.g. ?fundkit_fundraise=10) across the
// magic-link round-trip through email, so registering lands back in the flow
// the donor started rather than the portal overview.
const RETURN_KEY = 'fundkit_portal_return';

function stashReturn() {
    const p = new URLSearchParams( window.location.search );
    p.delete( 'token' );
    const s = p.toString();
    if ( ! s ) return;
    try {
        window.localStorage.setItem( RETURN_KEY, JSON.stringify( { s, ts: Date.now() } ) );
    } catch ( e ) {}
}

function popReturn() {
    try {
        const raw = window.localStorage.getItem( RETURN_KEY );
        if ( ! raw ) return '';
        window.localStorage.removeItem( RETURN_KEY );
        const { s, ts } = JSON.parse( raw );
        return s && Date.now() - ts < 3600000 ? s : ''; // one-shot, 1h freshness
    } catch ( e ) {
        return '';
    }
}

// A payment method that confirms by navigation leaves the portal entirely and
// comes back to the bare URL with the modal gone, so the plan it belongs to and
// the key needed to read the intent are parked where the boot path finds them.
const CARD_RETURN_KEY = 'fundkit_portal_card_return';

function stashCardReturn( planId, publishableKey ) {
    if ( ! planId || ! publishableKey ) return;
    try {
        window.sessionStorage.setItem(
            CARD_RETURN_KEY,
            JSON.stringify( { planId, publishableKey, ts: Date.now() } )
        );
    } catch ( e ) {}
}

function clearCardReturn() {
    try {
        window.sessionStorage.removeItem( CARD_RETURN_KEY );
    } catch ( e ) {}
}

// Strips the setup-intent markers as it reads them, so a reload cannot replay
// the attach.
function takeCardReturn() {
    const clientSecret = new URLSearchParams( window.location.search ).get( 'setup_intent_client_secret' );
    if ( ! clientSecret ) return null;

    try {
        const url = new URL( window.location.href );
        [ 'setup_intent', 'setup_intent_client_secret', 'redirect_status' ]
            .forEach( ( k ) => url.searchParams.delete( k ) );
        window.history.replaceState( {}, '', url.toString() );
    } catch ( e ) {}

    let stashed = null;
    try {
        const raw = window.sessionStorage.getItem( CARD_RETURN_KEY );
        if ( raw ) stashed = JSON.parse( raw );
    } catch ( e ) {}
    clearCardReturn();

    if ( ! stashed || ! stashed.planId || ! stashed.publishableKey || Date.now() - stashed.ts > 3600000 ) return null;
    return { clientSecret, planId: stashed.planId, publishableKey: stashed.publishableKey };
}

function completeCardReturn( { clientSecret, planId, publishableKey } ) {
    return loadStripeJs()
        .then( ( Stripe ) => Stripe( publishableKey ).retrieveSetupIntent( clientSecret ) )
        .then( ( res ) => {
            const intent = res && res.setupIntent;
            const token  = intent && intent.status === 'succeeded' ? intent.payment_method : '';
            if ( ! token ) throw new Error( __( 'That payment method was not saved.', 'fundraising-toolkit' ) );
            return api( `recurring/${ planId }/payment-method/complete`, {
                method: 'POST',
                body:   JSON.stringify( { token } ),
            } );
        } );
}

// Extension-tab seam, on preact/hooks because the portal is a standalone preact
// app; assets/admin/_shared/extensionTabs.jsx is the React counterpart.
const TAB_EVENT   = 'fundkit:tabs:changed';
const PANEL_EVENT = 'fundkit:panels:changed';

function readExtTabs( surface ) {
    const reg = ( window.fundkit && window.fundkit.tabs ) || null;
    return reg && typeof reg.get === 'function' ? reg.get( surface ) : [];
}

function readExtPanels( surface ) {
    const reg = ( window.fundkit && window.fundkit.panels ) || null;
    return reg && typeof reg.get === 'function' ? reg.get( surface ) : [];
}

// Sections an add-on adds inside an existing screen, as opposed to a whole tab.
function useExtensionPanels( surface ) {
    const [ panels, setPanels ] = useState( () => readExtPanels( surface ) );
    useEffect( () => {
        const onChange = ( e ) => {
            if ( ! e.detail || e.detail.surface === surface ) setPanels( readExtPanels( surface ) );
        };
        window.addEventListener( PANEL_EVENT, onChange );
        setPanels( readExtPanels( surface ) );
        return () => window.removeEventListener( PANEL_EVENT, onChange );
    }, [ surface ] );
    return panels;
}

function useExtensionTabs( surface ) {
    const [ tabs, setTabs ] = useState( () => readExtTabs( surface ) );
    useEffect( () => {
        const onChange = ( e ) => {
            if ( ! e.detail || e.detail.surface === surface ) setTabs( readExtTabs( surface ) );
        };
        window.addEventListener( TAB_EVENT, onChange );
        // Catch tabs registered between initial render and this effect.
        setTabs( readExtTabs( surface ) );
        return () => window.removeEventListener( TAB_EVENT, onChange );
    }, [ surface ] );
    return tabs;
}

function ExtensionPanel( { tab, context } ) {
    const ref = useRef( null );
    useEffect( () => {
        if ( ! ref.current || ! tab || typeof tab.mount !== 'function' ) return undefined;
        const cleanup = tab.mount( ref.current, context );
        return () => { if ( typeof cleanup === 'function' ) cleanup(); };
    }, [ tab && tab.id ] );
    return <div ref={ ref } class="dp-ext-panel" />;
}

// Same imperative contract, remounted when the record it describes changes.
function ExtensionSection( { panel, context, token, className = 'dp-detail__section dp-ext-section' } ) {
    const ref = useRef( null );
    useEffect( () => {
        if ( ! ref.current ) return undefined;
        const cleanup = panel.mount( ref.current, context );
        return () => { if ( typeof cleanup === 'function' ) cleanup(); };
    }, [ panel.id, token ] );
    return <div ref={ ref } class={ className } />;
}

function App() {
    const [ me, setMe ]         = useState( null );
    const [ loading, setLoading ] = useState( true );
    const [ error, setError ]   = useState( null );
    const [ tab, setTab ]       = useState( 'overview' );
    const [ openDonation, setOpenDonation ] = useState( null );
    const extTabs = useExtensionTabs( 'portal' );
    const initialExtTabApplied = useRef( false );

    const [ loadError, setLoadError ] = useState( null );
    const [ cardNotice, setCardNotice ] = useState( null );
    const pendingCardReturn = useRef( null );

    // Resolves with the donor, or null when there is no session to load: the
    // magic-link path has to be able to tell "signed in" from "the exchange
    // succeeded and the session did not survive it".
    const loadMe = useCallback( () => {
        setLoadError( null );
        return api( 'me' )
            .then( ( who ) => { setMe( who ); return who; } )
            .catch( ( err ) => {
                // 401/403 means the session is gone. A transient failure
                // (network blip, 5xx) must not bounce a signed-in donor to
                // sign-in; it gets a retry instead.
                if ( err && ( err.status === 401 || err.status === 403 ) ) {
                    setMe( null );
                } else {
                    setLoadError( err?.message || __( 'Could not load your account.', 'fundraising-toolkit' ) );
                }
                return null;
            } )
            .finally( () => setLoading( false ) );
    }, [] );

    // Armed only while signed in: the initial "not signed in yet" 401 during
    // the magic-link flow must not trip it.
    useEffect( () => {
        if ( ! me ) return undefined;
        onSessionExpired = () => {
            setMe( null );
            setError( __( 'Your session expired. Please sign in again.', 'fundraising-toolkit' ) );
        };
        return () => { onSessionExpired = null; };
    }, [ me ] );

    // Lets an add-on tab claim the initial view from URL params (e.g. a
    // "Start fundraising" link landing on ?fundkit_fundraise=<id>). Runs once,
    // after sign-in, when the registry has populated.
    useEffect( () => {
        if ( initialExtTabApplied.current || ! me || ! extTabs.length ) return;
        const params = new URLSearchParams( window.location.search );
        const match  = extTabs.find( ( t ) =>
            ( typeof t.visible !== 'function' || t.visible( me ) ) &&
            typeof t.initialMatch === 'function' && t.initialMatch( params )
        );
        if ( match ) {
            initialExtTabApplied.current = true;
            setTab( match.id );
        }
    }, [ me, extTabs ] );

    useEffect( () => {
        pendingCardReturn.current = takeCardReturn();

        const params = new URLSearchParams( window.location.search );
        const token  = params.get( 'token' );
        if ( token ) {
            // Strip the single-use token up front so a failed exchange never
            // leaves it in the address bar, history or Referer.
            const cleanUrl = new URL( window.location.href );
            cleanUrl.searchParams.delete( 'token' );
            window.history.replaceState( {}, '', cleanUrl.toString() );

            api( 'exchange', { method: 'POST', body: JSON.stringify( { token } ) } )
                .then( () => {
                    // Restore the pre-login intent so an add-on tab's
                    // initialMatch reopens the flow the donor started.
                    const ret = popReturn();
                    if ( ret ) {
                        const url = new URL( window.location.href );
                        url.search = ret;
                        window.history.replaceState( {}, '', url.toString() );
                    }
                    // The token is spent by now. If the cookie the exchange set
                    // did not come back, the donor is looking at a blank
                    // sign-in form with their link already burnt, so the reason
                    // has to be on the screen: silently asking for another one
                    // burns the next link the same way.
                    return loadMe().then( ( who ) => {
                        if ( ! who ) {
                            setError( __( 'Your sign-in link worked, but this browser did not keep you signed in. Check that the web address here matches the one in your email, and that cookies are allowed for this site, then ask for a new link.', 'fundraising-toolkit' ) );
                        }
                    } );
                } )
                .catch( ( err ) => {
                    // The token is single use, so re-opening the link from the
                    // mailbox 401s while the session it already created is
                    // still good. Only fall through to sign-in if that is gone
                    // too, in which case this message is what the donor sees.
                    setError( err.message );
                    return loadMe();
                } );
        } else {
            loadMe();
        }
    }, [ loadMe ] );

    // Finishes the attach the inline path does in place, for a method that
    // resolved by sending the donor away and back.
    useEffect( () => {
        const pending = pendingCardReturn.current;
        if ( ! me || ! pending ) return;
        pendingCardReturn.current = null;
        completeCardReturn( pending )
            .then( () => setCardNotice( { ok: true, text: __( 'Your new payment method is saved. Future donations will use it.', 'fundraising-toolkit' ) } ) )
            .catch( ( e ) => setCardNotice( {
                ok:   false,
                text: e.message || __( 'That payment method was not saved, so your donation still uses the old one.', 'fundraising-toolkit' ),
            } ) );
    }, [ me ] );

    if ( loading ) return <div class="dp-loading">{ __( 'Loading…', 'fundraising-toolkit' ) }</div>;
    if ( ! me && loadError ) {
        return (
            <div class="dp-loading">
                <p class="dp-signin__error">{ loadError }</p>
                <button type="button" class="dp-link" onClick={ () => { setLoading( true ); loadMe(); } }>
                    { __( 'Try again', 'fundraising-toolkit' ) }
                </button>
            </div>
        );
    }
    if ( ! me )    return <SignInPrompt initialError={ error } />;

    const consentsPending = Number( me.consents_pending || 0 );
    const visibleExtTabs  = extTabs.filter(
        ( t ) => typeof t.visible !== 'function' || t.visible( me )
    );
    const extContext = { me, rest: cfg.rest, nonce: cfg.nonce, csrf: csrfToken };

    return (
        <div class="dp">
            <header class="dp__head">
                <h1>{ sprintf( /* translators: %s: donor's first name or full name */ __( 'Hi, %s.', 'fundraising-toolkit' ), me.first_name || me.name ) }</h1>
                <SignOutControls />
            </header>

            { cardNotice && (
                <div class="dp-banner" role={ cardNotice.ok ? 'status' : 'alert' }>
                    <div class="dp-banner__text">{ cardNotice.text }</div>
                    <button
                        type="button"
                        class="dp-banner__action"
                        onClick={ () => { setCardNotice( null ); if ( ! cardNotice.ok ) setTab( 'recurring' ); } }
                    >
                        { cardNotice.ok ? __( 'Dismiss', 'fundraising-toolkit' ) : __( 'Try again', 'fundraising-toolkit' ) }
                    </button>
                </div>
            ) }

            { consentsPending > 0 && tab !== 'consents' && (
                <div class="dp-banner" role="status">
                    <div class="dp-banner__text">
                        <strong>{ __( 'Your privacy preferences need an update.', 'fundraising-toolkit' ) }</strong>{ ' ' }
                        { __( "We've revised the terms for some of the things you previously agreed to. Take a moment to review.", 'fundraising-toolkit' ) }
                    </div>
                    <button
                        type="button"
                        class="dp-banner__action"
                        onClick={ () => setTab( 'consents' ) }
                    >
                        { __( 'Review now', 'fundraising-toolkit' ) }
                    </button>
                </div>
            ) }

            <div class="dp__body">
            <div class="dp__nav" role="tablist" aria-orientation="vertical">
                { [ ...TABS, ...visibleExtTabs ].map( ( t ) => {
                    const showDot = t.id === 'consents' && consentsPending > 0;
                    return (
                        <button
                            key={ t.id }
                            role="tab"
                            aria-selected={ tab === t.id }
                            class={ `dp__tab${ tab === t.id ? ' is-active' : '' }` }
                            onClick={ () => setTab( t.id ) }
                        >
                            { t.label }
                            { showDot && <span class="dp__tab-dot" aria-label={ __( 'needs attention', 'fundraising-toolkit' ) } /> }
                        </button>
                    );
                } ) }
            </div>

            <main class="dp__main">
                { tab === 'overview'    && <Overview  me={ me } /> }
                { tab === 'donations'   && <Donations onOpen={ setOpenDonation } /> }
                { tab === 'recurring'   && <Recurring /> }
                { tab === 'receipts'    && <Receipts /> }
                { tab === 'preferences' && <Preferences /> }
                { tab === 'profile'     && <Profile  me={ me } onSaved={ loadMe } /> }
                { tab === 'consents'    && <Consents onResolved={ ( pending ) => setMe( ( cur ) => cur ? { ...cur, consents_pending: pending } : cur ) } /> }
                { visibleExtTabs.map( ( t ) => (
                    tab === t.id ? <ExtensionPanel key={ t.id } tab={ t } context={ extContext } /> : null
                ) ) }
            </main>
            </div>

            { openDonation && (
                <DonationDetail
                    reference={ openDonation }
                    onClose={ () => setOpenDonation( null ) }
                />
            ) }
        </div>
    );
}

const TABS = [
    { id: 'overview',    label: __( 'Overview', 'fundraising-toolkit' ) },
    { id: 'donations',   label: __( 'Donations', 'fundraising-toolkit' ) },
    { id: 'recurring',   label: __( 'Recurring', 'fundraising-toolkit' ) },
    { id: 'receipts',    label: __( 'Receipts & tax', 'fundraising-toolkit' ) },
    { id: 'preferences', label: __( 'Preferences', 'fundraising-toolkit' ) },
    { id: 'profile',     label: __( 'Profile', 'fundraising-toolkit' ) },
    { id: 'consents',    label: __( 'Consents', 'fundraising-toolkit' ) },
];

/** Sign out all devices and invalidate unused links; state that scope in the control. */
function SignOutControls() {
    return (
        <div class="dp__signout-group">
            <button type="button" class="dp__signout" onClick={ () => {
                api( 'logout-everywhere', { method: 'POST' } ).finally( () => window.location.reload() );
            } }>{ __( 'Sign out', 'fundraising-toolkit' ) }</button>
        </div>
    );
}

function SignInPrompt( { initialError } ) {
    const [ mode, setMode ]           = useState( 'signin' ); // 'signin' | 'register'
    const [ email, setEmail ]         = useState( '' );
    const [ firstName, setFirstName ] = useState( '' );
    const [ lastName, setLastName ]   = useState( '' );
    const [ sent, setSent ]           = useState( false );
    const [ sending, setSending ]     = useState( false );
    const [ error, setError ]         = useState( initialError || null );

    // The magic-link path decides there is no session before it knows why, so
    // the reason can arrive after this form is already on screen. Initial state
    // alone would drop it, and that message is the only account the donor gets
    // of a link that has already been spent.
    useEffect( () => {
        if ( initialError ) setError( initialError );
    }, [ initialError ] );

    const isRegister = mode === 'register';

    const submit = ( e ) => {
        e.preventDefault();
        if ( ! email || ( isRegister && ! firstName.trim() ) ) return;
        setSending( true );
        setError( null );
        // Both routes write without a session to check, so both want proof the
        // caller loaded this page. Minted per day bucket, so a cached portal
        // still carries a token the server accepts.
        const req = isRegister
            ? api( 'register', {
                method: 'POST',
                body:   JSON.stringify( {
                    email,
                    first_name: firstName.trim(),
                    last_name:  lastName.trim(),
                    token:      cfg.token || '',
                } ),
            } )
            : api( 'send-link', {
                method: 'POST',
                body:   JSON.stringify( { email, token: cfg.token || '' } ),
            } );
        req
            .then( () => { stashReturn(); setSent( true ); } )
            .catch( ( err ) => setError( err.message ) )
            .finally( () => setSending( false ) );
    };

    if ( sent ) {
        return (
            <div class="dp-signin">
                <h2>{ __( 'Check your email', 'fundraising-toolkit' ) }</h2>
                <p>{ sprintf(
                    /* translators: %s: action the link performs, either "finish setting up your account" or "sign in" */
                    __( 'If that address is valid, a link to %s is on its way. Open it on any device.', 'fundraising-toolkit' ),
                    isRegister ? __( 'finish setting up your account', 'fundraising-toolkit' ) : __( 'sign in', 'fundraising-toolkit' )
                ) }</p>
                { /* The server quietly refuses a second request inside its send
                     window, so this copy promises nothing about timing. */ }
                <p class="dp-hint">{ __( 'Only one link goes out every few minutes. If nothing arrives shortly, wait a moment before asking for another.', 'fundraising-toolkit' ) }</p>
                { /* Anyone can type anyone's address here, so a name typed
                     against an address that is already waiting for a link is
                     dropped rather than believed. Said to everyone, because
                     saying it only when it happened would answer whether that
                     address has a signup waiting. */ }
                { isRegister && (
                    <p class="dp-hint">{ __( 'Your name is taken from your first signup for an address. If you have signed up before, you may need to set it again in the portal once you are signed in.', 'fundraising-toolkit' ) }</p>
                ) }
                <p class="dp-signin__alt">
                    <button type="button" class="dp-link" onClick={ () => { setSent( false ); setError( null ); } }>
                        { __( 'Use a different email address', 'fundraising-toolkit' ) }
                    </button>
                </p>
            </div>
        );
    }

    return (
        <div class="dp-signin">
            <h2>{ isRegister ? __( 'Create your account', 'fundraising-toolkit' ) : __( 'Donor portal', 'fundraising-toolkit' ) }</h2>
            <p>
                { isRegister
                    ? __( "Set up an account to start fundraising. We'll email you a link to confirm.", 'fundraising-toolkit' )
                    : __( "Enter the email you donated with and we'll send a sign-in link.", 'fundraising-toolkit' ) }
            </p>
            <form class={ isRegister ? 'is-stacked' : null } onSubmit={ submit }>
                { isRegister && (
                    <div class="dp-signin__row">
                        <input
                            type="text"
                            required
                            autocomplete="given-name"
                            value={ firstName }
                            aria-label={ __( 'First name', 'fundraising-toolkit' ) }
                            placeholder={ __( 'First name', 'fundraising-toolkit' ) }
                            onInput={ ( e ) => setFirstName( e.target.value ) }
                        />
                        { /* Not required: plenty of people go by one name, and a
                             blocked signup is worse than a blank surname. */ }
                        <input
                            type="text"
                            autocomplete="family-name"
                            value={ lastName }
                            aria-label={ __( 'Last name', 'fundraising-toolkit' ) }
                            placeholder={ __( 'Last name', 'fundraising-toolkit' ) }
                            onInput={ ( e ) => setLastName( e.target.value ) }
                        />
                    </div>
                ) }
                <input
                    type="email"
                    required
                    autocomplete="email"
                    value={ email }
                    aria-label={ __( 'Email address', 'fundraising-toolkit' ) }
                    placeholder={ __( 'Enter your email address', 'fundraising-toolkit' ) }
                    onInput={ ( e ) => setEmail( e.target.value ) }
                />
                <button type="submit" disabled={ sending }>
                    { sending ? __( 'Sending…', 'fundraising-toolkit' ) : ( isRegister ? __( 'Create account', 'fundraising-toolkit' ) : __( 'Send sign-in link', 'fundraising-toolkit' ) ) }
                </button>
            </form>
            { error && <p class="dp-signin__error">{ error }</p> }
            <p class="dp-signin__alt">
                { isRegister ? __( 'Already have an account or donated before?', 'fundraising-toolkit' ) : __( 'New here and want to fundraise?', 'fundraising-toolkit' ) }{ ' ' }
                <button type="button" class="dp-link" onClick={ () => { setError( null ); setMode( isRegister ? 'signin' : 'register' ); } }>
                    { isRegister ? __( 'Sign in', 'fundraising-toolkit' ) : __( 'Create an account', 'fundraising-toolkit' ) }
                </button>
            </p>
        </div>
    );
}

function Overview( { me } ) {
    return (
        <div class="dp-overview">
            <div class="dp-kpis">
                <Kpi label={ __( 'Lifetime giving', 'fundraising-toolkit' ) } value={ formatAmount( me.total_donated_cents, me.primary_currency || 'USD' ) } />
                <Kpi label={ __( 'Donations', 'fundraising-toolkit' ) } value={ String( me.donations_count ) } />
                <Kpi label={ __( 'Donor since', 'fundraising-toolkit' ) } value={ me.first_donation_at ? formatDate( me.first_donation_at ) : '-' } />
            </div>
            { me.unconverted_count > 0 && (
                <p class="dp-hint">
                    { sprintf(
                        /* translators: %d: number of donations given in another currency. */
                        _n(
                            'Lifetime giving does not include %d donation you gave in another currency.',
                            'Lifetime giving does not include %d donations you gave in other currencies.',
                            me.unconverted_count,
                            'fundraising-toolkit'
                        ),
                        me.unconverted_count
                    ) }
                </p>
            ) }
            <p class="dp-hint">{ __( 'Manage recurring donations, download receipts, and update preferences from the tabs above.', 'fundraising-toolkit' ) }</p>
        </div>
    );
}

function freqLabel( f ) {
    const map = {
        one_time:  __( 'one time', 'fundraising-toolkit' ),
        weekly:    __( 'weekly', 'fundraising-toolkit' ),
        biweekly:  __( 'biweekly', 'fundraising-toolkit' ),
        monthly:   __( 'monthly', 'fundraising-toolkit' ),
        quarterly: __( 'quarterly', 'fundraising-toolkit' ),
        yearly:    __( 'yearly', 'fundraising-toolkit' ),
    };
    return map[ f ] || String( f || '' ).replace( '_', ' ' );
}

/**
 * A tab that could not load is a dead end otherwise: the donor has one red
 * sentence, no control, and the only way back is knowing to reload the page.
 */
function LoadFailure( { message, onRetry } ) {
    return (
        <p class="dp-error">
            { message }{ ' ' }
            <button type="button" class="dp-link" onClick={ onRetry }>{ __( 'Try again', 'fundraising-toolkit' ) }</button>
        </p>
    );
}

function Donations( { onOpen } ) {
    const [ page, setPage ]   = useState( null );
    const [ error, setError ] = useState( null );
    const load = useCallback( () => {
        setError( null );
        api( 'donations' ).then( setPage ).catch( ( e ) => setError( e.message ) );
    }, [] );

    useEffect( () => { load(); }, [ load ] );

    if ( error )   return <LoadFailure message={ error } onRetry={ load } />;
    if ( ! page )  return <p>{ __( 'Loading donations…', 'fundraising-toolkit' ) }</p>;

    const list  = Array.isArray( page.items ) ? page.items : [];
    const total = Number( page.total || list.length );

    if ( ! list.length ) return <p>{ __( 'No donations yet.', 'fundraising-toolkit' ) }</p>;

    return (
        <div class="dp-list">
            { total > list.length && (
                <p class="dp-list__note">
                    { sprintf(
                        /* translators: 1: how many donations are listed, 2: how many the donor has made in total. */
                        __( 'Showing your %1$s most recent donations of %2$s. Ask the organization for the rest.', 'fundraising-toolkit' ),
                        list.length.toLocaleString(),
                        total.toLocaleString()
                    ) }
                </p>
            ) }
            { list.map( ( d ) => (
                <div
                    key={ d.id }
                    class="dp-list__row"
                    role="button"
                    tabIndex={ 0 }
                    onClick={ () => onOpen( d.reference ) }
                    onKeyDown={ ( e ) => { if ( e.key === 'Enter' || e.key === ' ' ) { e.preventDefault(); onOpen( d.reference ); } } }
                    aria-label={ sprintf( /* translators: %s: donation reference */ __( 'View donation %s', 'fundraising-toolkit' ), d.reference ) }
                >
                    <div>
                        <strong>{ formatAmount( d.amount_cents, d.currency ) }</strong>
                        { d.fee_covered_cents > 0 && (
                            <span class="dp-list__pill">{ sprintf( /* translators: %s: formatted fee amount */ __( 'incl. %s fees', 'fundraising-toolkit' ), formatAmount( d.fee_covered_cents, d.currency ) ) }</span>
                        ) }
                        { d.refunded_cents > 0 && (
                            <span class="dp-list__pill">{ sprintf( /* translators: %s: formatted refunded amount */ __( '%s refunded', 'fundraising-toolkit' ), formatAmount( d.refunded_cents, d.currency ) ) }</span>
                        ) }
                        { d.is_anonymous && <span class="dp-list__pill">{ __( 'anonymous', 'fundraising-toolkit' ) }</span> }
                        <div class="dp-list__sub">{ formatDate( d.paid_at ) } · { d.reference }</div>
                    </div>
                    <span class={ `dp-pill dp-pill--${ d.frequency }` }>{ freqLabel( d.frequency ) }</span>
                </div>
            ) ) }
        </div>
    );
}

function DonationDetail( { reference, onClose } ) {
    const [ d, setD ]         = useState( null );
    const [ error, setError ] = useState( null );
    const panelRef = useRef( null );
    const extSections = useExtensionPanels( 'portal-donation' );
    useFocusTrap( panelRef, true, onClose );

    const load = useCallback( () => {
        api( `donations/${ reference }` ).then( setD ).catch( ( e ) => setError( e.message ) );
    }, [ reference ] );

    useEffect( () => { load(); }, [ load ] );

    const toggleAnonymity = ( next ) => {
        api( `donations/${ reference }/anonymity`, { method: 'POST', body: JSON.stringify( { is_anonymous: next } ) } )
            .then( load )
            .catch( ( e ) => setError( e.message ) );
    };

    return (
        // eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions, jsx-a11y/click-events-have-key-events -- click-outside-to-close is a mouse convenience; Escape (focus trap) and the close button provide keyboard dismissal
        <div class="dp-modal" role="dialog" aria-modal="true" aria-label={ __( 'Donation details', 'fundraising-toolkit' ) } onClick={ ( e ) => { if ( e.target === e.currentTarget ) onClose(); } } ref={ panelRef }>
            <div class="dp-modal__panel">
                <button class="dp-modal__close" onClick={ onClose } aria-label={ __( 'Close', 'fundraising-toolkit' ) }>×</button>
                { error && <p class="dp-error">{ error }</p> }
                { ! d ? <p>{ __( 'Loading…', 'fundraising-toolkit' ) }</p> : (
                    <>
                        <div class="dp-detail__head">
                            <div class="dp-detail__amount">{ formatAmount( d.amount_cents, d.currency ) }</div>
                            <div class="dp-detail__meta">{ formatDate( d.paid_at ) } · { d.reference }</div>
                            { d.refunded_cents > 0 && (
                                <div class="dp-detail__refund">
                                    <span>{ sprintf( /* translators: %s: formatted refunded amount */ __( '%s was refunded to you', 'fundraising-toolkit' ), formatAmount( d.refunded_cents, d.currency ) ) }</span>
                                    <strong>{ sprintf( /* translators: %s: formatted amount the organization kept */ __( 'Net %s', 'fundraising-toolkit' ), formatAmount( d.amount_cents - d.refunded_cents, d.currency ) ) }</strong>
                                </div>
                            ) }
                        </div>


                        <dl class="dp-facts">
                            { [
                                [ __( 'Campaign', 'fundraising-toolkit' ), d.campaign_title ],
                                [ __( 'Form', 'fundraising-toolkit' ), d.form_title ],
                                [ __( 'Fund', 'fundraising-toolkit' ), d.fund_name ],
                                [ __( 'Frequency', 'fundraising-toolkit' ), d.frequency === 'one_time' ? __( 'One-off', 'fundraising-toolkit' ) : d.frequency ],
                                [ __( 'Paid with', 'fundraising-toolkit' ), d.payment_method ],
                                [ __( 'Fees you covered', 'fundraising-toolkit' ), d.fee_covered_cents > 0 ? formatAmount( d.fee_covered_cents, d.currency ) : null ],
                                [ __( 'Your note', 'fundraising-toolkit' ), d.note_to_org ],
                            ].filter( ( [ , v ] ) => v ).map( ( [ k, v ] ) => (
                                <div class="dp-facts__row" key={ k }>
                                    <dt>{ k }</dt>
                                    <dd>{ v }</dd>
                                </div>
                            ) ) }
                        </dl>

                        { d.give_again_url && (
                            <div class="dp-detail__section">
                                <a class="dp-action is-primary" href={ d.give_again_url }>
                                    { sprintf( /* translators: %s: formatted donation amount */ __( 'Give again (%s)', 'fundraising-toolkit' ), formatAmount( d.amount_cents, d.currency ) ) }
                                </a>
                            </div>
                        ) }

                        <div class="dp-detail__section">
                            <label class="dp-detail__toggle">
                                <input
                                    type="checkbox"
                                    checked={ d.is_anonymous }
                                    onChange={ ( e ) => toggleAnonymity( e.target.checked ) }
                                />
                                <span>
                                    { __( 'Hide my name from the public list of donors', 'fundraising-toolkit' ) }
                                    <small class="dp-hint">{ __( 'The organization still sees your name on this donation, and your receipt is unchanged.', 'fundraising-toolkit' ) }</small>
                                </span>
                            </label>
                        </div>

                        { extSections.map( ( panel ) => (
                            <ExtensionSection
                                key={ panel.id }
                                panel={ panel }
                                token={ `${ reference }:${ d.id }` }
                                context={ { donation: d, reload: load, api } }
                            />
                        ) ) }
                    </>
                ) }
            </div>
        </div>
    );
}

function Recurring() {
    const [ list, setList ]   = useState( null );
    const [ error, setError ] = useState( null );
    const [ action, setAction ] = useState( null );

    const load = useCallback( () => {
        setError( null );
        api( 'recurring' ).then( setList ).catch( ( e ) => setError( e.message ) );
    }, [] );

    useEffect( () => { load(); }, [ load ] );

    if ( error )    return <LoadFailure message={ error } onRetry={ load } />;
    if ( ! list )   return <p>{ __( 'Loading…', 'fundraising-toolkit' ) }</p>;
    if ( ! list.length ) return <p>{ __( 'No recurring donations.', 'fundraising-toolkit' ) }</p>;

    return (
        <>
            <ul class="dp-list">
                { list.map( ( p ) => (
                    <li key={ p.id }>
                        <div
                            class="dp-list__row"
                            role="button"
                            tabIndex={ 0 }
                            onClick={ () => setAction( p ) }
                            onKeyDown={ ( e ) => { if ( e.key === 'Enter' || e.key === ' ' ) { e.preventDefault(); setAction( p ); } } }
                            aria-label={ sprintf( /* translators: %d: subscription id */ __( 'View recurring donation %d', 'fundraising-toolkit' ), p.id ) }
                        >
                        <div>
                            <strong>{ formatAmount( p.amount_cents, p.currency ) }</strong>
                            <span class="dp-list__pill">{ intervalLabel( p.interval_count, p.interval_unit ) }</span>
                            <div class="dp-list__sub">
                                { sprintf( /* translators: %s: date of the next scheduled payment */ __( 'Next: %s', 'fundraising-toolkit' ), p.next_payment_at ? formatDate( p.next_payment_at ) : '-' ) }
                            </div>
                        </div>
                        <div class="dp-list__actions">
                            <span class={ `dp-pill dp-pill--${ p.status }` }>{ recurringStatusLabel( p.status ) }</span>
                        </div>
                        </div>
                    </li>
                ) ) }
            </ul>

            { action && (
                <RecurringActionSheet
                    plan={ action }
                    onClose={ () => setAction( null ) }
                    onDone={ () => { setAction( null ); load(); } }
                />
            ) }
        </>
    );
}

function RecurringActionSheet( { plan, onClose, onDone } ) {
    const [ stage, setStage ] = useState( 'menu' );
    const [ err, setErr ] = useState( '' );
    const [ approveUrl, setApproveUrl ] = useState( null );
    const [ busy, setBusy ] = useState( false );
    const inFlight = useRef( false );
    const panelRef = useRef( null );
    useFocusTrap( panelRef, true, onClose );

    // Every action here moves money on a schedule, so a second press while the
    // first is in flight must not reach the processor. The gate is a ref, not
    // the busy state: two presses in one tick both read the state their render
    // closed over, which is still false.
    const call = ( body ) => {
        if ( inFlight.current ) return Promise.resolve();
        inFlight.current = true;
        setBusy( true );
        setErr( '' );
        setApproveUrl( null );

        return api( `recurring/${ plan.id }/action`, { method: 'POST', body: JSON.stringify( body ) } )
            .then( onDone )
            .catch( ( e ) => {
                setErr( e.message || __( 'Something went wrong.', 'fundraising-toolkit' ) );
                // PayPal answers a revision with a link the donor must open.
                // refusal() hands back the whole REST body, so the payload the
                // route set sits one level in.
                setApproveUrl( e?.data?.data?.approve_url || e?.data?.approve_url || null );
            } )
            .finally( () => { inFlight.current = false; setBusy( false ); } );
    };

    return (
        // eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions, jsx-a11y/click-events-have-key-events -- click-outside-to-close is a mouse convenience; Escape (focus trap) and the close button provide keyboard dismissal
        <div class="dp-modal" role="dialog" aria-modal="true" aria-label={ __( 'Manage subscription', 'fundraising-toolkit' ) } onClick={ ( e ) => { if ( e.target === e.currentTarget ) onClose(); } } ref={ panelRef }>
            <div class="dp-modal__panel">
                <button class="dp-modal__close" onClick={ onClose } aria-label={ __( 'Close', 'fundraising-toolkit' ) }>×</button>
                { err && <p class="dp-error">{ err }</p> }
                { approveUrl && (
                    <p class="dp-approve">
                        <a href={ approveUrl } target="_blank" rel="noreferrer noopener">
                            { __( 'Approve the change', 'fundraising-toolkit' ) }
                        </a>
                    </p>
                ) }

                { stage === 'menu' && (
                    <>
                        <div class="dp-detail__head">
                            <div class="dp-detail__amount">
                                { formatAmount( plan.amount_cents, plan.currency ) }
                                <span class="dp-detail__interval">
                                    { ' / ' }{ intervalLabel( plan.interval_count, plan.interval_unit ) }
                                </span>
                            </div>
                            <div class="dp-detail__meta">
                                { recurringStatusLabel( plan.status ) }{ plan.id ? ` \u00b7 ${ plan.id }` : '' }
                            </div>
                        </div>

                        <dl class="dp-facts">
                            { [
                                [ __( 'Campaign', 'fundraising-toolkit' ), plan.campaign_title ],
                                [ __( 'Fund', 'fundraising-toolkit' ), plan.fund_name ],
                                [ __( 'Next charge', 'fundraising-toolkit' ), plan.next_payment_at ? formatDate( plan.next_payment_at ) : null ],
                                [ __( 'Last charge', 'fundraising-toolkit' ), plan.last_payment_at ? formatDate( plan.last_payment_at ) : null ],
                                [ __( 'Resumes', 'fundraising-toolkit' ), plan.resume_at ? formatDate( plan.resume_at ) : null ],
                                [ __( 'Giving since', 'fundraising-toolkit' ), plan.started_at ? formatDate( plan.started_at ) : null ],
                                [ __( 'Donations made', 'fundraising-toolkit' ), plan.payments_count || null ],
                                [ __( 'Given in total', 'fundraising-toolkit' ), plan.total_paid_cents ? formatAmount( plan.total_paid_cents, plan.currency ) : null ],
                            ].filter( ( [ , v ] ) => v ).map( ( [ k, v ] ) => (
                                <div class="dp-facts__row" key={ k }>
                                    <dt>{ k }</dt>
                                    <dd>{ v }</dd>
                                </div>
                            ) ) }
                        </dl>

                        { ! isTerminalPlan( plan.status ) && (
                            <h3>{ __( 'Manage subscription', 'fundraising-toolkit' ) }</h3>
                        ) }
                        { plan.status === 'paused' && (
                            <button class="dp-action is-primary" disabled={ busy } onClick={ () => call( { action: 'resume' } ) }>
                                { __( 'Resume', 'fundraising-toolkit' ) }
                            </button>
                        ) }
                        { /* Two shipped gateways handle subscriptions and
                             refuse both of these: a Direct Debit mandate has no
                             pause, and stopping it means cancelling and asking
                             the donor to sign a new one. Offering the buttons
                             anyway got them a raw 422. */ }
                        { ! isTerminalPlan( plan.status ) && (
                            <>
                                { plan.can_pause && plan.status !== 'paused' && (
                                    <>
                                        <button class="dp-action" onClick={ () => setStage( 'pause' ) }>{ __( 'Pause', 'fundraising-toolkit' ) }</button>
                                        <button class="dp-action" disabled={ busy } onClick={ () => call( { action: 'skip_next' } ) }>{ __( 'Skip next charge', 'fundraising-toolkit' ) }</button>
                                    </>
                                ) }
                                <button class="dp-action" onClick={ () => setStage( 'amount' ) }>{ __( 'Change amount', 'fundraising-toolkit' ) }</button>
                                { plan.can_change_interval && (
                                    <button class="dp-action" onClick={ () => setStage( 'interval' ) }>{ __( 'Change frequency', 'fundraising-toolkit' ) }</button>
                                ) }
                                { plan.can_update_payment_method && (
                                    <button class="dp-action" onClick={ () => setStage( 'payment' ) }>{ __( 'Update payment method', 'fundraising-toolkit' ) }</button>
                                ) }
                                <button class="dp-action dp-action--danger" onClick={ () => setStage( 'cancel' ) }>{ __( 'Cancel subscription', 'fundraising-toolkit' ) }</button>
                            </>
                        ) }
                    </>
                ) }

                { stage === 'pause' && (
                    <>
                        <h3>{ __( 'Pause for how long?', 'fundraising-toolkit' ) }</h3>
                        { [ 1, 3, 6, 12 ].map( ( m ) => (
                            <button key={ m } class="dp-action" disabled={ busy } onClick={ () => call( { action: 'pause', months: m } ) }>
                                { sprintf( /* translators: %d: number of months */ _n( '%d month', '%d months', m, 'fundraising-toolkit' ), m ) }
                            </button>
                        ) ) }
                    </>
                ) }

                { stage === 'amount' && (
                    <ChangeAmountForm plan={ plan } busy={ busy } onSubmit={ ( cents ) => call( { action: 'change_amount', amount_cents: cents } ) } />
                ) }

                { stage === 'interval' && (
                    <ChangeFrequencyForm plan={ plan } busy={ busy } onSubmit={ ( frequency ) => call( { action: 'change_interval', frequency } ) } />
                ) }

                { stage === 'payment' && (
                    <UpdatePaymentMethod plan={ plan } onDone={ onDone } onError={ setErr } />
                ) }

                { stage === 'cancel' && (
                    <CancelDeflection plan={ plan } busy={ busy }
                        onPause={  plan.can_pause ? () => setStage( 'pause' ) : null }
                        onSkip={   plan.can_pause ? () => call( { action: 'skip_next' } ) : null }
                        onReduce={ () => setStage( 'amount' ) }
                        onCancel={ ( reason ) => call( { action: 'cancel', reason } ) }
                    />
                ) }
            </div>
        </div>
    );
}

// Two shapes, because the processors differ. Stripe returns a SetupIntent the
// browser confirms, so the card never touches this site. PayPal will not let
// anyone else collect a funding source, so the donor is sent to PayPal.
function UpdatePaymentMethod( { plan, onDone, onError } ) {
    const [ mode, setMode ]       = useState( '' );
    const [ redirect, setRedirect ] = useState( '' );
    const [ label, setLabel ]     = useState( '' );
    const [ ready, setReady ]     = useState( false );
    const [ saving, setSaving ]   = useState( false );
    const mountRef    = useRef( null );
    const stripeRef   = useRef( null );
    const elementsRef = useRef( null );
    const pubKeyRef   = useRef( '' );

    useEffect( () => {
        let cancelled = false;

        api( `recurring/${ plan.id }/payment-method`, { method: 'POST' } )
            .then( ( res ) => {
                if ( cancelled ) return;
                setMode( res.mode );
                if ( res.mode === 'redirect' ) {
                    setRedirect( res.redirect_url || '' );
                    setLabel( res.gateway_label || '' );
                    return;
                }
                pubKeyRef.current = res.publishable_key || '';
                return loadStripeJs().then( ( Stripe ) => {
                    if ( cancelled ) return;
                    const stripe = Stripe( res.publishable_key );
                    stripeRef.current = stripe;
                    const elements = stripe.elements( {
                        clientSecret: res.client_secret,
                        appearance: { theme: 'stripe' },
                    } );
                    elementsRef.current = elements;
                    const el = elements.create( 'payment', { layout: 'tabs' } );
                    el.on( 'ready', () => { if ( ! cancelled ) setReady( true ); } );
                    el.mount( mountRef.current );
                } );
            } )
            .catch( ( e ) => { if ( ! cancelled ) onError( e.message || __( 'Something went wrong.', 'fundraising-toolkit' ) ); } );

        return () => { cancelled = true; };
    }, [ plan.id ] );

    const save = async () => {
        const stripe = stripeRef.current;
        const elements = elementsRef.current;
        if ( ! stripe || ! elements || saving ) return;

        setSaving( true );
        onError( '' );

        // if_required keeps the donor here for a card that needs no challenge;
        // one that does goes to its bank and returns to this same page, where
        // this component no longer exists to finish the job.
        stashCardReturn( plan.id, pubKeyRef.current );

        const { error, setupIntent } = await stripe.confirmSetup( {
            elements,
            confirmParams: { return_url: window.location.href },
            redirect: 'if_required',
        } );

        clearCardReturn();

        if ( error ) {
            onError( error.message || __( 'That card could not be saved.', 'fundraising-toolkit' ) );
            setSaving( false );
            return;
        }

        const token = setupIntent && setupIntent.payment_method;
        if ( ! token ) {
            onError( __( 'That card could not be saved.', 'fundraising-toolkit' ) );
            setSaving( false );
            return;
        }

        api( `recurring/${ plan.id }/payment-method/complete`, {
            method: 'POST',
            body: JSON.stringify( { token } ),
        } )
            .then( onDone )
            .catch( ( e ) => { onError( e.message || __( 'That card could not be saved.', 'fundraising-toolkit' ) ); setSaving( false ); } );
    };

    if ( mode === 'redirect' ) {
        return (
            <>
                <h3>{ __( 'Update payment method', 'fundraising-toolkit' ) }</h3>
                <p>
                    { sprintf(
                        /* translators: %s: the payment provider's name, e.g. PayPal. */
                        __( '%s handles this on their own site. You will be taken there to choose how you pay, and your donation carries on unchanged.', 'fundraising-toolkit' ),
                        label || __( 'Your payment provider', 'fundraising-toolkit' )
                    ) }
                </p>
                <a class="dp-action" href={ redirect } rel="noopener">
                    { label
                        ? sprintf(
                            /* translators: %s: the payment provider's name, e.g. PayPal. */
                            __( 'Continue to %s', 'fundraising-toolkit' ),
                            label
                        )
                        : __( 'Continue', 'fundraising-toolkit' ) }
                </a>
            </>
        );
    }

    return (
        <>
            <h3>{ __( 'Update payment method', 'fundraising-toolkit' ) }</h3>
            <p>{ __( 'Enter the card you would like future donations charged to.', 'fundraising-toolkit' ) }</p>
            <div ref={ mountRef } />
            { ! ready && <p class="dp-hint">{ __( 'Loading secure card form…', 'fundraising-toolkit' ) }</p> }
            <button class="dp-action" disabled={ ! ready || saving } onClick={ save }>
                { saving ? __( 'Saving…', 'fundraising-toolkit' ) : __( 'Save card', 'fundraising-toolkit' ) }
            </button>
        </>
    );
}

function ChangeAmountForm( { plan, onSubmit, busy } ) {
    const [ value, setValue ] = useState( plan.amount_cents / 100 );

    const floor = 50;
    const cents = Math.round( Number( value ) * 100 );
    const valid = Number.isFinite( cents ) && cents >= floor;

    return (
        <>
            <h3>{ __( 'Change amount', 'fundraising-toolkit' ) }</h3>
            <p class="dp-hint">{ __( 'Current:', 'fundraising-toolkit' ) } { formatAmount( plan.amount_cents, plan.currency ) }</p>
            { /* No min: AmountInput clamps every keystroke to it, so clearing
                 the box emitted the minimum and left Save live on an amount the
                 donor never typed. */ }
            <AmountInput
                value={ value }
                onChange={ setValue }
                currency={ plan.currency }
                inputProps={ { 'aria-label': __( 'New donation amount', 'fundraising-toolkit' ) } }
            />
            <p class="dp-hint">
                { sprintf(
                    /* translators: %s: the smallest amount this donation can be changed to. */
                    __( 'The smallest amount is %s.', 'fundraising-toolkit' ),
                    formatAmount( floor, plan.currency )
                ) }
            </p>
            <button class="dp-action is-primary" disabled={ busy || ! valid } onClick={ () => valid && onSubmit( cents ) }>{ __( 'Save new amount', 'fundraising-toolkit' ) }</button>
        </>
    );
}

function ChangeFrequencyForm( { plan, onSubmit, busy } ) {
    const options = plan.frequency_options || [];
    // A plan can be on a cadence this product has no name for, and preselecting
    // the first named one would offer a donor on a two-monthly plan a live Save
    // button sitting on Every week.
    const current = plan.frequency || '';
    const [ value, setValue ] = useState( current );
    const perYear = FREQUENCY_PER_YEAR[ value ];

    return (
        <>
            <h3>{ __( 'Change frequency', 'fundraising-toolkit' ) }</h3>
            <p class="dp-hint">{ __( 'Current:', 'fundraising-toolkit' ) } { intervalLabel( plan.interval_count, plan.interval_unit ) }</p>
            <label class="dp-modal__field">
                <span>{ __( 'How often', 'fundraising-toolkit' ) }</span>
                <select value={ value } onChange={ ( e ) => setValue( e.target.value ) } aria-label={ __( 'How often to donate', 'fundraising-toolkit' ) }>
                    { current === '' && (
                        <option value="">{ __( 'Choose a frequency', 'fundraising-toolkit' ) }</option>
                    ) }
                    { options.map( ( f ) => (
                        <option key={ f } value={ f }>{ frequencyLabel( f ) }</option>
                    ) ) }
                </select>
            </label>
            { /* A donor moving from monthly to weekly is agreeing to give four
                 times as much, and the cadence label alone does not say so. */ }
            { perYear && (
                <p class="dp-hint">
                    { sprintf(
                        /* translators: %s: formatted amount, e.g. $120.00 */
                        __( 'That comes to %s a year.', 'fundraising-toolkit' ),
                        formatAmount( plan.amount_cents * perYear, plan.currency )
                    ) }
                </p>
            ) }
            <p class="dp-hint">{ __( 'You stay paid up to your current date. The new schedule starts from the charge after that.', 'fundraising-toolkit' ) }</p>
            <button class="dp-action is-primary" disabled={ busy || ! value || value === current } onClick={ () => onSubmit( value ) }>
                { __( 'Save new frequency', 'fundraising-toolkit' ) }
            </button>
        </>
    );
}

function CancelDeflection( { onPause, onSkip, onReduce, onCancel, busy } ) {
    const [ confirmed, setConfirmed ] = useState( false );
    const [ reason, setReason ] = useState( '' );

    if ( confirmed ) {
        return (
            <>
                <h3>{ __( 'Cancel subscription?', 'fundraising-toolkit' ) }</h3>
                <p>{ __( "You'll keep all donations you've made so far. The recurring schedule will stop after today.", 'fundraising-toolkit' ) }</p>
                <textarea
                    placeholder={ __( 'Tell us why (optional, helps the org)', 'fundraising-toolkit' ) }
                    rows={ 3 }
                    value={ reason }
                    onInput={ ( e ) => setReason( e.target.value ) }
                />
                <button class="dp-action dp-action--danger" disabled={ busy } onClick={ () => onCancel( reason ) }>{ __( 'Cancel subscription', 'fundraising-toolkit' ) }</button>
            </>
        );
    }

    return (
        <>
            <h3>{ __( 'Before you cancel…', 'fundraising-toolkit' ) }</h3>
            <p class="dp-hint">{ __( 'A few alternatives that might work better:', 'fundraising-toolkit' ) }</p>
            { /* Offered only where the rail can actually do it. A donor trying
                 NOT to cancel was handed two buttons that both failed, and then
                 cancelled: the deflection sheet was doing the opposite of its
                 job. */ }
            { onPause && (
                <button class="dp-action" onClick={ onPause }>{ __( 'Pause for 1-12 months', 'fundraising-toolkit' ) }</button>
            ) }
            { onSkip && (
                <button class="dp-action" onClick={ onSkip }>{ __( 'Skip just the next charge', 'fundraising-toolkit' ) }</button>
            ) }
            <button class="dp-action" onClick={ onReduce }>{ __( 'Lower the amount', 'fundraising-toolkit' ) }</button>
            <button class="dp-action dp-action--danger" onClick={ () => setConfirmed( true ) }>{ __( 'Continue to cancel', 'fundraising-toolkit' ) }</button>
        </>
    );
}

// The download link is minted with rest_url(), which on an install that serves
// this page on both the apex and www names the other one of the pair. Fetching
// it there is cross-origin; the base the portal already talks to is not.
function onPortalOrigin( url ) {
    try {
        const target = new URL( url, window.location.href );
        const base   = new URL( cfg.rest, window.location.href );
        target.protocol = base.protocol;
        target.host     = base.host;
        return target.toString();
    } catch ( e ) {
        return url;
    }
}

// A synthesized anchor, not window.open: a popup a gesture did not open is
// blocked outright on iOS Safari, and blocked silently.
function saveBlob( blob, filename ) {
    const url = URL.createObjectURL( blob );
    const a   = document.createElement( 'a' );
    a.href     = url;
    a.download = filename;
    // Append before click and defer the revoke, or the browser cancels the
    // download mid-flight.
    document.body.appendChild( a );
    a.click();
    a.remove();
    setTimeout( () => URL.revokeObjectURL( url ), 10000 );
}

function Receipts() {
    const [ page, setPage ]   = useState( null );
    const [ error, setError ] = useState( null );
    const [ year, setYear ]   = useState( new Date().getFullYear() );
    const [ years, setYears ] = useState( [ new Date().getFullYear() ] );
    const [ dlError, setDlError ] = useState( '' );
    // Kept apart from the statement's error, the way the profile picture keeps
    // its own: the receipts list is a scroll below the statement card, and a
    // donor who taps Download there is not looking at the top of the page.
    const [ rowError, setRowError ] = useState( { id: 0, message: '' } );

    useEffect( () => {
        api( 'receipts' ).then( setPage ).catch( ( e ) => setError( e.message ) );
        // Clamp year picker to the donor's actual donation history so they
        // can't pick a year that returns an empty PDF.
        api( 'me' )
            .then( ( me ) => {
                const now   = new Date().getFullYear();
                const first = me?.first_donation_at
                    ? parseTimestamp( me.first_donation_at ).getFullYear()
                    : now;
                const safe  = Math.min( now, Math.max( now - 9, first || now ) );
                const span  = Math.max( 1, now - safe + 1 );
                setYears( Array.from( { length: span }, ( _, i ) => now - i ) );
            } )
            .catch( () => {} );
    }, [] );

    // The request behind this renders a whole PDF over every donation in the
    // year, which takes seconds. Without a busy state the button looked dead
    // and a second press started the render again.
    const [ dlBusy, setDlBusy ] = useState( false );

    const downloadAnnual = async () => {
        if ( dlBusy ) return;
        setDlBusy( true );
        setDlError( '' );
        try {
            saveBlob( await api( `annual-statement/${ year }` ), `fundkit-annual-${ year }.pdf` );
        } catch ( err ) {
            setDlError( err.message || __( 'Could not generate statement.', 'fundraising-toolkit' ) );
        } finally {
            setDlBusy( false );
        }
    };

    // Fetch a fresh download link at click time so it never opens expired, then
    // hand the donor the bytes. A window.open one round trip after the tap is
    // outside the user gesture, and Safari refuses it without a word.
    const downloadReceipt = async ( id, receiptNumber ) => {
        const generic = __( 'Could not open the receipt. Please try again.', 'fundraising-toolkit' );
        setRowError( { id: 0, message: '' } );
        try {
            const res = await api( `receipts/${ id }/download-url` );
            if ( ! res?.url ) throw new Error( generic );
            const doc = await fetchDocument( onPortalOrigin( res.url ), generic );
            saveBlob( doc, `receipt-${ String( receiptNumber || id ).replace( /[^A-Za-z0-9_-]/g, '' ) }.pdf` );
        } catch ( err ) {
            setRowError( { id, message: err.message || generic } );
        }
    };

    // The route caps what it returns, so the count is what the donor has and
    // the length is what they are looking at. Derived rather than stored, so a
    // request still in flight cannot read as an empty history.
    const list  = Array.isArray( page?.items ) ? page.items : [];
    const total = Number( page?.total ?? list.length );

    return (
        <>
            <div class="dp-card">
                <h3>{ __( 'Annual statement', 'fundraising-toolkit' ) }</h3>
                <p class="dp-hint">{ __( 'One consolidated PDF covering all your donations in a given year.', 'fundraising-toolkit' ) }</p>
                <div class="dp-card__row">
                    <select value={ year } aria-label={ __( 'Statement year', 'fundraising-toolkit' ) } onChange={ ( e ) => setYear( e.target.value ) }>
                        { years.map( ( y ) => (
                            <option key={ y } value={ y }>{ y }</option>
                        ) ) }
                    </select>
                    <button class="dp-action is-primary" onClick={ downloadAnnual } disabled={ dlBusy } aria-busy={ dlBusy }>
                        { dlBusy ? __( 'Preparing…', 'fundraising-toolkit' ) : __( 'Download statement', 'fundraising-toolkit' ) }
                    </button>
                </div>
                { dlError && <p class="dp-error">{ dlError }</p> }
            </div>

            <h3>{ __( 'Individual receipts', 'fundraising-toolkit' ) }</h3>
            { error    && <p class="dp-error">{ error }</p> }
            { ! page   && <p>{ __( 'Loading…', 'fundraising-toolkit' ) }</p> }
            { page && list.length === 0 && <p>{ __( 'No receipts yet.', 'fundraising-toolkit' ) }</p> }
            { page && list.length > 0 && (
                <>
                { total > list.length && (
                    <p class="dp-list__note">
                        { sprintf(
                            /* translators: 1: how many receipts are listed, 2: how many the donor has in total. */
                            __( 'Showing your %1$s most recent receipts of %2$s. The annual statement covers a whole year.', 'fundraising-toolkit' ),
                            list.length.toLocaleString(),
                            total.toLocaleString()
                        ) }
                    </p>
                ) }
                <ul class="dp-list">
                    { list.map( ( r ) => (
                        <li key={ r.id } class="dp-list__row">
                            <div>
                                <strong>{ r.receipt_number }</strong>
                                <div class="dp-list__sub">{ formatDate( r.issued_at ) }</div>
                                { rowError.id === r.id && rowError.message && (
                                    <p class="dp-error dp-list__error" role="alert">{ rowError.message }</p>
                                ) }
                            </div>
                            <button type="button" class="dp-link" onClick={ () => downloadReceipt( r.id, r.receipt_number ) }>{ __( 'Download', 'fundraising-toolkit' ) }</button>
                        </li>
                    ) ) }
                </ul>
                </>
            ) }
        </>
    );
}

function Profile( { me, onSaved } ) {
    const [ form, setForm ]   = useState( null );
    const [ saving, setSaving ] = useState( false );
    const [ saved,  setSaved  ] = useState( false );
    const [ err,    setErr    ] = useState( '' );
    // Kept apart from the form's own state: the picture saves on its own, and
    // its error belongs beside the picture rather than down by the Save button.
    const [ uploading, setUploading ] = useState( false );
    const [ picErr,    setPicErr    ] = useState( '' );

    useEffect( () => { api( 'profile' ).then( ( v ) => setForm( withDefaults( v ) ) ).catch( ( e ) => setErr( e.message || __( 'Could not load your profile.', 'fundraising-toolkit' ) ) ); }, [] );

    if ( ! form ) return <p>{ err || __( 'Loading…', 'fundraising-toolkit' ) }</p>;

    const set = ( k ) => ( e ) => setForm( { ...form, [ k ]: e.target.value } );

    const save = () => {
        setSaving( true );
        setSaved( false );
        setErr( '' );
        const { email, ...editable } = form;
        api( 'profile', { method: 'POST', body: JSON.stringify( editable ) } )
            .then( ( next ) => {
                setForm( withDefaults( next ) );
                setSaved( true );
                onSaved && onSaved();
                setTimeout( () => setSaved( false ), 2500 );
            } )
            .catch( ( e ) => setErr( e.message || __( 'Could not save.', 'fundraising-toolkit' ) ) )
            .finally( () => setSaving( false ) );
    };

    const pickPicture = ( e ) => {
        const file = e.target.files && e.target.files[ 0 ];
        e.target.value = '';
        if ( ! file ) return;

        // Refused here rather than sent and refused: past post_max_size the
        // server never sees the request as an upload at all, so the donor
        // would wait for a round trip that could only end in confusion.
        const max = Number( cfg.avatarMaxBytes || 0 );
        if ( max > 0 && file.size > max ) {
            setPicErr( sprintf(
                /* translators: %s: file size, e.g. "2 MB". */
                __( 'That picture is too large. The most this site takes is %s.', 'fundraising-toolkit' ),
                cfg.avatarMaxLabel || `${ Math.floor( max / 1048576 ) } MB`
            ) );
            return;
        }

        const body = new FormData();
        body.append( 'file', file );
        setUploading( true );
        setPicErr( '' );
        api( 'avatar', { method: 'POST', body } )
            .then( ( next ) => {
                setForm( withDefaults( next ) );
                onSaved && onSaved();
            } )
            .catch( ( e2 ) => setPicErr( e2.message || __( 'Could not upload that picture.', 'fundraising-toolkit' ) ) )
            .finally( () => setUploading( false ) );
    };

    const removePicture = () => {
        setUploading( true );
        setPicErr( '' );
        api( 'avatar', { method: 'DELETE' } )
            .then( ( next ) => {
                setForm( withDefaults( next ) );
                onSaved && onSaved();
            } )
            .catch( ( e2 ) => setPicErr( e2.message || __( 'Could not remove that picture.', 'fundraising-toolkit' ) ) )
            .finally( () => setUploading( false ) );
    };

    return (
        <div class="dp-form">
            <div class="dp-avatar-field">
                <span class={ `dp-avatar-field__frame${ uploading ? ' is-uploading' : '' }` }>
                    { form.avatar_url
                        ? <img class="dp-avatar-field__preview" src={ form.avatar_url } alt="" />
                        : <span class="dp-avatar-field__preview dp-avatar-field__preview--empty" aria-hidden="true" /> }
                    { uploading && <span class="dp-avatar-field__spinner" aria-hidden="true" /> }
                </span>
                <div class="dp-avatar-field__controls">
                    <span class="dp-avatar-field__label">{ __( 'Profile picture', 'fundraising-toolkit' ) }</span>
                    <small>
                        { sprintf(
                            /* translators: %s: file size, e.g. "2 MB". */
                            __( 'Shown next to your name where the organization lists supporters. JPEG, PNG, GIF or WebP, up to %s.', 'fundraising-toolkit' ),
                            cfg.avatarMaxLabel || __( '2 MB', 'fundraising-toolkit' )
                        ) }
                    </small>
                    <div class="dp-avatar-field__buttons">
                        <label class={ `dp-btn dp-btn--ghost${ uploading ? ' is-disabled' : '' }` }>
                            { uploading
                                ? __( 'Uploading…', 'fundraising-toolkit' )
                                : form.avatar_url ? __( 'Replace', 'fundraising-toolkit' ) : __( 'Upload', 'fundraising-toolkit' ) }
                            <input
                                type="file"
                                accept="image/jpeg,image/png,image/gif,image/webp"
                                onChange={ pickPicture }
                                disabled={ uploading }
                                hidden
                            />
                        </label>
                        { form.avatar_url && ! uploading && (
                            <button type="button" class="dp-btn dp-btn--ghost" onClick={ removePicture }>
                                { __( 'Remove', 'fundraising-toolkit' ) }
                            </button>
                        ) }
                    </div>
                    { picErr && <span class="dp-error dp-avatar-field__error" role="alert">{ picErr }</span> }
                </div>
            </div>
            <label>{ __( 'Email', 'fundraising-toolkit' ) }
                <input type="email" value={ form.email } disabled readOnly />
                <small>{ __( 'To change your email, contact the organization.', 'fundraising-toolkit' ) }</small>
            </label>
            <div class="dp-form__row">
                <label>{ __( 'First name', 'fundraising-toolkit' ) } <input type="text" value={ form.first_name } onInput={ set( 'first_name' ) } /></label>
                <label>{ __( 'Last name', 'fundraising-toolkit' ) }  <input type="text" value={ form.last_name }  onInput={ set( 'last_name' ) } /></label>
            </div>
            <label>{ __( 'Phone', 'fundraising-toolkit' ) }   <input type="tel" autocomplete="tel" value={ form.phone } onInput={ set( 'phone' ) } /></label>
            <CountryPicker value={ form.country } onChange={ ( code ) => setForm( { ...form, country: code } ) } />
            <label>{ __( 'Company', 'fundraising-toolkit' ) } <input type="text" value={ form.company } onInput={ set( 'company' ) } /></label>
            <div class="dp-form__actions">
                <button class="dp-action is-primary" disabled={ saving } onClick={ save }>
                    { saving ? __( 'Saving…', 'fundraising-toolkit' ) : __( 'Save', 'fundraising-toolkit' ) }
                </button>
                { saved && <span class="dp-form__saved">{ __( 'Saved.', 'fundraising-toolkit' ) }</span> }
                { err && <span class="dp-error">{ err }</span> }
            </div>
            <PrivacyActions me={ me } />
        </div>
    );
}

function PrivacyActions( { me } ) {
    const [ exporting, setExporting ] = useState( false );
    const [ deleting, setDeleting ]   = useState( false );
    const [ confirmOpen, setConfirmOpen ] = useState( false );
    const [ error, setError ]         = useState( null );
    // Both routes refuse when the org has turned them off, so this only decides
    // whether the donor is offered something that would be refused. Read off
    // the donor the app already holds: a second fetch of the same thing turns a
    // transient failure into a screen with no right-of-access controls on it
    // and nothing saying why.
    const allowed = {
        export: me?.allow_data_export !== false,
        remove: me?.allow_account_delete !== false,
    };

    const downloadData = async () => {
        setExporting( true );
        setError( null );
        try {
            // Direct fetch (not the api() helper) so the attachment streams as
            // a binary download instead of being JSON.parsed in the helper.
            const send = ( nonce ) => {
                const headers = {
                    'Content-Type': 'application/json',
                    ...( nonce ? { 'X-WP-Nonce': nonce } : {} ),
                };
                if ( csrfToken ) headers[ 'X-FundKit-Csrf' ] = csrfToken;

                return fetch( `${ cfg.rest }data-export`, {
                    method:      'POST',
                    credentials: 'same-origin',
                    headers,
                } );
            };

            let r = await send( cfg.nonce );
            if ( ! r.ok ) {
                const why = await refusal( r, __( 'Export failed.', 'fundraising-toolkit' ) );
                if ( nonceWasRefused( r, why ) ) {
                    r = await send( '' );
                    if ( r.ok ) cfg.nonce = '';
                } else {
                    throw why;
                }
            }
            if ( ! r.ok ) {
                throw await refusal( r, __( 'Export failed.', 'fundraising-toolkit' ) );
            }
            saveBlob( await r.blob(), 'my-data.json' );
        } catch ( e ) {
            setError( e.message || __( 'Export failed.', 'fundraising-toolkit' ) );
        } finally {
            setExporting( false );
        }
    };

    const forget = async () => {
        setDeleting( true );
        setError( null );
        try {
            await api( 'forget', { method: 'POST', body: JSON.stringify( { confirm: 'DELETE' } ) } );
            window.location.reload();
        } catch ( e ) {
            setError( e.message || __( 'Deletion failed.', 'fundraising-toolkit' ) );
            setDeleting( false );
        }
    };

    if ( ! allowed.export && ! allowed.remove ) return null;

    const note = allowed.export && allowed.remove
        ? __( "Download returns a JSON copy of everything we hold on you. Deletion anonymizes your record; donation totals stay for the organization's tax records.", 'fundraising-toolkit' )
        : allowed.export
            ? __( 'Download returns a JSON copy of everything we hold on you.', 'fundraising-toolkit' )
            : __( "Deletion anonymizes your record; donation totals stay for the organization's tax records.", 'fundraising-toolkit' );

    return (
        <div class="dp-privacy">
            <h4>{ __( 'Your data', 'fundraising-toolkit' ) }</h4>
            { error && <p class="dp-error">{ error }</p> }
            <div class="dp-privacy__actions">
                { allowed.export && (
                    <button class="dp-action" disabled={ exporting } onClick={ downloadData }>
                        { exporting ? __( 'Preparing…', 'fundraising-toolkit' ) : __( 'Download my data', 'fundraising-toolkit' ) }
                    </button>
                ) }
                { allowed.remove && (
                    <button class="dp-action is-destructive" disabled={ deleting } onClick={ () => { setError( null ); setConfirmOpen( true ); } }>
                        { deleting ? __( 'Deleting…', 'fundraising-toolkit' ) : __( 'Delete my account', 'fundraising-toolkit' ) }
                    </button>
                ) }
            </div>
            <p class="dp-privacy__note">{ note }</p>
            { confirmOpen && (
                <DeleteAccountModal
                    deleting={ deleting }
                    error={ error }
                    onConfirm={ forget }
                    onClose={ () => setConfirmOpen( false ) }
                />
            ) }
        </div>
    );
}

function DeleteAccountModal( { deleting, error, onConfirm, onClose } ) {
    const [ typed, setTyped ] = useState( '' );
    const panelRef = useRef( null );
    const inputRef = useRef( null );
    useFocusTrap( panelRef, true, onClose );
    // The trap's initial focus lands on the close button; move it to the
    // confirmation input so the donor can type straight away.
    useEffect( () => { if ( inputRef.current ) inputRef.current.focus(); }, [] );

    const matches = typed === 'DELETE';

    return (
        // eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions, jsx-a11y/click-events-have-key-events -- click-outside-to-close is a mouse convenience; Escape (focus trap) and the close button provide keyboard dismissal
        <div class="dp-modal" role="dialog" aria-modal="true" aria-label={ __( 'Delete my account', 'fundraising-toolkit' ) } onClick={ ( e ) => { if ( e.target === e.currentTarget ) onClose(); } } ref={ panelRef }>
            <div class="dp-modal__panel">
                <button class="dp-modal__close" onClick={ onClose } aria-label={ __( 'Close', 'fundraising-toolkit' ) }>×</button>
                <h3>{ __( 'Delete my account', 'fundraising-toolkit' ) }</h3>
                { error && <p class="dp-error">{ error }</p> }
                <p>{ __( 'Permanently anonymize your account? Past donations stay attached for tax/audit but every other detail is wiped. This cannot be undone.', 'fundraising-toolkit' ) }</p>
                <div class="dp-form">
                    <label>
                        { sprintf( /* translators: %s: the literal confirmation keyword to type (DELETE) */ __( 'Type %s to confirm.', 'fundraising-toolkit' ), 'DELETE' ) }
                        <input
                            ref={ inputRef }
                            type="text"
                            value={ typed }
                            autofocus
                            autocomplete="off"
                            spellcheck="false"
                            onInput={ ( e ) => setTyped( e.target.value ) }
                        />
                    </label>
                </div>
                <button class="dp-action dp-action--danger" disabled={ deleting || ! matches } onClick={ onConfirm }>
                    { deleting ? __( 'Deleting…', 'fundraising-toolkit' ) : __( 'Delete my account', 'fundraising-toolkit' ) }
                </button>
                <button class="dp-action" disabled={ deleting } onClick={ onClose }>{ __( 'Cancel', 'fundraising-toolkit' ) }</button>
            </div>
        </div>
    );
}

function withDefaults( v ) {
    return {
        email:      '',
        phone:      '',
        first_name: '',
        last_name:  '',
        country:    '',
        company:    '',
        avatar_url: '',
        ...( v || {} ),
    };
}

let countryPickerSeq = 0;

function CountryPicker( { value, onChange } ) {
    // The donor's own language, sorted their way, with the English name kept so
    // a search matches either. A picker that only reads English is one a donor
    // cannot find their own country in, on a field the account form requires.
    const countries = useMemo( () => localizedCountries(), [] );
    const byCode    = ( code ) => countries.find( ( c ) => c.code === String( code || '' ).toUpperCase() );

    const [ query, setQuery ] = useState( () => byCode( value )?.label || '' );
    const [ open, setOpen ]   = useState( false );
    const [ active, setActive ] = useState( 0 );
    const [ id ] = useState( () => `dp-country-${ ++countryPickerSeq }` );

    useEffect( () => {
        const c = byCode( value );
        setQuery( c ? c.label : ( value || '' ) );
    }, [ value ] );

    const q       = query.trim().toLowerCase();
    const matches = q === ''
        ? countries
        : countries.filter( ( c ) =>
            c.label.toLowerCase().includes( q )
            || c.name.toLowerCase().includes( q )
            || c.code.toLowerCase().startsWith( q ) );
    const visible = matches.slice( 0, 50 );

    useEffect( () => { setActive( 0 ); }, [ query ] );

    const pick = ( c ) => {
        onChange( c.code );
        setQuery( c.label );
        setOpen( false );
    };

    // Mouse was the only way in: the options were buttons activated on
    // mousedown, so a donor on a keyboard could type a search and never choose
    // anything, on a field the account form requires.
    const onKeyDown = ( e ) => {
        if ( e.key === 'ArrowDown' || e.key === 'ArrowUp' ) {
            e.preventDefault();
            if ( ! open ) { setOpen( true ); return; }
            setActive( ( i ) => e.key === 'ArrowDown'
                ? Math.min( visible.length - 1, i + 1 )
                : Math.max( 0, i - 1 ) );
        } else if ( e.key === 'Enter' ) {
            if ( ! open || ! visible[ active ] ) return;
            e.preventDefault();
            pick( visible[ active ] );
        } else if ( e.key === 'Escape' ) {
            setOpen( false );
        }
    };

    return (
        <label class="dp-country" for={ id }>
            { __( 'Country', 'fundraising-toolkit' ) }
            <div class="dp-country__wrap">
                <input
                    id={ id }
                    type="text"
                    role="combobox"
                    aria-expanded={ open }
                    aria-autocomplete="list"
                    aria-controls={ open ? `${ id }-list` : undefined }
                    aria-activedescendant={ open && visible[ active ] ? `${ id }-opt-${ visible[ active ].code }` : undefined }
                    value={ query }
                    placeholder={ __( 'Search country…', 'fundraising-toolkit' ) }
                    onFocus={ () => setOpen( true ) }
                    onBlur={ () => setTimeout( () => {
                        setOpen( false );
                        // Free text nobody picked is not a country. Left as
                        // typed it sat next to "Saved." showing one the donor
                        // never chose and the account never stored.
                        const chosen = byCode( value );
                        setQuery( chosen ? chosen.label : '' );
                    }, 150 ) }
                    onInput={ ( e ) => { setQuery( e.target.value ); setOpen( true ); } }
                    onKeyDown={ onKeyDown }
                />
                { open && visible.length > 0 && (
                    <ul id={ `${ id }-list` } class="dp-country__list" role="listbox">
                        { visible.map( ( c, i ) => (
                            <li
                                key={ c.code }
                                id={ `${ id }-opt-${ c.code }` }
                                role="option"
                                aria-selected={ i === active }
                                class={ i === active ? 'is-active' : undefined }
                            >
                                <button type="button" tabIndex={ -1 } onMouseEnter={ () => setActive( i ) } onMouseDown={ ( e ) => { e.preventDefault(); pick( c ); } }>
                                    <span>{ c.label }</span>
                                    <span class="dp-country__code">{ c.code }</span>
                                </button>
                            </li>
                        ) ) }
                    </ul>
                ) }
            </div>
        </label>
    );
}

function Consents( { onResolved } ) {
    const [ list, setList ] = useState( null );
    const extSections = useExtensionPanels( 'portal-consents' );
    const [ saving, setSaving ] = useState( false );
    const [ savedAt, setSavedAt ] = useState( null );
    const [ err, setErr ] = useState( '' );

    const load = useCallback( () => {
        setErr( '' );

        return api( 'consents' ).then( setList ).catch( ( e ) => setErr( e.message || __( 'Could not load your consents.', 'fundraising-toolkit' ) ) );
    }, [] );
    useEffect( () => { load(); }, [ load ] );
    useEffect( () => {
        if ( ! savedAt ) return undefined;
        const t = setTimeout( () => setSavedAt( null ), 2500 );
        return () => clearTimeout( t );
    }, [ savedAt ] );

    // Add-on sections are not about consent purposes, so an organization that
    // has defined none must still see them.
    const sections = extSections.map( ( panel ) => (
        <ExtensionSection key={ panel.id } panel={ panel } context={ { api } } className="dp-ext-section" />
    ) );

    if ( ! list ) return err
        ? <LoadFailure message={ err } onRetry={ load } />
        : <p>{ __( 'Loading…', 'fundraising-toolkit' ) }</p>;
    if ( ! list.length ) return (
        <div class="dp-consents">
            <div class="dp-empty">
                <p>{ __( 'No consent purposes are defined yet.', 'fundraising-toolkit' ) }</p>
                <p class="dp-hint">{ __( 'The organization has not configured any subscriptions or consents.', 'fundraising-toolkit' ) }</p>
            </div>
            { sections }
        </div>
    );

    // One save at a time. Each response replaces the whole list, so two in
    // flight could settle on the older answer and leave the boxes disagreeing
    // with the record behind them.
    const send = ( items ) => {
        if ( saving ) return;
        setSaving( true );
        setErr( '' );

        return api( 'consents', { method: 'POST', body: JSON.stringify( { items } ) } )
            .then( ( fresh ) => {
                setList( fresh );
                setSavedAt( Date.now() );
                // What the tab dot and the banner on every other tab read.
                if ( typeof onResolved === 'function' ) {
                    onResolved( fresh.filter( ( p ) => p.stale ).length );
                }
            } )
            .catch( ( e ) => { setErr( e.message || __( 'Could not save your choice.', 'fundraising-toolkit' ) ); load(); } )
            .finally( () => setSaving( false ) );
    };

    const toggle = ( key, next ) => {
        if ( saving ) return;
        setList( ( cur ) => cur.map( ( p ) => p.key === key ? { ...p, granted: next } : p ) );
        send( [ { key, granted: next } ] );
    };

    const confirmStale = ( key ) => {
        // Accepting the new version without flipping the toggle: re-sending the
        // current `granted` state makes the server record a fresh row at the
        // new purpose_version, clearing the stale flag.
        const cur = list.find( ( p ) => p.key === key );
        if ( ! cur ) return;
        send( [ { key, granted: !! cur.granted } ] );
    };

    const staleCount = list.filter( ( p ) => p.stale ).length;

    return (
        <div class="dp-consents">
            { err && <p class="dp-error">{ err }</p> }
            { staleCount > 0 && (
                <div class="dp-consents__notice" role="status">
                    <strong>{ sprintf( /* translators: %d: number of consent items that were updated */ _n( '%d updated.', '%d updated.', staleCount, 'fundraising-toolkit' ), staleCount ) }</strong>{ ' ' }
                    { __( 'The items marked below have new terms since you last reviewed them. Confirm or change each one.', 'fundraising-toolkit' ) }
                </div>
            ) }
            { staleCount === 0 && (
                <p class="dp-hint">{ __( 'Toggle each subscription below. Every change is logged for your records.', 'fundraising-toolkit' ) }</p>
            ) }
            { list.map( ( p ) => (
                <label
                    key={ p.key }
                    class={ `dp-consent${ p.required ? ' is-required' : '' }${ p.stale ? ' is-stale' : '' }` }
                >
                    <input
                        type="checkbox"
                        checked={ p.granted }
                        disabled={ p.required || saving }
                        onChange={ ( e ) => toggle( p.key, e.target.checked ) }
                    />
                    <div>
                        <strong>{ p.label }</strong>
                        { p.required && <span class="dp-consent__required">{ __( 'required', 'fundraising-toolkit' ) }</span> }
                        { p.stale && <span class="dp-consent__stale">{ __( 'Updated', 'fundraising-toolkit' ) }</span> }
                        { p.description && <p class="dp-consent__desc">{ p.description }</p> }
                        { p.has_record && p.occurred_at && (
                            <p class="dp-consent__meta">{ sprintf( /* translators: %s: date the consent was last confirmed */ __( 'Last confirmed %s', 'fundraising-toolkit' ), formatDate( p.occurred_at ) ) }</p>
                        ) }
                        { p.stale && (
                            <button
                                type="button"
                                class="dp-consent__confirm"
                                onClick={ () => confirmStale( p.key ) }
                            >
                                { __( 'Keep as is', 'fundraising-toolkit' ) }
                            </button>
                        ) }
                    </div>
                </label>
            ) ) }
            { saving && <p class="dp-consent__saving">{ __( 'Saving…', 'fundraising-toolkit' ) }</p> }
            { ! saving && savedAt && <p class="dp-consent__saving dp-form__saved" role="status">{ __( 'Saved.', 'fundraising-toolkit' ) }</p> }
            { sections }
        </div>
    );
}

function Preferences() {
    const [ p, setP ] = useState( null );
    const [ saving, setSaving ] = useState( false );
    const [ saved, setSaved ] = useState( false );
    const [ err, setErr ] = useState( '' );

    const load = useCallback( () => {
        setErr( '' );
        api( 'preferences' ).then( setP ).catch( ( e ) => setErr( e.message || __( 'Could not load your preferences.', 'fundraising-toolkit' ) ) );
    }, [] );

    useEffect( () => { load(); }, [ load ] );
    useEffect( () => {
        if ( ! saved ) return undefined;
        const t = setTimeout( () => setSaved( false ), 2500 );
        return () => clearTimeout( t );
    }, [ saved ] );

    if ( ! p ) return err
        ? <LoadFailure message={ err } onRetry={ load } />
        : <p>{ __( 'Loading…', 'fundraising-toolkit' ) }</p>;

    const save = () => {
        setSaving( true );
        setErr( '' );
        setSaved( false );
        api( 'preferences', { method: 'POST', body: JSON.stringify( p ) } )
            .then( ( fresh ) => { setP( fresh ); setSaved( true ); } )
            .catch( ( e ) => setErr( e.message || __( 'Could not save.', 'fundraising-toolkit' ) ) )
            .finally( () => setSaving( false ) );
    };

    return (
        <div class="dp-prefs">
            <div class="dp-prefs__col">
                <h4>{ __( 'Privacy', 'fundraising-toolkit' ) }</h4>
                <label>
                    <input type="checkbox" checked={ p.always_anonymous } onChange={ ( e ) => setP( { ...p, always_anonymous: e.target.checked } ) } />
                    <span>
                        { __( 'Hide my name from the public list of donors', 'fundraising-toolkit' ) }
                        <small class="dp-hint">{ __( 'Applies to every future donation. The organization still sees your name, and your receipts are unchanged.', 'fundraising-toolkit' ) }</small>
                    </span>
                </label>
            </div>
            <button class="dp-action is-primary" disabled={ saving } onClick={ save }>{ saving ? __( 'Saving…', 'fundraising-toolkit' ) : __( 'Save preferences', 'fundraising-toolkit' ) }</button>
            { ! saving && saved && <span class="dp-form__saved" role="status">{ __( 'Saved.', 'fundraising-toolkit' ) }</span> }
            { err && <p class="dp-error">{ err }</p> }
        </div>
    );
}

function Kpi( { label, value } ) {
    return (
        <div class="dp-kpi">
            <div class="dp-kpi__label">{ label }</div>
            <div class="dp-kpi__value">{ value }</div>
        </div>
    );
}

/**
 * The same reading the admin gives a timestamp, so a donor and the org see one
 * date for one event. parseTimestamp marks a zoneless MySQL string as UTC, and
 * leaves a date-only value alone rather than pushing it to UTC midnight.
 */
export function formatDate( iso ) {
    if ( ! iso ) return '';
    const d = parseTimestamp( iso );
    if ( Number.isNaN( d.getTime() ) ) return iso;

    return d.toLocaleString( undefined, {
        year:   'numeric',
        month:  'short',
        day:    '2-digit',
        hour:   '2-digit',
        minute: '2-digit',
    } );
}

const FREQUENCY_LABELS = {
    weekly:    __( 'Every week', 'fundraising-toolkit' ),
    biweekly:  __( 'Every 2 weeks', 'fundraising-toolkit' ),
    monthly:   __( 'Every month', 'fundraising-toolkit' ),
    quarterly: __( 'Every 3 months', 'fundraising-toolkit' ),
    yearly:    __( 'Every year', 'fundraising-toolkit' ),
};

const FREQUENCY_PER_YEAR = { weekly: 52, biweekly: 26, monthly: 12, quarterly: 4, yearly: 1 };

function frequencyLabel( frequency ) {
    return FREQUENCY_LABELS[ frequency ] || frequency;
}

function intervalLabel( count, unit ) {
    const n = Number( count ) || 1;
    const u = unit === 'year' ? _n( 'year', 'years', n, 'fundraising-toolkit' )
        : unit === 'week'     ? _n( 'week', 'weeks', n, 'fundraising-toolkit' )
        :                       _n( 'month', 'months', n, 'fundraising-toolkit' );
    /* translators: 1: count, 2: interval unit (e.g. months) */
    return sprintf( __( 'Every %1$d %2$s', 'fundraising-toolkit' ), n, u );
}

const mount = document.getElementById( 'fundkit-donor-portal' );
if ( mount ) render( <App />, mount );
