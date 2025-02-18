/** @jsxImportSource preact */

import { h, render } from 'preact';
import { useEffect, useState, useCallback, useRef } from 'preact/hooks';
import { __, _n, sprintf } from '@wordpress/i18n';
import { formatAmount } from '@dono/ui/utils/format';
import { COUNTRIES } from '../_shared/countries';
import './portal.scss';

const cfg = window.donoPortal || { rest: '/wp-json/dono/v1/portal/', nonce: '' };

const FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
function useFocusTrap( ref, active, onClose ) {
    useEffect( () => {
        if ( ! active || ! ref.current ) return;
        const el = ref.current;
        const prev = document.activeElement;
        const first = el.querySelector( FOCUSABLE );
        if ( first ) first.focus();
        const onKey = ( e ) => {
            if ( e.key === 'Escape' && typeof onClose === 'function' ) {
                e.preventDefault();
                onClose();
                return;
            }
            if ( e.key !== 'Tab' ) return;
            const nodes = [ ...el.querySelectorAll( FOCUSABLE ) ];
            if ( ! nodes.length ) return;
            if ( e.shiftKey && document.activeElement === nodes[ 0 ] ) {
                e.preventDefault();
                nodes[ nodes.length - 1 ].focus();
            } else if ( ! e.shiftKey && document.activeElement === nodes[ nodes.length - 1 ] ) {
                e.preventDefault();
                nodes[ 0 ].focus();
            }
        };
        el.addEventListener( 'keydown', onKey );
        return () => {
            el.removeEventListener( 'keydown', onKey );
            if ( prev && typeof prev.focus === 'function' ) prev.focus();
        };
    }, [ active ] );
}

// Portal session CSRF token. Lives only in memory; populated from the
// /portal/me or /portal/exchange response. State-changing endpoints reject
// the request without a matching `X-Dono-Csrf` header.
let csrfToken = '';

function setCsrfFromResponse( payload ) {
    if ( payload && typeof payload === 'object' && typeof payload.csrf === 'string' && payload.csrf ) {
        csrfToken = payload.csrf;
    }
}

// Armed by App only while signed in, so a 401/403 from any per-tab request
// (expired session cookie) bounces the whole app back to the sign-in screen
// instead of leaving one tab stuck on a raw "Request failed".
let onSessionExpired = null;

function api( path, init = {} ) {
    const headers = {
        'Content-Type': 'application/json',
        'X-WP-Nonce':   cfg.nonce,
        ...( init.headers || {} ),
    };
    if ( csrfToken ) headers[ 'X-Dono-Csrf' ] = csrfToken;

    return fetch( `${ cfg.rest }${ path }`, {
        credentials: 'same-origin',
        headers,
        ...init,
    } ).then( async ( r ) => {
        if ( ! r.ok ) {
            const data = await r.json().catch( () => ({}) );
            if ( ( r.status === 401 || r.status === 403 ) && typeof onSessionExpired === 'function' ) {
                onSessionExpired();
            }
            throw Object.assign( new Error( data.message || __( 'Request failed', 'dono' ) ), { status: r.status, data } );
        }
        const ct = r.headers.get( 'content-type' ) || '';
        if ( ct.includes( 'application/pdf' ) ) return r.blob();
        const json = await r.json();
        setCsrfFromResponse( json );
        return json;
    } );
}

// Preserve the page's intent params (e.g. ?dono_fundraise=10) across the
// magic-link round-trip through email, so a brand-new fundraiser who registers
// lands back in the create flow rather than the portal overview.
const RETURN_KEY = 'dono_portal_return';

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

// Extension-tab seam (preact side). Mirrors assets/admin/_shared/extensionTabs.jsx
// but built on preact/hooks since the portal is a standalone preact app. Add-ons
// register portal tabs into window.dono.tabs (defined by ExtensionAssets).
const TAB_EVENT = 'dono:tabs:changed';

