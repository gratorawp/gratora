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
import { notify } from '../../_shared/notify';
import useCardOpen from '../../_shared/useCardOpen';

function Pill( { tone, children } ) {
    return (
        <span className={ `giveflow-pill giveflow-pill--${ tone }` }>
            <span className="giveflow-pill__dot" />
            { children }
        </span>
    );
}

function Notice( { tone, icon, children } ) {
    return (
        <div className={ `giveflow-connect-notice giveflow-connect-notice--${ tone }` }>
            <span className="giveflow-connect-notice__icon" aria-hidden="true">{ icon }</span>
            <div>{ children }</div>
        </div>
    );
}

function AccountFoot( { account, onRemove, removing } ) {
    const tail = account?.account_id ? account.account_id.slice( -4 ) : '';
    const yes = <span style={ { color: 'var(--giveflow-color-accent)' } }>{ __( 'Enabled', 'giveflow-fundraising-campaigns' ) }</span>;
    const no  = <span style={ { color: 'var(--giveflow-color-red)' } }>{ __( 'Disabled', 'giveflow-fundraising-campaigns' ) }</span>;
    return (
        <div className="giveflow-gateway-foot">
            <div className="giveflow-gateway-foot__cell">
                <div className="lbl">{ __( 'Account', 'giveflow-fundraising-campaigns' ) }</div>
                <div className="val is-muted is-mono">{ tail ? `acct_…${ tail }` : '...' }</div>
            </div>
            <div className="giveflow-gateway-foot__cell">
                <div className="lbl">{ __( 'Charges', 'giveflow-fundraising-campaigns' ) }</div>
                <div className="val">{ account?.charges_enabled ? yes : no }</div>
            </div>
            <div className="giveflow-gateway-foot__cell">
                <div className="lbl">{ __( 'Payouts', 'giveflow-fundraising-campaigns' ) }</div>
                <div className="val">{ account?.payouts_enabled ? yes : no }</div>
            </div>
            <div style={ { flex: 1 } } />
            <Btn variant="danger" size="sm" onClick={ onRemove } isBusy={ removing } disabled={ removing }>
                { __( 'Remove keys', 'giveflow-fundraising-campaigns' ) }
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

    const label = isTest ? __( 'Test keys', 'giveflow-fundraising-campaigns' ) : __( 'Live keys', 'giveflow-fundraising-campaigns' );
    const prefix = isTest ? 'test' : 'live';

    const save = () => {
        if ( ! sk.trim() || ! pk.trim() ) {
            notify.error( __( 'Enter both the publishable key and the secret key.', 'giveflow-fundraising-campaigns' ) );
            return;
        }
        setBusy( true );
        apiFetch( {
            path:   '/giveflow/v1/gateways/stripe/keys',
            method: 'POST',
            data:   { mode, secret_key: sk.trim(), publishable_key: pk.trim() },
        } )
            .then( ( res ) => {
                setSk( '' );
                setPk( '' );
                setOpen( false );
                notify.success(
                    isTest
                        ? __( 'Test keys verified and saved.', 'giveflow-fundraising-campaigns' )
                        : __( 'Live keys verified and saved.', 'giveflow-fundraising-campaigns' )
                );
                onSaved( res );
            } )
            .catch( ( err ) => notify.error( err?.message || __( 'Could not verify those keys.', 'giveflow-fundraising-campaigns' ) ) )
            .finally( () => setBusy( false ) );
    };

    return (
        <div className="giveflow-stripe-mode">
            <div className="giveflow-stripe-mode__head">
                <strong>{ label }</strong>
                { saved
                    ? <Pill tone="green">{ __( 'Saved', 'giveflow-fundraising-campaigns' ) }</Pill>
                    : <Pill tone="gray">{ __( 'Not set', 'giveflow-fundraising-campaigns' ) }</Pill> }
            </div>

            { saved && ! open && (
                <div className="giveflow-stripe-mode__saved">
                    <span className="is-mono is-muted">{ publishable || '' }</span>
                    <div className="giveflow-stripe-mode__actions">
                        <Btn variant="secondary" size="sm" onClick={ () => setOpen( true ) }>
                            { __( 'Replace', 'giveflow-fundraising-campaigns' ) }
                        </Btn>
                        <Btn variant="ghost" size="sm" onClick={ () => onRemove( mode ) }>
                            { __( 'Remove', 'giveflow-fundraising-campaigns' ) }
                        </Btn>
                    </div>
                </div>
            ) }

            { open && (
                <>
                    <FormRow
                        label={ __( 'Publishable key', 'giveflow-fundraising-campaigns' ) }
                        help={ __( 'Safe to expose. Used in the browser to show the payment fields.', 'giveflow-fundraising-campaigns' ) }
                    >
                        <KeyField value={ pk } onChange={ setPk } placeholder={ `pk_${ prefix }_…` } />
                    </FormRow>
                    <FormRow
                        label={ __( 'Secret key', 'giveflow-fundraising-campaigns' ) }
                        help={ __( 'Stored encrypted and never shown again. GiveFlow verifies it with Stripe before saving.', 'giveflow-fundraising-campaigns' ) }
                    >
                        <KeyField value={ sk } onChange={ setSk } placeholder={ `sk_${ prefix }_…` } secret />
                    </FormRow>
                    <div className="giveflow-stripe-mode__actions">
                        <Btn variant="primary" size="sm" onClick={ save } isBusy={ busy } disabled={ busy }>
                            { __( 'Save and verify', 'giveflow-fundraising-campaigns' ) }
                        </Btn>
                        { saved && (
                            <Btn variant="ghost" size="sm" onClick={ () => { setOpen( false ); setSk( '' ); setPk( '' ); } }>
                                { __( 'Cancel', 'giveflow-fundraising-campaigns' ) }
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
            notify.error( __( 'Paste the domain association file from Stripe first.', 'giveflow-fundraising-campaigns' ) );
            return;
        }
        if ( ! modes.length ) {
            notify.error( __( 'Save your Stripe keys first.', 'giveflow-fundraising-campaigns' ) );
            return;
        }

        setBusy( true );
        // Stripe registers a domain per mode, so every saved mode needs its own
        // call before Apple Pay works there.
        Promise.all( modes.map( ( mode ) => apiFetch( {
            path:   '/giveflow/v1/gateways/stripe/apple-pay',
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
                        __( 'Stripe could not verify this domain yet. Check the file is reachable, then try again.', 'giveflow-fundraising-campaigns' )
                    );
                } else {
                    setFile( '' );
                    setOpen( false );
                    notify.success( __( 'Apple Pay is verified for this domain.', 'giveflow-fundraising-campaigns' ) );
                }
                onDone();
            } )
            .finally( () => setBusy( false ) );
    };

    const stateLabel = ( mode ) => {
        const st = apple?.[ mode ]?.status;
        if ( st === 'active' )   return __( 'verified', 'giveflow-fundraising-campaigns' );
        if ( st === 'inactive' ) return __( 'not verified', 'giveflow-fundraising-campaigns' );
        return __( 'not checked yet', 'giveflow-fundraising-campaigns' );
    };

    let pill = <Pill tone="gray">{ __( 'Not set up', 'giveflow-fundraising-campaigns' ) }</Pill>;
    if ( hasFile && active )      pill = <Pill tone="green">{ __( 'Verified', 'giveflow-fundraising-campaigns' ) }</Pill>;
    else if ( hasFile )           pill = <Pill tone="amber">{ __( 'Not verified', 'giveflow-fundraising-campaigns' ) }</Pill>;

    const firstMessage = modes.map( ( m ) => apple?.[ m ]?.message ).find( Boolean );

    return (
        <div className="giveflow-connect-options">
            <div className="giveflow-stripe-mode">
                <div className="giveflow-stripe-mode__head">
                    <strong>{ __( 'Apple Pay', 'giveflow-fundraising-campaigns' ) }</strong>
                    { pill }
                </div>

                <p className="giveflow-connect-p">
                    { __( 'Google Pay needs nothing here, it appears as soon as your Stripe account supports it. Apple checks that you own this domain first, and until it verifies, the Apple Pay button just never shows.', 'giveflow-fundraising-campaigns' ) }
                </p>

                <FormRow label={ __( 'Domain', 'giveflow-fundraising-campaigns' ) }>
                    { /* No onChange: KeyField renders read-only with a Copy button. */ }
                    <KeyField value={ apple.domain || '' } />
                </FormRow>

                { open ? (
                    <>
                        <FormRow
                            label={ __( 'Domain association file', 'giveflow-fundraising-campaigns' ) }
                            help={ __( 'In Stripe, go to Settings, Payment method domains, and add the domain above. Stripe links a file to download, paste its whole contents here.', 'giveflow-fundraising-campaigns' ) }
                            wide
                        >
                            <textarea
                                className="giveflow-textarea giveflow-textarea--mono"
                                rows={ 4 }
                                value={ file }
                                onChange={ ( e ) => setFile( e.target.value ) }
                                placeholder="7B227073704964223A…"
                            />
                        </FormRow>
                        <div className="giveflow-stripe-mode__actions">
                            <Btn variant="primary" size="sm" onClick={ enable } isBusy={ busy } disabled={ busy }>
                                { __( 'Enable Apple Pay', 'giveflow-fundraising-campaigns' ) }
                            </Btn>
                            { hasFile && (
                                <Btn variant="ghost" size="sm" onClick={ () => { setOpen( false ); setFile( '' ); } }>
                                    { __( 'Cancel', 'giveflow-fundraising-campaigns' ) }
                                </Btn>
                            ) }
                        </div>
                    </>
                ) : (
                    <div className="giveflow-stripe-mode__saved">
                        <span className="is-muted">
                            { modes.map( ( m ) => sprintf(
                                /* translators: 1: Stripe mode, test or live. 2: verification state. */
                                __( '%1$s: %2$s', 'giveflow-fundraising-campaigns' ),
                                m === 'test' ? __( 'Test', 'giveflow-fundraising-campaigns' ) : __( 'Live', 'giveflow-fundraising-campaigns' ),
                                stateLabel( m )
                            ) ).join( '  ·  ' ) }
                        </span>
                        <div className="giveflow-stripe-mode__actions">
                            <Btn variant="secondary" size="sm" onClick={ enable } isBusy={ busy } disabled={ busy }>
                                { __( 'Check again', 'giveflow-fundraising-campaigns' ) }
                            </Btn>
                            <Btn variant="ghost" size="sm" onClick={ () => setOpen( true ) }>
                                { __( 'Replace file', 'giveflow-fundraising-campaigns' ) }
                            </Btn>
                        </div>
                    </div>
                ) }

                { ! active && firstMessage && (
                    <Notice tone="amber" icon="!">{ firstMessage }</Notice>
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
        apiFetch( { path: '/giveflow/v1/gateways/stripe/status' } )
            .then( ( r ) => setStatus( r ) )
            .catch( () => { setStatus( null ); setLoadError( true ); } )
            .finally( () => setLoading( false ) );
    }, [] );

    useEffect( () => { load(); }, [ load ] );

    const removeKeys = useCallback( ( mode ) => {
        const all = mode === 'all';
        setConfirm( {
            title: all ? __( 'Remove Stripe keys', 'giveflow-fundraising-campaigns' ) : __( 'Remove these keys', 'giveflow-fundraising-campaigns' ),
            message: all
                ? __( 'Remove both key pairs? Card donations will stop until you add keys again.', 'giveflow-fundraising-campaigns' )
                : __( 'Remove this key pair? Donations in this mode will stop until you add keys again.', 'giveflow-fundraising-campaigns' ),
            confirmLabel: __( 'Remove', 'giveflow-fundraising-campaigns' ),
            destructive: true,
            onConfirm: async () => {
                setRemoving( true );
                apiFetch( { path: `/giveflow/v1/gateways/stripe/keys?mode=${ mode }`, method: 'DELETE' } )
                    .then( ( res ) => setStatus( res ) )
                    .catch( ( err ) => notify.error( err?.message || __( 'Could not remove the keys.', 'giveflow-fundraising-campaigns' ) ) )
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
        title:       __( 'Stripe', 'giveflow-fundraising-campaigns' ),
        collapsible: true,
        open,
        onToggle:    setOpen,
    };
    const sub = __( 'Cards, SEPA, Apple Pay, Google Pay', 'giveflow-fundraising-campaigns' );

    if ( loading ) {
        return (
            <Card { ...head } sub={ sub } meta={ <Pill tone="gray">{ __( 'Checking…', 'giveflow-fundraising-campaigns' ) }</Pill> }>
                <p className="giveflow-connect-p">{ __( 'Loading Stripe status…', 'giveflow-fundraising-campaigns' ) }</p>
            </Card>
        );
    }

    // Status request failed: say so and offer a retry, rather than falling
    // through to a state that misreports the real setup.
    if ( loadError ) {
        return (
            <Card { ...head } sub={ sub } meta={ <Pill tone="amber">{ __( 'Unavailable', 'giveflow-fundraising-campaigns' ) }</Pill> }>
                <Notice tone="amber" icon="!">
                    <strong>{ __( 'Could not check your Stripe setup.', 'giveflow-fundraising-campaigns' ) }</strong>{ ' ' }
                    { __( 'Something went wrong loading the status. Please try again.', 'giveflow-fundraising-campaigns' ) }
                </Notice>
                <div style={ { marginTop: 18 } }>
                    <Btn variant="primary" onClick={ load }>{ __( 'Retry', 'giveflow-fundraising-campaigns' ) }</Btn>
                </div>
            </Card>
        );
    }

    let meta = <Pill tone="gray">{ __( 'Not set up', 'giveflow-fundraising-campaigns' ) }</Pill>;
    if ( connected && canCharge ) meta = <Pill tone="green">{ __( 'Ready', 'giveflow-fundraising-campaigns' ) }</Pill>;
    else if ( connected ) meta = <Pill tone="amber">{ __( 'Action needed', 'giveflow-fundraising-campaigns' ) }</Pill>;

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
                    <p className="giveflow-connect-p">
                        { __( 'Add the API keys from your own Stripe account. Donations are charged directly on your account and pay out to your bank, and GiveFlow never takes a cut.', 'giveflow-fundraising-campaigns' ) }
                    </p>
                    <p className="giveflow-connect-p">
                        { __( 'Find them in the Stripe dashboard under Developers, API keys. Add your test keys first to try a donation safely.', 'giveflow-fundraising-campaigns' ) }
                    </p>
                </>
            ) }

            <ToggleRow
                title={ __( 'Enable the Stripe gateway', 'giveflow-fundraising-campaigns' ) }
                sub={ connected
                    ? __( 'Your keys stay on file while it is off.', 'giveflow-fundraising-campaigns' )
                    : __( 'Available once your keys are saved.', 'giveflow-fundraising-campaigns' ) }
                checked={ connected && !! s.value( 'stripe.enabled', true ) }
                onChange={ s.setValue( 'stripe.enabled' ) }
                disabled={ ! connected }
            />

            { connected && ! canCharge && (
                <Notice tone="amber" icon="⚠">
                    <strong>{ __( 'Your Stripe account cannot take payments yet.', 'giveflow-fundraising-campaigns' ) }</strong>{ ' ' }
                    { __( 'Stripe still needs some verification details (ID, bank account, business info). Finish that in your Stripe dashboard; live donations will fail until you do.', 'giveflow-fundraising-campaigns' ) }
                </Notice>
            ) }

            { connected && canCharge && (
                <Notice tone="accent" icon="✓">
                    <strong>{ __( 'You are all set.', 'giveflow-fundraising-campaigns' ) }</strong>{ ' ' }
                    { __( 'Donations are charged on your Stripe account and paid out to your bank.', 'giveflow-fundraising-campaigns' ) }
                </Notice>
            ) }

            <div className="giveflow-stripe-modes">
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

            <div className="giveflow-connect-options">
                <p className="giveflow-connect-p">
                    { __( 'Webhooks tell GiveFlow when a payment succeeds, fails or is refunded. GiveFlow registers this endpoint on your account automatically when you save keys. On a local site Stripe cannot reach it, so add the endpoint yourself and paste its signing secret below.', 'giveflow-fundraising-campaigns' ) }
                </p>
                <FormRow label={ __( 'Webhook endpoint', 'giveflow-fundraising-campaigns' ) }>
                    { /* No onChange: KeyField renders read-only with a Copy button. */ }
                    <KeyField value={ status?.webhook_url || '' } />
                </FormRow>
                { s && (
                    <>
                        <FormRow
                            label={ __( 'Webhook signing secret (test)', 'giveflow-fundraising-campaigns' ) }
                            help={ __( 'From the test-mode Stripe webhook endpoint. Needed for paid, refund and dispute updates on test donations. Once saved it is hidden, so the dots mean it is set: type a new one to replace it, or clear the field to remove it.', 'giveflow-fundraising-campaigns' ) }
                        >
                            <KeyField
                                value={ s.value( 'stripe.webhook_secret_test', '' ) }
                                onChange={ s.setValue( 'stripe.webhook_secret_test' ) }
                                placeholder="whsec_…"
                                secret
                            />
                        </FormRow>
                        <FormRow
                            label={ __( 'Webhook signing secret (live)', 'giveflow-fundraising-campaigns' ) }
                            help={ __( 'From the live-mode Stripe webhook endpoint. Stripe issues a separate secret for live; without it, live webhooks are rejected. Once saved it is hidden, same as the test one.', 'giveflow-fundraising-campaigns' ) }
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
