import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import Card from '../../_shared/components/Card';
import Btn from '../../_shared/components/Btn';
import BrandMark from '../../_shared/components/BrandMark';
import FormRow from '../../_shared/components/FormRow';
import KeyField from '../../_shared/components/KeyField';
import ConfirmDialog from '../../_shared/components/ConfirmDialog';
import { notify } from '../../_shared/notify';


function isPro() {
    return !! ( typeof window !== 'undefined' && window.dono?.pro?.active );
}

function readReturnFlash() {
    if ( typeof window === 'undefined' ) return '';
    const params = new URLSearchParams( window.location.search );
    return params.get( 'dono_connect' ) || '';
}

// Keys must match the dono_connect values StripeConnectController::callback emits.
const FLASH = {
    connected:     { tone: 'accent', text: __( 'Stripe account connected.', 'dono' ) },
    denied:        { tone: 'amber',  text: __( 'Stripe connection was cancelled.', 'dono' ) },
    invalid_state: { tone: 'amber',  text: __( 'The connection link expired. Please try again.', 'dono' ) },
    claim_failed:  { tone: 'amber',  text: __( 'Stripe could not complete the connection. Please try again.', 'dono' ) },
};

function Pill( { tone, children } ) {
    return (
        <span className={ `dono-pill dono-pill--${ tone }` }>
            <span className="dono-pill__dot" />
            { children }
        </span>
    );
}

function Notice( { tone, icon, children } ) {
    return (
        <div className={ `dono-connect-notice dono-connect-notice--${ tone }` }>
            <span className="dono-connect-notice__icon" aria-hidden="true">{ icon }</span>
            <div>{ children }</div>
        </div>
    );
}

function AccountFoot( { account, onDisconnect, disconnecting } ) {
    const tail = account?.account_id ? account.account_id.slice( -4 ) : '';
    const yes = <span style={ { color: 'var(--dono-color-accent)' } }>{ __( 'Enabled', 'dono' ) }</span>;
    const no  = <span style={ { color: 'var(--dono-color-red)' } }>{ __( 'Disabled', 'dono' ) }</span>;
    return (
        <div className="dono-gateway-foot">
            <div className="dono-gateway-foot__cell">
                <div className="lbl">{ __( 'Account', 'dono' ) }</div>
                <div className="val is-muted is-mono">{ tail ? `acct_…${ tail }` : '...' }</div>
            </div>
            <div className="dono-gateway-foot__cell">
                <div className="lbl">{ __( 'Charges', 'dono' ) }</div>
                <div className="val">{ account?.charges_enabled ? yes : no }</div>
            </div>
            <div className="dono-gateway-foot__cell">
                <div className="lbl">{ __( 'Payouts', 'dono' ) }</div>
                <div className="val">{ account?.payouts_enabled ? yes : no }</div>
            </div>
            <div style={ { flex: 1 } } />
            <Btn
                variant="danger"
                size="sm"
                onClick={ onDisconnect }
                isBusy={ disconnecting }
                disabled={ disconnecting }
            >
                { __( 'Disconnect', 'dono' ) }
            </Btn>
        </div>
    );
}

function DevConnect( { onConnected } ) {
    const [ open, setOpen ]   = useState( false );
    const [ acct, setAcct ]   = useState( '' );
    const [ tok, setTok ]     = useState( '' );
    const [ busy, setBusy ]   = useState( false );

    if ( ! open ) {
        return (
            <button type="button" className="dono-connect-devlink" onClick={ () => setOpen( true ) }>
                { __( 'Developer: paste a test connection', 'dono' ) }
            </button>
        );
    }

    const submit = () => {
        if ( ! acct || ! tok ) return;
        setBusy( true );
        apiFetch( {
            path:   '/dono/v1/connect/stripe/dev-connect',
            method: 'POST',
            data:   { account_id: acct, test_token: tok },
        } )
            .then( () => onConnected() )
            .catch( ( err ) => notify.error( err?.message || __( 'Could not save the test connection.', 'dono' ) ) )
            .finally( () => setBusy( false ) );
    };

    return (
        <div className="dono-connect-dev">
            <p className="dono-connect-p" style={ { marginBottom: 10 } }>
                { __( 'Local-only shortcut (WP_DEBUG). Paste a Stripe test account id and a test-mode secret/restricted key to simulate a connection before the broker is live.', 'dono' ) }
            </p>
            <FormRow label={ __( 'Account id (acct_…)', 'dono' ) }>
                <KeyField value={ acct } onChange={ setAcct } placeholder="acct_…" />
            </FormRow>
            <FormRow label={ __( 'Test access token (sk_test_… / rk_test_…)', 'dono' ) }>
                <KeyField value={ tok } onChange={ setTok } placeholder="sk_test_…" secret />
            </FormRow>
            <div style={ { marginTop: 12 } }>
                <Btn variant="primary" size="sm" onClick={ submit } isBusy={ busy } disabled={ busy }>
                    { __( 'Save test connection', 'dono' ) }
                </Btn>
            </div>
        </div>
    );
}