function readExtTabs( surface ) {
    const reg = ( window.dono && window.dono.tabs ) || null;
    return reg && typeof reg.get === 'function' ? reg.get( surface ) : [];
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

function App() {
    const [ me, setMe ]         = useState( null );
    const [ loading, setLoading ] = useState( true );
    const [ error, setError ]   = useState( null );
    const [ tab, setTab ]       = useState( 'overview' );
    const [ openDonation, setOpenDonation ] = useState( null );
    const extTabs = useExtensionTabs( 'portal' );
    const initialExtTabApplied = useRef( false );

    const [ loadError, setLoadError ] = useState( null );

    const loadMe = useCallback( () => {
        setLoadError( null );
        return api( 'me' )
            .then( setMe )
            .catch( ( err ) => {
                // 401/403 means the session is genuinely gone -> show sign-in.
                // A transient failure (network blip, 5xx) must NOT bounce a
                // signed-in donor to the sign-in screen; offer a retry instead.
                if ( err && ( err.status === 401 || err.status === 403 ) ) {
                    setMe( null );
                } else {
                    setLoadError( err?.message || __( 'Could not load your account.', 'dono' ) );
                }
            } )
            .finally( () => setLoading( false ) );
    }, [] );

    // Arm the session-expired hook only while signed in, so a per-tab 401/403
    // returns the donor to sign-in with a clear message (an initial "not signed
    // in yet" 401 during the magic-link flow must not trip it).
    useEffect( () => {
        if ( ! me ) return undefined;
        onSessionExpired = () => {
            setMe( null );
            setError( __( 'Your session expired. Please sign in again.', 'dono' ) );
        };
        return () => { onSessionExpired = null; };
    }, [ me ] );

    // Let an add-on tab claim the initial view from URL params (e.g. the P2P
    // "Start fundraising" link lands on ?dono_fundraise=<id>). Runs once, after
    // sign-in, when the registry has populated.
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
        const params = new URLSearchParams( window.location.search );
        const token  = params.get( 'token' );
        if ( token ) {
            // Strip the single-use token from the URL up front so a failed
            // exchange never leaves it in the address bar, history, or Referer
            // (the server may have already consumed it).
            const cleanUrl = new URL( window.location.href );
            cleanUrl.searchParams.delete( 'token' );
            window.history.replaceState( {}, '', cleanUrl.toString() );

            api( 'exchange', { method: 'POST', body: JSON.stringify( { token } ) } )
                .then( () => {
                    // Restore the pre-login intent (e.g. ?dono_fundraise=10) so
                    // the add-on tab's initialMatch reopens the create flow.
                    const ret = popReturn();
                    if ( ret ) {
                        const url = new URL( window.location.href );
                        url.search = ret;
                        window.history.replaceState( {}, '', url.toString() );
                    }
                    return loadMe();
                } )
                .catch( ( err ) => { setError( err.message ); setLoading( false ); } );
        } else {
            loadMe();
        }
    }, [ loadMe ] );

    if ( loading ) return <div class="dp-loading">{ __( 'Loading…', 'dono' ) }</div>;
    if ( ! me && loadError ) {
        return (
            <div class="dp-loading">
                <p class="dp-signin__error">{ loadError }</p>
                <button type="button" class="dp-link" onClick={ () => { setLoading( true ); loadMe(); } }>
                    { __( 'Try again', 'dono' ) }
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
                <h1>{ sprintf( /* translators: %s: donor's first name or full name */ __( 'Hi, %s.', 'dono' ), me.first_name || me.name ) }</h1>
                <button class="dp__signout" onClick={ () => {
                    api( 'logout', { method: 'POST' } ).finally( () => window.location.reload() );
                } }>{ __( 'Sign out', 'dono' ) }</button>
            </header>

            { consentsPending > 0 && tab !== 'consents' && (
                <div class="dp-banner" role="status">
                    <div class="dp-banner__text">
                        <strong>{ __( 'Your privacy preferences need an update.', 'dono' ) }</strong>{ ' ' }
                        { __( "We've revised the terms for some of the things you previously agreed to. Take a moment to review.", 'dono' ) }
                    </div>
                    <button
                        type="button"
                        class="dp-banner__action"
                        onClick={ () => setTab( 'consents' ) }
                    >
                        { __( 'Review now', 'dono' ) }
                    </button>
                </div>
            ) }

            <nav class="dp__nav" role="tablist">
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
                            { showDot && <span class="dp__tab-dot" aria-label={ __( 'needs attention', 'dono' ) } /> }
                        </button>
                    );
                } ) }
            </nav>

            <main class="dp__main">
                { tab === 'overview'    && <Overview  me={ me } /> }
                { tab === 'donations'   && <Donations onOpen={ setOpenDonation } /> }
                { tab === 'recurring'   && <Recurring /> }
                { tab === 'receipts'    && <Receipts /> }
                { tab === 'preferences' && <Preferences /> }
                { tab === 'profile'     && <Profile  onSaved={ loadMe } /> }
                { tab === 'consents'    && <Consents /> }
                { visibleExtTabs.map( ( t ) => (
                    tab === t.id ? <ExtensionPanel key={ t.id } tab={ t } context={ extContext } /> : null
                ) ) }
            </main>

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
    { id: 'overview',    label: __( 'Overview', 'dono' ) },
    { id: 'donations',   label: __( 'Donations', 'dono' ) },
    { id: 'recurring',   label: __( 'Recurring', 'dono' ) },
    { id: 'receipts',    label: __( 'Receipts & tax', 'dono' ) },
    { id: 'preferences', label: __( 'Preferences', 'dono' ) },
    { id: 'profile',     label: __( 'Profile', 'dono' ) },
    { id: 'consents',    label: __( 'Consents', 'dono' ) },
];

function SignInPrompt( { initialError } ) {
    const [ mode, setMode ]       = useState( 'signin' ); // 'signin' | 'register'
    const [ email, setEmail ]     = useState( '' );
    const [ name, setName ]       = useState( '' );
    const [ sent, setSent ]       = useState( false );
    const [ sending, setSending ] = useState( false );
    const [ error, setError ]     = useState( initialError || null );

    const isRegister = mode === 'register';

    const submit = ( e ) => {
        e.preventDefault();
        if ( ! email || ( isRegister && ! name.trim() ) ) return;
        setSending( true );
        setError( null );
        const req = isRegister
            ? api( 'register', { method: 'POST', body: JSON.stringify( { email, name: name.trim() } ) } )
            : api( 'send-link', { method: 'POST', body: JSON.stringify( { email } ) } );
        req
            .then( () => { stashReturn(); setSent( true ); } )
            .catch( ( err ) => setError( err.message ) )
            .finally( () => setSending( false ) );
    };

    if ( sent ) {
        return (
            <div class="dp-signin">
                <h2>{ __( 'Check your email', 'dono' ) }</h2>
                <p>{ sprintf(
                    /* translators: %s: action the link performs, either "finish setting up your account" or "sign in" */
                    __( 'If that address is valid, we just sent a link to %s. Open it on any device.', 'dono' ),
                    isRegister ? __( 'finish setting up your account', 'dono' ) : __( 'sign in', 'dono' )
                ) }</p>
            </div>
        );
    }

    return (
        <div class="dp-signin">
            <h2>{ isRegister ? __( 'Create your account', 'dono' ) : __( 'Donor portal', 'dono' ) }</h2>
            <p>
                { isRegister
                    ? __( "Set up an account to start fundraising. We'll email you a link to confirm.", 'dono' )
                    : __( "Enter the email you donated with and we'll send a sign-in link.", 'dono' ) }
            </p>
            <form onSubmit={ submit }>
                { isRegister && (
                    <input
                        type="text"
                        required
                        value={ name }
                        aria-label={ __( 'Your name', 'dono' ) }
                        placeholder={ __( 'Your name', 'dono' ) }
                        onInput={ ( e ) => setName( e.target.value ) }
                    />
                ) }
                <input
                    type="email"
                    required
                    value={ email }
                    aria-label={ __( 'Email address', 'dono' ) }
                    placeholder={ __( 'Enter your email address', 'dono' ) }
                    onInput={ ( e ) => setEmail( e.target.value ) }
                />
                <button type="submit" disabled={ sending }>
                    { sending ? __( 'Sending…', 'dono' ) : ( isRegister ? __( 'Create account', 'dono' ) : __( 'Send sign-in link', 'dono' ) ) }
                </button>
            </form>
            { error && <p class="dp-signin__error">{ error }</p> }
            <p class="dp-signin__alt">
                { isRegister ? __( 'Already have an account or donated before? ', 'dono' ) : __( 'New here and want to fundraise? ', 'dono' ) }
                <button type="button" class="dp-link" onClick={ () => { setError( null ); setMode( isRegister ? 'signin' : 'register' ); } }>
                    { isRegister ? __( 'Sign in', 'dono' ) : __( 'Create an account', 'dono' ) }
                </button>
            </p>
        </div>
    );
}

