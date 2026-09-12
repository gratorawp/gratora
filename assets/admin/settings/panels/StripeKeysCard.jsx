import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import Card from '../../_shared/components/Card';
import { ToggleRow } from '../../_shared/components/Switch';
import Btn from '../../_shared/components/Btn';
import BrandMark from '../../_shared/components/BrandMark';
import FormRow from '../../_shared/components/FormRow';
import KeyField from '../../_shared/components/KeyField';
import ConfirmDialog from '../../_shared/components/ConfirmDialog';
import Notice from '../../_shared/components/Notice';
import { notify } from '../../_shared/notify';
import useCardOpen from '../../_shared/useCardOpen';

function Pill( { tone, children } ) {
    return (
        <span className={ `gratora-pill gratora-pill--${ tone }` }>
            <span className="gratora-pill__dot" />
            { children }
        </span>
    );
}

function AccountFoot( { account, onRemove, removing } ) {
    const tail = account?.account_id ? account.account_id.slice( -4 ) : '';
    const yes = <span style={ { color: 'var(--gratora-color-accent)' } }>{ __( 'Enabled', 'gratora-donation-platform' ) }</span>;
    const no  = <span style={ { color: 'var(--gratora-color-red)' } }>{ __( 'Disabled', 'gratora-donation-platform' ) }</span>;
    return (
        <div className="gratora-gateway-foot">
            <div className="gratora-gateway-foot__cell">
                <div className="lbl">{ __( 'Account', 'gratora-donation-platform' ) }</div>
                <div className="val is-muted is-mono">{ tail ? `acct_…${ tail }` : '...' }</div>
            </div>
            <div className="gratora-gateway-foot__cell">
                <div className="lbl">{ __( 'Charges', 'gratora-donation-platform' ) }</div>
                <div className="val">{ account?.charges_enabled ? yes : no }</div>
            </div>
            <div className="gratora-gateway-foot__cell">
                <div className="lbl">{ __( 'Payouts', 'gratora-donation-platform' ) }</div>
                <div className="val">{ account?.payouts_enabled ? yes : no }</div>
            </div>
            <div style={ { flex: 1 } } />
            <Btn variant="danger" size="sm" onClick={ onRemove } isBusy={ removing } disabled={ removing }>
                { __( 'Remove keys', 'gratora-donation-platform' ) }
            </Btn>
        </div>
    );
}

/**
 * One mode's key pair. Saved keys collapse to a last-4 summary: the secret is
 * write-only, so there is nothing to show and nothing to accidentally leak.
 */