export default function StripeConnectCard( { s } ) {
    const [ status, setStatus ]           = useState( null );
    const [ loading, setLoading ]         = useState( true );
    const [ loadError, setLoadError ]     = useState( false );
    const [ connecting, setConnecting ]   = useState( false );
    const [ disconnecting, setDisconnect ] = useState( false );
    const [ flash, setFlash ]             = useState( readReturnFlash );
    const [ confirm, setConfirm ]         = useState( null );

    const load = useCallback( () => {
        setLoading( true );
        setLoadError( false );
        apiFetch( { path: '/dono/v1/connect/stripe/status' } )
            .then( ( r ) => { setStatus( r ); } )
            .catch( () => { setStatus( null ); setLoadError( true ); } )
            .finally( () => setLoading( false ) );
    }, [] );

    useEffect( () => { load(); }, [ load ] );

    // Drop the query flag so a refresh doesn't re-show the flash.
    useEffect( () => {
        if ( ! flash || typeof window === 'undefined' ) return;
        const url = new URL( window.location.href );
        url.searchParams.delete( 'dono_connect' );
        window.history.replaceState( {}, '', url.toString() );
    }, [ flash ] );

    const connect = useCallback( () => {
        setConnecting( true );
        apiFetch( { path: '/dono/v1/connect/stripe/authorize', method: 'POST' } )
            .then( ( res ) => {
                if ( res?.url ) {
                    window.location.assign( res.url );
                } else {
                    setConnecting( false );
                    notify.error( __( 'Could not start the Stripe connection. Please try again.', 'dono' ) );
                }
            } )
            .catch( ( err ) => {
                setConnecting( false );
                notify.error( err?.message || __( 'Could not start the Stripe connection. Please try again.', 'dono' ) );
            } );
    }, [] );

    const disconnect = useCallback( () => {
        setConfirm( {
            title:        __( 'Disconnect Stripe account', 'dono' ),
            message:      __( 'Disconnect this Stripe account? Donations will stop until you reconnect.', 'dono' ),
            confirmLabel: __( 'Disconnect', 'dono' ),
            destructive:  true,
            onConfirm: async () => {
                setDisconnect( true );
                apiFetch( { path: '/dono/v1/connect/stripe/disconnect', method: 'POST' } )
                    .then( () => { setFlash( '' ); load(); } )
                    .catch( ( err ) => notify.error( err?.message || __( 'Could not disconnect. Please try again.', 'dono' ) ) )
                    .finally( () => setDisconnect( false ) );
            },
        } );
    }, [ load ] );

    const head = {
        leading: <BrandMark letter="S" variant="stripe" />,
        title:   __( 'Stripe', 'dono' ),
    };

    let flashNote = null;
    if ( flash ) {
        const f = FLASH[ flash ] || { tone: 'amber', text: __( 'Something went wrong connecting Stripe. Please try again.', 'dono' ) };
        flashNote = <Notice tone={ f.tone } icon={ f.tone === 'accent' ? '✓' : '!' }>{ f.text }</Notice>;
    }

    const optionsBlock = s ? (
        <div className="dono-connect-options">
            <FormRow
                label={ __( 'Webhook signing secret (test)', 'dono' ) }
                help={ __( 'From the test-mode Stripe webhook endpoint. Needed for paid / refund / dispute updates on test donations.', 'dono' ) }
            >
                <KeyField
                    value={ s.value( 'stripe.webhook_secret_test', '' ) }
                    onChange={ s.setValue( 'stripe.webhook_secret_test' ) }
                    placeholder="whsec_…"
                    secret
                />
            </FormRow>
            <FormRow
                label={ __( 'Webhook signing secret (live)', 'dono' ) }
                help={ __( 'From the live-mode Stripe webhook endpoint. Stripe issues a separate secret for live; without it, live webhooks are rejected.', 'dono' ) }
            >
                <KeyField
                    value={ s.value( 'stripe.webhook_secret_live', '' ) }
                    onChange={ s.setValue( 'stripe.webhook_secret_live' ) }
                    placeholder="whsec_…"
                    secret
                />
            </FormRow>
        </div>
    ) : null;

    if ( loading ) {
        return (
            <Card { ...head } sub={ __( 'Cards, SEPA, Apple Pay, Google Pay', 'dono' ) }
                meta={ <Pill tone="gray">{ __( 'Checking…', 'dono' ) }</Pill> }>
                <p className="dono-connect-p">{ __( 'Loading connection status…', 'dono' ) }</p>
            </Card>
        );
    }

    // Status request failed: say so and offer a retry, rather than falling
    // through to a state that misreports the real connection.
    if ( loadError ) {
        return (
            <Card { ...head } sub={ __( 'Cards, SEPA, Apple Pay, Google Pay', 'dono' ) }
                meta={ <Pill tone="amber">{ __( 'Unavailable', 'dono' ) }</Pill> }>
                { flashNote }
                <Notice tone="amber" icon="!">
                    <strong>{ __( 'Could not check the Stripe connection.', 'dono' ) }</strong>{ ' ' }
                    { __( 'Something went wrong loading the status. Please try again.', 'dono' ) }
                </Notice>
                <div style={ { marginTop: 18 } }>
                    <Btn variant="primary" onClick={ load }>{ __( 'Retry', 'dono' ) }</Btn>
                </div>
            </Card>
        );
    }

    const platformReady = !! status?.platform_ready;
    const connected     = !! status?.connected;
    const canCharge     = !! status?.can_charge;
    const account       = status?.account || null;
    const pro           = isPro();

    // State 1: platform not configured.
    if ( ! platformReady ) {
        return (
            <Card { ...head } sub={ __( 'Cards, SEPA, Apple Pay, Google Pay', 'dono' ) }
                meta={ <Pill tone="gray">{ __( 'Setup pending', 'dono' ) }</Pill> }>
                { flashNote }
                <Notice tone="info" icon="◷">
                    <strong>{ __( 'Stripe payments are being finalised.', 'dono' ) }</strong>{ ' ' }
                    { __( 'Dono is completing payment-provider setup. Once it is live you will connect your own Stripe account here in one click. Check back shortly.', 'dono' ) }
                </Notice>
            </Card>
        );
    }

    // State 2: not connected.
    if ( ! connected ) {
        return (
            <Card { ...head } sub={ __( 'Cards, SEPA, Apple Pay, Google Pay', 'dono' ) }
                meta={ <Pill tone="gray">{ __( 'Not connected', 'dono' ) }</Pill> }>
                { flashNote }
                <p className="dono-connect-p">
                    { __( 'Connect a Stripe account to start accepting donations. Dono handles the setup. You will be sent to Stripe to sign in or create an account, then brought right back.', 'dono' ) }
                </p>
                <ul className="dono-connect-checks">
                    <li>{ __( 'Payouts go straight to your bank, on your schedule', 'dono' ) }</li>
                    <li>{ __( 'You stay in full control, disconnect anytime', 'dono' ) }</li>
                </ul>
                { ! pro && (
                    <Notice tone="accent" icon="ⓘ">
                        { __( 'On the free plan Dono keeps a', 'dono' ) } <strong>2%{ ' ' }{ __( 'platform fee', 'dono' ) }</strong>{ ' ' }
                        { __( 'per donation.', 'dono' ) }
                    </Notice>
                ) }
                <div style={ { marginTop: 18 } }>
                    <Btn variant="primary" onClick={ connect } isBusy={ connecting } disabled={ connecting }>
                        { __( 'Connect with Stripe', 'dono' ) }
                    </Btn>
                </div>
                { status?.dev_mode && <DevConnect onConnected={ () => { setFlash( '' ); load(); } } /> }
            </Card>
        );
    }

    // State 3: connected, onboarding incomplete on Stripe side.
    if ( ! canCharge ) {
        return (
            <>
            <Card { ...head }
                sub={ account?.email || __( 'Stripe account linked', 'dono' ) }
                meta={ <Pill tone="amber">{ __( 'Action needed', 'dono' ) }</Pill> }
                foot={ <AccountFoot account={ account } onDisconnect={ disconnect } disconnecting={ disconnecting } /> }
            >
                { flashNote }
                <Notice tone="amber" icon="⚠">
                    <strong>{ __( 'Your Stripe account is not ready to take donations yet.', 'dono' ) }</strong>{ ' ' }
                    { __( 'Stripe still needs a few verification details (ID, bank account, business info). Donations will fail until this is done.', 'dono' ) }
                </Notice>
                <div style={ { marginTop: 18 } }>
                    <Btn variant="primary" onClick={ connect } isBusy={ connecting } disabled={ connecting }>
                        { __( 'Finish setup on Stripe', 'dono' ) }
                    </Btn>
                </div>
                { optionsBlock }
            </Card>
            <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
            </>
        );
    }

    // State 4: connected and active.
    const bizName  = account?.business_name || account?.email || __( 'Stripe account', 'dono' );
    return (
        <>
        <Card { ...head }
            sub={ bizName }
            meta={ <Pill tone="green">{ __( 'Connected', 'dono' ) }</Pill> }
            foot={ <AccountFoot account={ account } onDisconnect={ disconnect } disconnecting={ disconnecting } /> }
        >
            { flashNote }
            <Notice tone="accent" icon="✓">
                <strong>{ __( 'You are all set.', 'dono' ) }</strong>{ ' ' }
                { __( 'Donations are flowing to your Stripe account and paying out to your bank.', 'dono' ) }
            </Notice>
            <div className="dono-connect-fee">
                <span>
                    { __( 'Dono platform fee', 'dono' ) }
                    { pro && <span className="dono-connect-pro">{ __( 'Pro', 'dono' ) }</span> }
                </span>
                { pro
                    ? <span className="dono-connect-fee__amt dono-connect-fee__amt--waived">{ __( 'Waived, 0%', 'dono' ) }</span>
                    : <span className="dono-connect-fee__amt">2%{ ' ' }{ __( 'per donation', 'dono' ) }</span>
                }
            </div>
            { optionsBlock }
        </Card>
        <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
        </>
    );
}