function Overview( { me } ) {
    return (
        <div class="dp-overview">
            <div class="dp-kpis">
                <Kpi label={ __( 'Lifetime giving', 'dono' ) } value={ formatAmount( me.total_donated_cents, me.primary_currency || 'USD' ) } />
                <Kpi label={ __( 'Donations', 'dono' ) } value={ String( me.donations_count ) } />
                <Kpi label={ __( 'Donor since', 'dono' ) } value={ me.first_donation_at ? formatDate( me.first_donation_at ) : '-' } />
            </div>
            <p class="dp-hint">{ __( 'Manage recurring donations, download receipts, and update preferences from the tabs above.', 'dono' ) }</p>
        </div>
    );
}

function freqLabel( f ) {
    const map = {
        one_time:  __( 'one time', 'dono' ),
        weekly:    __( 'weekly', 'dono' ),
        biweekly:  __( 'biweekly', 'dono' ),
        monthly:   __( 'monthly', 'dono' ),
        quarterly: __( 'quarterly', 'dono' ),
        yearly:    __( 'yearly', 'dono' ),
    };
    return map[ f ] || String( f || '' ).replace( '_', ' ' );
}

function Donations( { onOpen } ) {
    const [ list, setList ]   = useState( null );
    const [ error, setError ] = useState( null );
    useEffect( () => { api( 'donations' ).then( setList ).catch( ( e ) => setError( e.message ) ); }, [] );

    if ( error )    return <p class="dp-error">{ error }</p>;
    if ( ! list )   return <p>{ __( 'Loading donations…', 'dono' ) }</p>;
    if ( ! list.length ) return <p>{ __( 'No donations yet.', 'dono' ) }</p>;

    return (
        <ul class="dp-list">
            { list.map( ( d ) => (
                <li
                    key={ d.id }
                    class="dp-list__row"
                    role="button"
                    tabIndex={ 0 }
                    onClick={ () => onOpen( d.reference ) }
                    onKeyDown={ ( e ) => { if ( e.key === 'Enter' || e.key === ' ' ) { e.preventDefault(); onOpen( d.reference ); } } }
                    aria-label={ sprintf( /* translators: %s: donation reference */ __( 'View donation %s', 'dono' ), d.reference ) }
                >
                    <div>
                        <strong>{ formatAmount( d.amount_cents, d.currency ) }</strong>
                        { d.fee_covered_cents > 0 && (
                            <span class="dp-list__pill">{ sprintf( /* translators: %s: formatted fee amount */ __( 'incl. %s fees', 'dono' ), formatAmount( d.fee_covered_cents, d.currency ) ) }</span>
                        ) }
                        { d.is_anonymous && <span class="dp-list__pill">{ __( 'anonymous', 'dono' ) }</span> }
                        <div class="dp-list__sub">{ formatDate( d.paid_at ) } · { d.reference }</div>
                    </div>
                    <span class={ `dp-pill dp-pill--${ d.frequency }` }>{ freqLabel( d.frequency ) }</span>
                </li>
            ) ) }
        </ul>
    );
}