function ModeKeys( { mode, saved, publishable, onSaved, onRemove } ) {
    const isTest = mode === 'test';
    const [ open, setOpen ] = useState( ! saved );
    const [ sk, setSk ]     = useState( '' );
    const [ pk, setPk ]     = useState( '' );
    const [ busy, setBusy ] = useState( false );

    const label = isTest ? __( 'Test keys', 'gratora-donation-platform' ) : __( 'Live keys', 'gratora-donation-platform' );
    const prefix = isTest ? 'test' : 'live';

    const save = () => {
        if ( ! sk.trim() || ! pk.trim() ) {
            notify.error( __( 'Enter both the publishable key and the secret key.', 'gratora-donation-platform' ) );
            return;
        }
        setBusy( true );
        apiFetch( {
            path:   '/gratora/v1/gateways/stripe/keys',
            method: 'POST',
            data:   { mode, secret_key: sk.trim(), publishable_key: pk.trim() },
        } )
            .then( ( res ) => {
                setSk( '' );
                setPk( '' );
                setOpen( false );
                notify.success(
                    isTest
                        ? __( 'Test keys verified and saved.', 'gratora-donation-platform' )
                        : __( 'Live keys verified and saved.', 'gratora-donation-platform' )
                );
                onSaved( res );
            } )
            .catch( ( err ) => notify.error( err?.message || __( 'Could not verify those keys.', 'gratora-donation-platform' ) ) )
            .finally( () => setBusy( false ) );
    };

    return (
        <div className="gratora-stripe-mode">
            <div className="gratora-stripe-mode__head">
                <strong>{ label }</strong>
                { saved
                    ? <Pill tone="green">{ __( 'Saved', 'gratora-donation-platform' ) }</Pill>
                    : <Pill tone="gray">{ __( 'Not set', 'gratora-donation-platform' ) }</Pill> }
            </div>

            { saved && ! open && (
                <div className="gratora-stripe-mode__saved">
                    <span className="is-mono is-muted">{ publishable || '' }</span>
                    <div className="gratora-stripe-mode__actions">
                        <Btn variant="secondary" size="sm" onClick={ () => setOpen( true ) }>
                            { __( 'Replace', 'gratora-donation-platform' ) }
                        </Btn>
                        <Btn variant="ghost" size="sm" onClick={ () => onRemove( mode ) }>
                            { __( 'Remove', 'gratora-donation-platform' ) }
                        </Btn>
                    </div>
                </div>
            ) }

            { open && (
                <>
                    <FormRow
                        label={ __( 'Publishable key', 'gratora-donation-platform' ) }
                        help={ __( 'Safe to expose. Used in the browser to show the payment fields.', 'gratora-donation-platform' ) }
                    >
                        <KeyField value={ pk } onChange={ setPk } placeholder={ `pk_${ prefix }_…` } />
                    </FormRow>
                    <FormRow
                        label={ __( 'Secret key', 'gratora-donation-platform' ) }
                        help={ __( 'Stored encrypted and never shown again. Gratora verifies it with Stripe before saving.', 'gratora-donation-platform' ) }
                    >
                        <KeyField value={ sk } onChange={ setSk } placeholder={ `sk_${ prefix }_…` } secret />
                    </FormRow>
                    <div className="gratora-stripe-mode__actions">
                        <Btn variant="primary" size="sm" onClick={ save } isBusy={ busy } disabled={ busy }>
                            { __( 'Save and verify', 'gratora-donation-platform' ) }
                        </Btn>
                        { saved && (
                            <Btn variant="ghost" size="sm" onClick={ () => { setOpen( false ); setSk( '' ); setPk( '' ); } }>
                                { __( 'Cancel', 'gratora-donation-platform' ) }
                            </Btn>
                        ) }
                    </div>
                </>
            ) }
        </div>
    );
}

/**
 * Apple Pay needs the domain verified before its button will render, and it
 * fails silently when it is not: the button simply never appears. Google Pay
 * needs nothing beyond the Stripe account, so it gets a line of copy and no
 * controls.
 */
function ApplePaySection( { status, onDone } ) {
    const account = status?.account || null;
    const apple   = status?.apple_pay || {};
    const hasFile = !! apple.has_file;

    const [ file, setFile ] = useState( '' );
    const [ busy, setBusy ] = useState( false );
    const [ open, setOpen ] = useState( ! hasFile );

    const modes = [ 'test', 'live' ].filter( ( m ) => !! account?.[ `has_${ m }` ] );
    const active = modes.length > 0 && modes.every( ( m ) => apple?.[ m ]?.status === 'active' );

    const enable = () => {
        const pasted = file.trim();
        if ( ! hasFile && ! pasted ) {
            notify.error( __( 'Paste the domain association file from Stripe first.', 'gratora-donation-platform' ) );
            return;
        }
        if ( ! modes.length ) {
            notify.error( __( 'Save your Stripe keys first.', 'gratora-donation-platform' ) );
            return;
        }

        setBusy( true );
        // Stripe registers a domain per mode, so every saved mode needs its own
        // call before Apple Pay works there.
        Promise.all( modes.map( ( mode ) => apiFetch( {
            path:   '/gratora/v1/gateways/stripe/apple-pay',
            method: 'POST',
            data:   { mode, association_file: pasted },
        } ).then(
            ( r ) => ( { ok: r?.apple_pay?.status === 'active', message: r?.apple_pay?.message || '' } ),
            ( e ) => ( { ok: false, message: e?.message || '' } )
        ) ) )
            .then( ( results ) => {
                const bad = results.find( ( r ) => ! r.ok );
                if ( bad ) {
                    notify.error(
                        bad.message ||
                        __( 'Stripe could not verify this domain yet. Check the file is reachable, then try again.', 'gratora-donation-platform' )
                    );
                } else {
                    setFile( '' );
                    setOpen( false );
                    notify.success( __( 'Apple Pay is verified for this domain.', 'gratora-donation-platform' ) );
                }
                onDone();
            } )
            .finally( () => setBusy( false ) );
    };

    const stateLabel = ( mode ) => {
        const st = apple?.[ mode ]?.status;
        if ( st === 'active' )   return __( 'verified', 'gratora-donation-platform' );
        if ( st === 'inactive' ) return __( 'not verified', 'gratora-donation-platform' );
        return __( 'not checked yet', 'gratora-donation-platform' );
    };

    let pill = <Pill tone="gray">{ __( 'Not set up', 'gratora-donation-platform' ) }</Pill>;
    if ( hasFile && active )      pill = <Pill tone="green">{ __( 'Verified', 'gratora-donation-platform' ) }</Pill>;
    else if ( hasFile )           pill = <Pill tone="amber">{ __( 'Not verified', 'gratora-donation-platform' ) }</Pill>;

    const firstMessage = modes.map( ( m ) => apple?.[ m ]?.message ).find( Boolean );

    return (
        <div className="gratora-connect-options">
            <div className="gratora-stripe-mode">
                <div className="gratora-stripe-mode__head">
                    <strong>{ __( 'Apple Pay', 'gratora-donation-platform' ) }</strong>
                    { pill }
                </div>

                <p className="gratora-connect-p">
                    { __( 'Google Pay needs nothing here, it appears as soon as your Stripe account supports it. Apple checks that you own this domain first, and until it verifies, the Apple Pay button just never shows.', 'gratora-donation-platform' ) }
                </p>

                <FormRow label={ __( 'Domain', 'gratora-donation-platform' ) }>
                    { /* No onChange: KeyField renders read-only with a Copy button. */ }
                    <KeyField value={ apple.domain || '' } />
                </FormRow>

                { open ? (
                    <>
                        <FormRow
                            label={ __( 'Domain association file', 'gratora-donation-platform' ) }
                            help={ __( 'In Stripe, go to Settings, Payment method domains, and add the domain above. Stripe links a file to download, paste its whole contents here.', 'gratora-donation-platform' ) }
                            wide
                        >
                            <textarea
                                className="gratora-textarea gratora-textarea--mono"
                                rows={ 4 }
                                value={ file }
                                onChange={ ( e ) => setFile( e.target.value ) }
                                placeholder="7B227073704964223A…"
                            />
                        </FormRow>
                        <div className="gratora-stripe-mode__actions">
                            <Btn variant="primary" size="sm" onClick={ enable } isBusy={ busy } disabled={ busy }>
                                { __( 'Enable Apple Pay', 'gratora-donation-platform' ) }
                            </Btn>
                            { hasFile && (
                                <Btn variant="ghost" size="sm" onClick={ () => { setOpen( false ); setFile( '' ); } }>
                                    { __( 'Cancel', 'gratora-donation-platform' ) }
                                </Btn>
                            ) }
                        </div>
                    </>
                ) : (
                    <div className="gratora-stripe-mode__saved">
                        <span className="is-muted">
                            { modes.map( ( m ) => sprintf(
                                /* translators: 1: Stripe mode, test or live. 2: verification state. */
                                __( '%1$s: %2$s', 'gratora-donation-platform' ),
                                m === 'test' ? __( 'Test', 'gratora-donation-platform' ) : __( 'Live', 'gratora-donation-platform' ),
                                stateLabel( m )
                            ) ).join( '  ·  ' ) }
                        </span>
                        <div className="gratora-stripe-mode__actions">
                            <Btn variant="secondary" size="sm" onClick={ enable } isBusy={ busy } disabled={ busy }>
                                { __( 'Check again', 'gratora-donation-platform' ) }
                            </Btn>
                            <Btn variant="ghost" size="sm" onClick={ () => setOpen( true ) }>
                                { __( 'Replace file', 'gratora-donation-platform' ) }
                            </Btn>
                        </div>
                    </div>
                ) }

                { ! active && firstMessage && (
                    <Notice status="warning">{ firstMessage }</Notice>
                ) }
            </div>
        </div>
    );
}