function DonationDetail( { reference, onClose } ) {
    const [ d, setD ]         = useState( null );
    const [ error, setError ] = useState( null );
    const [ editing, setEditing ] = useState( null );
    const panelRef = useRef( null );
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
        <div class="dp-modal" role="dialog" aria-modal="true" aria-label={ __( 'Donation details', 'dono' ) } onClick={ ( e ) => { if ( e.target === e.currentTarget ) onClose(); } } ref={ panelRef }>
            <div class="dp-modal__panel">
                <button class="dp-modal__close" onClick={ onClose } aria-label={ __( 'Close', 'dono' ) }>×</button>
                { error && <p class="dp-error">{ error }</p> }
                { ! d ? <p>{ __( 'Loading…', 'dono' ) }</p> : (
                    <>
                        <div class="dp-detail__head">
                            <div class="dp-detail__amount">{ formatAmount( d.amount_cents, d.currency ) }</div>
                            <div class="dp-detail__meta">{ formatDate( d.paid_at ) } · { d.reference }</div>
                        </div>

                        { d.give_again_url && (
                            <div class="dp-detail__section">
                                <a class="dp-action is-primary" href={ d.give_again_url }>
                                    { sprintf( /* translators: %s: formatted donation amount */ __( 'Give again (%s)', 'dono' ), formatAmount( d.amount_cents, d.currency ) ) }
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
                                <span>{ __( 'Show as anonymous on public displays', 'dono' ) }</span>
                            </label>
                        </div>

                        <div class="dp-detail__section">
                            <h4>{ __( 'Tribute', 'dono' ) }</h4>
                            { d.tribute ? (
                                <div>
                                    <strong>{ tributeTypeLabel( d.tribute.type ) } { d.tribute.name }</strong>
                                    { d.tribute.message && <p class="dp-detail__msg">{ d.tribute.message }</p> }
                                    <button class="dp-link" onClick={ () => setEditing( d.tribute ) }>{ __( 'Edit', 'dono' ) }</button>
                                </div>
                            ) : (
                                <button class="dp-link" onClick={ () => setEditing( { type: 'honor', name: '', message: '' } ) }>
                                    { __( 'Add a tribute', 'dono' ) }
                                </button>
                            ) }
                            { editing && (
                                <TributeForm
                                    initial={ editing }
                                    onSave={ ( payload ) => {
                                        api( `donations/${ reference }/tribute`, {
                                            method: 'POST',
                                            body:   JSON.stringify( payload ),
                                        } ).then( () => { setEditing( null ); load(); } )
                                            .catch( ( e ) => setError( e.message ) );
                                    } }
                                    onCancel={ () => setEditing( null ) }
                                />
                            ) }
                        </div>
                    </>
                ) }
            </div>
        </div>
    );
}

function tributeTypeLabel( type ) {
    if ( type === 'honor' )    return __( 'In honor of', 'dono' );
    if ( type === 'memorial' ) return __( 'In memory of', 'dono' );
    const t = String( type || '' ).replace( /-/g, ' ' ).trim();
    return t.charAt( 0 ).toUpperCase() + t.slice( 1 );
}

function TributeForm( { initial, onSave, onCancel } ) {
    const KNOWN = [
        { id: 'honor',    label: __( 'In honor of', 'dono' ) },
        { id: 'memorial', label: __( 'In memory of', 'dono' ) },
    ];
    const initialType = initial.type || 'honor';
    const initiallyCustom = ! KNOWN.some( ( k ) => k.id === initialType );

    const [ type, setType ]       = useState( initialType );
    const [ custom, setCustom ]   = useState( initiallyCustom );
    const [ name, setName ]       = useState( initial.name || '' );
    const [ msg, setMsg ]         = useState( initial.message || '' );
    const [ ann, setAnn ]         = useState( !! initial.convert_to_annual );
    const [ notify, setNotify ]   = useState( initial.notify_email || '' );

    return (
        <div class="dp-tribute-form">
            <div class="dp-tribute-form__types">
                { KNOWN.map( ( k ) => (
                    <label key={ k.id }>
                        <input
                            type="radio"
                            checked={ ! custom && type === k.id }
                            onChange={ () => { setCustom( false ); setType( k.id ); } }
                        /> { k.label }
                    </label>
                ) ) }
                <label>
                    <input
                        type="radio"
                        checked={ custom }
                        onChange={ () => { setCustom( true ); setType( initiallyCustom ? initialType : '' ); } }
                    /> { __( 'Other', 'dono' ) }
                </label>
            </div>
            { custom && (
                <input
                    type="text"
                    placeholder={ __( 'Enter tribute type', 'dono' ) }
                    value={ type }
                    onInput={ ( e ) => setType( e.target.value ) }
                />
            ) }
            <input type="text" placeholder={ __( 'Name', 'dono' ) } value={ name } onInput={ ( e ) => setName( e.target.value ) } />
            <input type="email" placeholder={ __( 'Notify someone (optional email)', 'dono' ) } value={ notify } onInput={ ( e ) => setNotify( e.target.value ) } />
            <textarea rows={ 2 } placeholder={ __( 'Message (optional)', 'dono' ) } value={ msg } onInput={ ( e ) => setMsg( e.target.value ) } />
            <label class="dp-tribute-form__check">
                <input type="checkbox" checked={ ann } onChange={ ( e ) => setAnn( e.target.checked ) } />
                { __( 'Remember this person every year on this date with a matching donation', 'dono' ) }
            </label>
            <div class="dp-tribute-form__actions">
                <button onClick={ onCancel }>{ __( 'Cancel', 'dono' ) }</button>
                <button class="is-primary" onClick={ () => onSave( {
                    type,
                    name,
                    message: msg,
                    notify_email: notify.trim() || undefined,
                    convert_to_annual: ann,
                } ) }>{ __( 'Save', 'dono' ) }</button>
            </div>
        </div>
    );
}

function Recurring() {
    const [ list, setList ]   = useState( null );
    const [ error, setError ] = useState( null );
    const [ action, setAction ] = useState( null );

    const load = useCallback( () => {
        api( 'recurring' ).then( setList ).catch( ( e ) => setError( e.message ) );
    }, [] );

    useEffect( () => { load(); }, [ load ] );

    if ( error )    return <p class="dp-error">{ error }</p>;
    if ( ! list )   return <p>{ __( 'Loading…', 'dono' ) }</p>;
    if ( ! list.length ) return <p>{ __( 'No recurring donations.', 'dono' ) }</p>;

    return (
        <>
            <ul class="dp-list">
                { list.map( ( p ) => (
                    <li key={ p.id } class="dp-list__row">
                        <div>
                            <strong>{ formatAmount( p.amount_cents, p.currency ) }</strong>
                            <span class="dp-list__pill">{ intervalLabel( p.interval_count, p.interval_unit ) }</span>
                            <div class="dp-list__sub">
                                { sprintf( /* translators: %s: date of the next scheduled payment */ __( 'Next: %s', 'dono' ), p.next_payment_at ? formatDate( p.next_payment_at ) : '-' ) }
                            </div>
                        </div>
                        <div class="dp-list__actions">
                            <span class={ `dp-pill dp-pill--${ p.status }` }>{ recurringStatusLabel( p.status ) }</span>
                            { p.status === 'active' && (
                                <button class="dp-link" onClick={ () => setAction( p ) }>{ __( 'Manage', 'dono' ) }</button>
                            ) }
                            { p.status === 'paused' && (
                                <button class="dp-link" onClick={ () => {
                                    api( `recurring/${ p.id }/action`, { method: 'POST', body: JSON.stringify( { action: 'resume' } ) } )
                                        .then( load )
                                        .catch( ( e ) => setError( e.message ) );
                                } }>{ __( 'Resume', 'dono' ) }</button>
                            ) }
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
    const panelRef = useRef( null );
    useFocusTrap( panelRef, true, onClose );

    const call = ( body ) => api( `recurring/${ plan.id }/action`, { method: 'POST', body: JSON.stringify( body ) } )
        .then( onDone )
        .catch( ( e ) => setErr( e.message || __( 'Something went wrong.', 'dono' ) ) );

    return (
        <div class="dp-modal" role="dialog" aria-modal="true" aria-label={ __( 'Manage donation', 'dono' ) } onClick={ ( e ) => { if ( e.target === e.currentTarget ) onClose(); } } ref={ panelRef }>
            <div class="dp-modal__panel">
                <button class="dp-modal__close" onClick={ onClose } aria-label={ __( 'Close', 'dono' ) }>×</button>
                { err && <p class="dp-error">{ err }</p> }

                { stage === 'menu' && (
                    <>
                        <h3>{ __( 'Manage donation', 'dono' ) }</h3>
                        <button class="dp-action" onClick={ () => setStage( 'pause' ) }>{ __( 'Pause', 'dono' ) }</button>
                        <button class="dp-action" onClick={ () => call( { action: 'skip_next' } ) }>{ __( 'Skip next charge', 'dono' ) }</button>
                        <button class="dp-action" onClick={ () => setStage( 'amount' ) }>{ __( 'Change amount', 'dono' ) }</button>
                        <button class="dp-action dp-action--danger" onClick={ () => setStage( 'cancel' ) }>{ __( 'Cancel donation', 'dono' ) }</button>
                    </>
                ) }

                { stage === 'pause' && (
                    <>
                        <h3>{ __( 'Pause for how long?', 'dono' ) }</h3>
                        { [ 1, 3, 6, 12 ].map( ( m ) => (
                            <button key={ m } class="dp-action" onClick={ () => call( { action: 'pause', months: m } ) }>
                                { sprintf( /* translators: %d: number of months */ _n( '%d month', '%d months', m, 'dono' ), m ) }
                            </button>
                        ) ) }
                    </>
                ) }

                { stage === 'amount' && (
                    <ChangeAmountForm plan={ plan } onSubmit={ ( cents ) => call( { action: 'change_amount', amount_cents: cents } ) } />
                ) }

                { stage === 'cancel' && (
                    <CancelDeflection plan={ plan }
                        onPause={  () => setStage( 'pause' ) }
                        onSkip={   () => call( { action: 'skip_next' } ) }
                        onReduce={ () => setStage( 'amount' ) }
                        onCancel={ ( reason ) => call( { action: 'cancel', reason } ) }
                    />
                ) }
            </div>
        </div>
    );
}

function ChangeAmountForm( { plan, onSubmit } ) {
    const major = ( plan.amount_cents / 100 ).toFixed( 2 );
    const [ value, setValue ] = useState( major );
    const cents = Math.round( parseFloat( value ) * 100 );
    const valid = Number.isFinite( cents ) && cents >= 50;
    return (
        <>
            <h3>{ __( 'Change amount', 'dono' ) }</h3>
            <p class="dp-hint">{ __( 'Current:', 'dono' ) } { formatAmount( plan.amount_cents, plan.currency ) }</p>
            <input
                type="number"
                step="0.01"
                min="0.5"
                value={ value }
                aria-label={ __( 'New donation amount', 'dono' ) }
                onInput={ ( e ) => setValue( e.target.value ) }
            />
            <button class="dp-action is-primary" disabled={ ! valid } onClick={ () => valid && onSubmit( cents ) }>{ __( 'Save new amount', 'dono' ) }</button>
        </>
    );
}

function CancelDeflection( { plan, onPause, onSkip, onReduce, onCancel } ) {
    const [ confirmed, setConfirmed ] = useState( false );
    const [ reason, setReason ] = useState( '' );

    if ( confirmed ) {
        return (
            <>
                <h3>{ __( 'Cancel donation?', 'dono' ) }</h3>
                <p>{ __( "You'll keep all donations you've made so far. The recurring schedule will stop after today.", 'dono' ) }</p>
                <textarea
                    placeholder={ __( 'Tell us why (optional, helps the org)', 'dono' ) }
                    rows={ 3 }
                    value={ reason }
                    onInput={ ( e ) => setReason( e.target.value ) }
                />
                <button class="dp-action dp-action--danger" onClick={ () => onCancel( reason ) }>{ __( 'Cancel donation', 'dono' ) }</button>
            </>
        );
    }

    return (
        <>
            <h3>{ __( 'Before you cancel…', 'dono' ) }</h3>
            <p class="dp-hint">{ __( 'A few alternatives that might work better:', 'dono' ) }</p>
            <button class="dp-action" onClick={ onPause }>{ __( 'Pause for 1-12 months', 'dono' ) }</button>
            <button class="dp-action" onClick={ onSkip }>{ __( 'Skip just the next charge', 'dono' ) }</button>
            <button class="dp-action" onClick={ onReduce }>{ __( 'Lower the amount', 'dono' ) }</button>
            <button class="dp-action dp-action--danger" onClick={ () => setConfirmed( true ) }>{ __( 'Continue to cancel', 'dono' ) }</button>
        </>
    );
}

function Receipts() {
    const [ list, setList ]   = useState( null );
    const [ error, setError ] = useState( null );
    const [ year, setYear ]   = useState( new Date().getFullYear() );
    const [ years, setYears ] = useState( [ new Date().getFullYear() ] );
    const [ dlError, setDlError ] = useState( '' );

    useEffect( () => {
        api( 'receipts' ).then( setList ).catch( ( e ) => setError( e.message ) );
        // Clamp year picker to the donor's actual donation history so they
        // can't pick a year that returns an empty PDF.
        api( 'me' )
            .then( ( me ) => {
                const now   = new Date().getFullYear();
                const first = me?.first_donation_at
                    ? new Date( String( me.first_donation_at ).replace( ' ', 'T' ) ).getFullYear()
                    : now;
                const safe  = Math.min( now, Math.max( now - 9, first || now ) );
                const span  = Math.max( 1, now - safe + 1 );
                setYears( Array.from( { length: span }, ( _, i ) => now - i ) );
            } )
            .catch( () => {} );
    }, [] );

    const downloadAnnual = async () => {
        setDlError( '' );
        try {
            const blob = await api( `annual-statement/${ year }` );
            const url  = URL.createObjectURL( blob );
            const a    = document.createElement( 'a' );
            a.href     = url;
            a.download = `dono-annual-${ year }.pdf`;
            // Append before click + defer the revoke so the download isn't
            // cancelled mid-flight (matches the GDPR export below).
            document.body.appendChild( a );
            a.click();
            a.remove();
            setTimeout( () => URL.revokeObjectURL( url ), 10000 );
        } catch ( err ) {
            setDlError( err.message || __( 'Could not generate statement.', 'dono' ) );
        }
    };

    // Fetch a fresh download link at click time so it never opens expired.
    const downloadReceipt = async ( id ) => {
        setDlError( '' );
        try {
            const res = await api( `receipts/${ id }/download-url` );
            if ( res?.url ) window.open( res.url, '_blank', 'noopener' );
        } catch ( err ) {
            setDlError( err.message || __( 'Could not open the receipt. Please try again.', 'dono' ) );
        }
    };

    return (
        <>
            <div class="dp-card">
                <h3>{ __( 'Annual statement', 'dono' ) }</h3>
                <p class="dp-hint">{ __( 'One consolidated PDF covering all your donations in a given year.', 'dono' ) }</p>
                <div class="dp-card__row">
                    <select value={ year } aria-label={ __( 'Statement year', 'dono' ) } onChange={ ( e ) => setYear( e.target.value ) }>
                        { years.map( ( y ) => (
                            <option key={ y } value={ y }>{ y }</option>
                        ) ) }
                    </select>
                    <button class="dp-action is-primary" onClick={ downloadAnnual }>{ __( 'Download statement', 'dono' ) }</button>
                </div>
                { dlError && <p class="dp-error">{ dlError }</p> }
            </div>

            <h3>{ __( 'Individual receipts', 'dono' ) }</h3>
            { error    && <p class="dp-error">{ error }</p> }
            { ! list   && <p>{ __( 'Loading…', 'dono' ) }</p> }
            { list && list.length === 0 && <p>{ __( 'No receipts yet.', 'dono' ) }</p> }
            { list && list.length > 0 && (
                <ul class="dp-list">
                    { list.map( ( r ) => (
                        <li key={ r.id } class="dp-list__row">
                            <div>
                                <strong>{ r.receipt_number }</strong>
                                <div class="dp-list__sub">{ formatDate( r.issued_at ) }</div>
                            </div>
                            <button type="button" class="dp-link" onClick={ () => downloadReceipt( r.id ) }>{ __( 'Download', 'dono' ) }</button>
                        </li>
                    ) ) }
                </ul>
            ) }
        </>
    );
}

function Profile( { onSaved } ) {
    const [ form, setForm ]   = useState( null );
    const [ saving, setSaving ] = useState( false );
    const [ saved,  setSaved  ] = useState( false );
    const [ err,    setErr    ] = useState( '' );

    useEffect( () => { api( 'profile' ).then( ( v ) => setForm( withDefaults( v ) ) ).catch( ( e ) => setErr( e.message || __( 'Could not load your profile.', 'dono' ) ) ); }, [] );

    if ( ! form ) return <p>{ err || __( 'Loading…', 'dono' ) }</p>;

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
            .catch( ( e ) => setErr( e.message || __( 'Could not save.', 'dono' ) ) )
            .finally( () => setSaving( false ) );
    };

    return (
        <div class="dp-form">
            <label>{ __( 'Email', 'dono' ) }
                <input type="email" value={ form.email } disabled readOnly />
                <small>{ __( 'To change your email, contact the organisation.', 'dono' ) }</small>
            </label>
            <div class="dp-form__row">
                <label>{ __( 'First name', 'dono' ) } <input type="text" value={ form.first_name } onInput={ set( 'first_name' ) } /></label>
                <label>{ __( 'Last name', 'dono' ) }  <input type="text" value={ form.last_name }  onInput={ set( 'last_name' ) } /></label>
            </div>
            <label>{ __( 'Phone', 'dono' ) }   <input type="tel" autocomplete="tel" value={ form.phone } onInput={ set( 'phone' ) } /></label>
            <CountryPicker value={ form.country } onChange={ ( code ) => setForm( { ...form, country: code } ) } />
            <label>{ __( 'Company', 'dono' ) } <input type="text" value={ form.company } onInput={ set( 'company' ) } /></label>
            <div class="dp-form__actions">
                <button class="dp-action is-primary" disabled={ saving } onClick={ save }>
                    { saving ? __( 'Saving…', 'dono' ) : __( 'Save', 'dono' ) }
                </button>
                { saved && <span class="dp-form__saved">{ __( 'Saved.', 'dono' ) }</span> }
                { err && <span class="dp-error">{ err }</span> }
            </div>
            <PrivacyActions />
        </div>
    );
}

function PrivacyActions() {
    const [ exporting, setExporting ] = useState( false );
    const [ deleting, setDeleting ]   = useState( false );
    const [ error, setError ]         = useState( null );

    const downloadData = async () => {
        setExporting( true );
        setError( null );
        try {
            // Direct fetch (not the api() helper) so the attachment streams as
            // a binary download instead of being JSON.parsed in the helper.
            const headers = {
                'Content-Type': 'application/json',
                'X-WP-Nonce':   cfg.nonce,
            };
            if ( csrfToken ) headers[ 'X-Dono-Csrf' ] = csrfToken;
            const r = await fetch( `${ cfg.rest }data-export`, {
                method:      'POST',
                credentials: 'same-origin',
                headers,
            } );
            if ( ! r.ok ) {
                const data = await r.json().catch( () => ({}) );
                throw new Error( data.message || __( 'Export failed.', 'dono' ) );
            }
            const blob = await r.blob();
            const url  = URL.createObjectURL( blob );
            const a    = document.createElement( 'a' );
            a.href     = url;
            a.download = 'my-data.json';
            document.body.appendChild( a );
            a.click();
            a.remove();
            URL.revokeObjectURL( url );
        } catch ( e ) {
            setError( e.message || __( 'Export failed.', 'dono' ) );
        } finally {
            setExporting( false );
        }
    };

    const forget = async () => {
        if ( ! window.confirm( __( 'Permanently anonymize your account? Past donations stay attached for tax/audit but every other detail is wiped. This cannot be undone.', 'dono' ) ) ) return;
        const confirm = window.prompt( sprintf( /* translators: %s: the literal confirmation keyword to type (DELETE) */ __( 'Type %s to confirm.', 'dono' ), 'DELETE' ) );
        if ( confirm !== 'DELETE' ) return;
        setDeleting( true );
        setError( null );
        try {
            await api( 'forget', { method: 'POST', body: JSON.stringify( { confirm: 'DELETE' } ) } );
            window.location.reload();
        } catch ( e ) {
            setError( e.message || __( 'Deletion failed.', 'dono' ) );
            setDeleting( false );
        }
    };

    return (
        <div class="dp-privacy">
            <h4>{ __( 'Your data', 'dono' ) }</h4>
            { error && <p class="dp-error">{ error }</p> }
            <div class="dp-privacy__actions">
                <button class="dp-action" disabled={ exporting } onClick={ downloadData }>
                    { exporting ? __( 'Preparing…', 'dono' ) : __( 'Download my data', 'dono' ) }
                </button>
                <button class="dp-action is-destructive" disabled={ deleting } onClick={ forget }>
                    { deleting ? __( 'Deleting…', 'dono' ) : __( 'Delete my account', 'dono' ) }
                </button>
            </div>
            <p class="dp-privacy__note">
                { __( "Download returns a JSON copy of everything we hold on you. Deletion anonymizes your record; donation totals stay for the organisation's tax records.", 'dono' ) }
            </p>
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
        ...( v || {} ),
    };
}

function CountryPicker( { value, onChange } ) {
    const current = COUNTRIES.find( ( c ) => c.code === ( value || '' ).toUpperCase() );
    const [ query, setQuery ] = useState( current ? current.name : '' );
    const [ open, setOpen ]   = useState( false );

    useEffect( () => {
        const c = COUNTRIES.find( ( c ) => c.code === ( value || '' ).toUpperCase() );
        setQuery( c ? c.name : ( value || '' ) );
    }, [ value ] );

    const q       = query.trim().toLowerCase();
    const matches = q === ''
        ? COUNTRIES
        : COUNTRIES.filter( ( c ) => c.name.toLowerCase().includes( q ) || c.code.toLowerCase().startsWith( q ) );

    const pick = ( c ) => {
        onChange( c.code );
        setQuery( c.name );
        setOpen( false );
    };

    return (
        <label class="dp-country">
            { __( 'Country', 'dono' ) }
            <div class="dp-country__wrap">
                <input
                    type="text"
                    value={ query }
                    placeholder={ __( 'Search country…', 'dono' ) }
                    onFocus={ () => setOpen( true ) }
                    onBlur={ () => setTimeout( () => setOpen( false ), 150 ) }
                    onInput={ ( e ) => { setQuery( e.target.value ); setOpen( true ); } }
                />
                { open && matches.length > 0 && (
                    <ul class="dp-country__list">
                        { matches.slice( 0, 50 ).map( ( c ) => (
                            <li key={ c.code }>
                                <button type="button" onMouseDown={ ( e ) => { e.preventDefault(); pick( c ); } }>
                                    <span>{ c.name }</span>
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

function Consents() {
    const [ list, setList ] = useState( null );
    const [ saving, setSaving ] = useState( false );
    const [ savedAt, setSavedAt ] = useState( null );
    const [ err, setErr ] = useState( '' );

    const load = useCallback( () => api( 'consents' ).then( setList ).catch( ( e ) => setErr( e.message || __( 'Could not load your consents.', 'dono' ) ) ), [] );
    useEffect( () => { load(); }, [ load ] );
    useEffect( () => {
        if ( ! savedAt ) return undefined;
        const t = setTimeout( () => setSavedAt( null ), 2500 );
        return () => clearTimeout( t );
    }, [ savedAt ] );

    if ( ! list ) return <p>{ err || __( 'Loading…', 'dono' ) }</p>;
    if ( ! list.length ) return (
        <div class="dp-empty">
            <p>{ __( 'No consent purposes are defined yet.', 'dono' ) }</p>
            <p class="dp-hint">{ __( 'The organisation has not configured any subscriptions or consents.', 'dono' ) }</p>
        </div>
    );

    const toggle = ( key, next ) => {
        const items = [ { key, granted: next } ];
        setList( ( cur ) => cur.map( ( p ) => p.key === key ? { ...p, granted: next } : p ) );
        setSaving( true );
        setErr( '' );
        api( 'consents', { method: 'POST', body: JSON.stringify( { items } ) } )
            .then( ( fresh ) => { setList( fresh ); setSavedAt( Date.now() ); } )
            .catch( ( e ) => { setErr( e.message || __( 'Could not save your choice.', 'dono' ) ); load(); } )
            .finally( () => setSaving( false ) );
    };

    const confirmStale = ( key ) => {
        // The donor accepts the new version without flipping the toggle. We
        // re-send the current `granted` state so the server records a fresh
        // row with the new purpose_version, clearing the stale flag.
        const cur = list.find( ( p ) => p.key === key );
        if ( ! cur ) return;
        const items = [ { key, granted: !! cur.granted } ];
        setSaving( true );
        setErr( '' );
        api( 'consents', { method: 'POST', body: JSON.stringify( { items } ) } )
            .then( ( fresh ) => { setList( fresh ); setSavedAt( Date.now() ); } )
            .catch( ( e ) => { setErr( e.message || __( 'Could not save your choice.', 'dono' ) ); load(); } )
            .finally( () => setSaving( false ) );
    };

    const staleCount = list.filter( ( p ) => p.stale ).length;

    return (
        <div class="dp-consents">
            { err && <p class="dp-error">{ err }</p> }
            { staleCount > 0 && (
                <div class="dp-consents__notice" role="status">
                    <strong>{ sprintf( /* translators: %d: number of consent items that were updated */ _n( '%d updated.', '%d updated.', staleCount, 'dono' ), staleCount ) }</strong>{ ' ' }
                    { __( 'The items marked below have new terms since you last reviewed them. Confirm or change each one.', 'dono' ) }
                </div>
            ) }
            { staleCount === 0 && (
                <p class="dp-hint">{ __( 'Toggle each subscription below. Every change is logged for your records.', 'dono' ) }</p>
            ) }
            { list.map( ( p ) => (
                <label
                    key={ p.key }
                    class={ `dp-consent${ p.required ? ' is-required' : '' }${ p.stale ? ' is-stale' : '' }` }
                >
                    <input
                        type="checkbox"
                        checked={ p.granted }
                        disabled={ p.required }
                        onChange={ ( e ) => toggle( p.key, e.target.checked ) }
                    />
                    <div>
                        <strong>{ p.label }</strong>
                        { p.required && <span class="dp-consent__required">{ __( 'required', 'dono' ) }</span> }
                        { p.stale && <span class="dp-consent__stale">{ __( 'Updated', 'dono' ) }</span> }
                        { p.description && <p class="dp-consent__desc">{ p.description }</p> }
                        { p.has_record && p.occurred_at && (
                            <p class="dp-consent__meta">{ sprintf( /* translators: %s: date the consent was last confirmed */ __( 'Last confirmed %s', 'dono' ), formatDate( p.occurred_at ) ) }</p>
                        ) }
                        { p.stale && (
                            <button
                                type="button"
                                class="dp-consent__confirm"
                                onClick={ () => confirmStale( p.key ) }
                            >
                                { __( 'Keep as is', 'dono' ) }
                            </button>
                        ) }
                    </div>
                </label>
            ) ) }
            { saving && <p class="dp-consent__saving">{ __( 'Saving…', 'dono' ) }</p> }
            { ! saving && savedAt && <p class="dp-consent__saving dp-form__saved" role="status">{ __( 'Saved.', 'dono' ) }</p> }
        </div>
    );
}

function Preferences() {
    const [ p, setP ] = useState( null );
    const [ saving, setSaving ] = useState( false );
    const [ saved, setSaved ] = useState( false );
    const [ err, setErr ] = useState( '' );

    useEffect( () => { api( 'preferences' ).then( setP ).catch( ( e ) => setErr( e.message || __( 'Could not load your preferences.', 'dono' ) ) ); }, [] );
    useEffect( () => {
        if ( ! saved ) return undefined;
        const t = setTimeout( () => setSaved( false ), 2500 );
        return () => clearTimeout( t );
    }, [ saved ] );

    if ( ! p ) return <p>{ err || __( 'Loading…', 'dono' ) }</p>;

    const save = () => {
        setSaving( true );
        setErr( '' );
        setSaved( false );
        api( 'preferences', { method: 'POST', body: JSON.stringify( p ) } )
            .then( ( fresh ) => { setP( fresh ); setSaved( true ); } )
            .catch( ( e ) => setErr( e.message || __( 'Could not save.', 'dono' ) ) )
            .finally( () => setSaving( false ) );
    };

    return (
        <div class="dp-prefs">
            <div class="dp-prefs__col">
                <h4>{ __( 'Privacy', 'dono' ) }</h4>
                <label>
                    <input type="checkbox" checked={ p.always_anonymous } onChange={ ( e ) => setP( { ...p, always_anonymous: e.target.checked } ) } />
                    { __( 'Make all future donations anonymous', 'dono' ) }
                </label>
            </div>
            <button class="dp-action is-primary" disabled={ saving } onClick={ save }>{ saving ? __( 'Saving…', 'dono' ) : __( 'Save preferences', 'dono' ) }</button>
            { ! saving && saved && <span class="dp-form__saved" role="status">{ __( 'Saved.', 'dono' ) }</span> }
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

function formatDate( iso ) {
    if ( ! iso ) return '';
    // Backend timestamps are GMT (PHP gmdate); mark as UTC so the local-date
    // conversion doesn't skew a day near midnight.
    const d = new Date( iso.replace( ' ', 'T' ) + 'Z' );
    return d.toLocaleDateString();
}

function recurringStatusLabel( s ) {
    switch ( s ) {
        case 'active':    return __( 'Active', 'dono' );
        case 'paused':    return __( 'Paused', 'dono' );
        case 'cancelled': return __( 'Cancelled', 'dono' );
        case 'expired':   return __( 'Expired', 'dono' );
        default:          return s;
    }
}

function intervalLabel( count, unit ) {
    const n = Number( count ) || 1;
    const u = unit === 'year' ? _n( 'year', 'years', n, 'dono' )
        : unit === 'week'     ? _n( 'week', 'weeks', n, 'dono' )
        :                       _n( 'month', 'months', n, 'dono' );
    /* translators: 1: count, 2: interval unit (e.g. months) */
    return sprintf( __( 'Every %1$d %2$s', 'dono' ), n, u );
}

const mount = document.getElementById( 'dono-donor-portal' );
if ( mount ) render( <App />, mount );