export default function StripeKeysCard( { s } ) {
    const [ status, setStatus ]       = useState( null );
    const [ loading, setLoading ]     = useState( true );
    const [ loadError, setLoadError ] = useState( false );
    const [ removing, setRemoving ]   = useState( false );
    const [ confirm, setConfirm ]     = useState( null );

    const load = useCallback( () => {
        setLoading( true );
        setLoadError( false );
        apiFetch( { path: '/gratora/v1/gateways/stripe/status' } )
            .then( ( r ) => setStatus( r ) )
            .catch( () => { setStatus( null ); setLoadError( true ); } )
            .finally( () => setLoading( false ) );
    }, [] );

    useEffect( () => { load(); }, [ load ] );

    const removeKeys = useCallback( ( mode ) => {
        const all = mode === 'all';
        setConfirm( {
            title: all ? __( 'Remove Stripe keys', 'gratora-donation-platform' ) : __( 'Remove these keys', 'gratora-donation-platform' ),
            message: all
                ? __( 'Remove both key pairs? Card donations will stop until you add keys again.', 'gratora-donation-platform' )
                : __( 'Remove this key pair? Donations in this mode will stop until you add keys again.', 'gratora-donation-platform' ),
            confirmLabel: __( 'Remove', 'gratora-donation-platform' ),
            destructive: true,
            onConfirm: async () => {
                setRemoving( true );
                apiFetch( { path: `/gratora/v1/gateways/stripe/keys?mode=${ mode }`, method: 'DELETE' } )
                    .then( ( res ) => setStatus( res ) )
                    .catch( ( err ) => notify.error( err?.message || __( 'Could not remove the keys.', 'gratora-donation-platform' ) ) )
                    .finally( () => setRemoving( false ) );
            },
        } );
    }, [] );

    const account   = status?.account || null;
    const connected = !! status?.connected;
    const canCharge = !! status?.can_charge;

    const [ open, setOpen ] = useCardOpen( loadError || ( connected && ! canCharge ), 'payments', 'stripe' );

    const head = {
        leading:     <BrandMark letter="S" variant="stripe" />,
        title:       __( 'Stripe', 'gratora-donation-platform' ),
        collapsible: true,
        open,
        onToggle:    setOpen,
    };
    const sub = __( 'Cards, SEPA, Apple Pay, Google Pay', 'gratora-donation-platform' );

    if ( loading ) {
        return (
            <Card { ...head } sub={ sub } meta={ <Pill tone="gray">{ __( 'Checking…', 'gratora-donation-platform' ) }</Pill> }>
                <p className="gratora-connect-p">{ __( 'Loading Stripe status…', 'gratora-donation-platform' ) }</p>
            </Card>
        );
    }

    // Status request failed: say so and offer a retry, rather than falling
    // through to a state that misreports the real setup.
    if ( loadError ) {
        return (
            <Card { ...head } sub={ sub } meta={ <Pill tone="amber">{ __( 'Unavailable', 'gratora-donation-platform' ) }</Pill> }>
                <Notice status="warning">
                    <strong>{ __( 'Could not check your Stripe setup.', 'gratora-donation-platform' ) }</strong>{ ' ' }
                    { __( 'Something went wrong loading the status. Please try again.', 'gratora-donation-platform' ) }
                </Notice>
                <div style={ { marginTop: 18 } }>
                    <Btn variant="primary" onClick={ load }>{ __( 'Retry', 'gratora-donation-platform' ) }</Btn>
                </div>
            </Card>
        );
    }

    let meta = <Pill tone="gray">{ __( 'Not set up', 'gratora-donation-platform' ) }</Pill>;
    if ( connected && canCharge ) meta = <Pill tone="green">{ __( 'Ready', 'gratora-donation-platform' ) }</Pill>;
    else if ( connected ) meta = <Pill tone="amber">{ __( 'Action needed', 'gratora-donation-platform' ) }</Pill>;

    const bizName = account?.business_name || account?.email || '';

    return (
        <>
        <Card
            { ...head }
            sub={ connected && bizName ? bizName : sub }
            meta={ meta }
            foot={ connected
                ? <AccountFoot account={ account } onRemove={ () => removeKeys( 'all' ) } removing={ removing } />
                : null }
        >
            { ! connected && (
                <>
                    <p className="gratora-connect-p">
                        { __( 'Add the API keys from your own Stripe account. Donations are charged directly on your account and pay out to your bank, and Gratora never takes a cut.', 'gratora-donation-platform' ) }
                    </p>
                    <p className="gratora-connect-p">
                        { __( 'Find them in the Stripe dashboard under Developers, API keys. Add your test keys first to try a donation safely.', 'gratora-donation-platform' ) }
                    </p>
                </>
            ) }

            <ToggleRow
                title={ __( 'Enable the Stripe gateway', 'gratora-donation-platform' ) }
                sub={ connected
                    ? __( 'Your keys stay on file while it is off.', 'gratora-donation-platform' )
                    : __( 'Available once your keys are saved.', 'gratora-donation-platform' ) }
                checked={ connected && !! s.value( 'stripe.enabled', true ) }
                onChange={ s.setValue( 'stripe.enabled' ) }
                disabled={ ! connected }
            />

            { connected && ! canCharge && (
                <Notice status="warning">
                    <strong>{ __( 'Your Stripe account cannot take payments yet.', 'gratora-donation-platform' ) }</strong>{ ' ' }
                    { __( 'Stripe still needs some verification details (ID, bank account, business info). Finish that in your Stripe dashboard; live donations will fail until you do.', 'gratora-donation-platform' ) }
                </Notice>
            ) }

            { connected && canCharge && (
                <Notice status="success">
                    <strong>{ __( 'You are all set.', 'gratora-donation-platform' ) }</strong>{ ' ' }
                    { __( 'Donations are charged on your Stripe account and paid out to your bank.', 'gratora-donation-platform' ) }
                </Notice>
            ) }

            <div className="gratora-stripe-modes">
                <ModeKeys
                    mode="test"
                    saved={ !! account?.has_test }
                    publishable={ account?.publishable_test || '' }
                    onSaved={ setStatus }
                    onRemove={ removeKeys }
                />
                <ModeKeys
                    mode="live"
                    saved={ !! account?.has_live }
                    publishable={ account?.publishable_live || '' }
                    onSaved={ setStatus }
                    onRemove={ removeKeys }
                />
            </div>

            { connected && <ApplePaySection status={ status } onDone={ load } /> }

            <div className="gratora-connect-options">
                <p className="gratora-connect-p">
                    { __( 'Webhooks tell Gratora when a payment succeeds, fails or is refunded. Gratora registers this endpoint on your account automatically when you save keys. On a local site Stripe cannot reach it, so add the endpoint yourself and paste its signing secret below.', 'gratora-donation-platform' ) }
                </p>
                <FormRow label={ __( 'Webhook endpoint', 'gratora-donation-platform' ) }>
                    { /* No onChange: KeyField renders read-only with a Copy button. */ }
                    <KeyField value={ status?.webhook_url || '' } />
                </FormRow>
                { s && (
                    <>
                        <FormRow
                            label={ __( 'Webhook signing secret (test)', 'gratora-donation-platform' ) }
                            help={ __( 'From the test-mode Stripe webhook endpoint. Needed for paid, refund and dispute updates on test donations. Once saved it is hidden, so the dots mean it is set: type a new one to replace it, or clear the field to remove it.', 'gratora-donation-platform' ) }
                        >
                            <KeyField
                                value={ s.value( 'stripe.webhook_secret_test', '' ) }
                                onChange={ s.setValue( 'stripe.webhook_secret_test' ) }
                                placeholder="whsec_…"
                                secret
                            />
                        </FormRow>
                        <FormRow
                            label={ __( 'Webhook signing secret (live)', 'gratora-donation-platform' ) }
                            help={ __( 'From the live-mode Stripe webhook endpoint. Stripe issues a separate secret for live; without it, live webhooks are rejected. Once saved it is hidden, same as the test one.', 'gratora-donation-platform' ) }
                        >
                            <KeyField
                                value={ s.value( 'stripe.webhook_secret_live', '' ) }
                                onChange={ s.setValue( 'stripe.webhook_secret_live' ) }
                                placeholder="whsec_…"
                                secret
                            />
                        </FormRow>
                    </>
                ) }
            </div>
        </Card>
        <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
        </>
    );
}
