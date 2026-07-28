import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import Card from '../../_shared/components/Card';
import Btn from '../../_shared/components/Btn';
import BrandMark from '../../_shared/components/BrandMark';
import FormRow from '../../_shared/components/FormRow';
import KeyField from '../../_shared/components/KeyField';
import ConfirmDialog from '../../_shared/components/ConfirmDialog';
import { notify } from '../../_shared/notify';

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

function AccountFoot( { account, onRemove, removing } ) {
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
            <Btn variant="danger" size="sm" onClick={ onRemove } isBusy={ removing } disabled={ removing }>
                { __( 'Remove keys', 'dono' ) }
            </Btn>
        </div>
    );
}

/**
 * One mode's key pair. Saved keys collapse to a last-4 summary: the secret is
 * write-only, so there is nothing to show and nothing to accidentally leak.
 */
function ModeKeys( { mode, saved, hint, publishable, onSaved, onRemove } ) {
    const isTest = mode === 'test';
    const [ open, setOpen ] = useState( ! saved );
    const [ sk, setSk ]     = useState( '' );
    const [ pk, setPk ]     = useState( '' );
    const [ busy, setBusy ] = useState( false );

    const label = isTest ? __( 'Test keys', 'dono' ) : __( 'Live keys', 'dono' );
    const prefix = isTest ? 'test' : 'live';

    const save = () => {
        if ( ! sk.trim() || ! pk.trim() ) {
            notify.error( __( 'Enter both the publishable key and the secret key.', 'dono' ) );
            return;
        }
        setBusy( true );
        apiFetch( {
            path:   '/dono/v1/gateways/stripe/keys',
            method: 'POST',
            data:   { mode, secret_key: sk.trim(), publishable_key: pk.trim() },
        } )
            .then( ( res ) => {
                setSk( '' );
                setPk( '' );
                setOpen( false );
                notify.success(
                    isTest
                        ? __( 'Test keys verified and saved.', 'dono' )
                        : __( 'Live keys verified and saved.', 'dono' )
                );
                onSaved( res );
            } )
            .catch( ( err ) => notify.error( err?.message || __( 'Could not verify those keys.', 'dono' ) ) )
            .finally( () => setBusy( false ) );
    };

    return (
        <div className="dono-stripe-mode">
            <div className="dono-stripe-mode__head">
                <strong>{ label }</strong>
                { saved
                    ? <Pill tone="green">{ sprintf( /* translators: %s: last 4 characters of the key */ __( 'Saved, ending %s', 'dono' ), hint ) }</Pill>
                    : <Pill tone="gray">{ __( 'Not set', 'dono' ) }</Pill> }
            </div>

            { saved && ! open && (
                <div className="dono-stripe-mode__saved">
                    <span className="is-mono is-muted">{ publishable || '' }</span>
                    <div className="dono-stripe-mode__actions">
                        <Btn variant="secondary" size="sm" onClick={ () => setOpen( true ) }>
                            { __( 'Replace', 'dono' ) }
                        </Btn>
                        <Btn variant="ghost" size="sm" onClick={ () => onRemove( mode ) }>
                            { __( 'Remove', 'dono' ) }
                        </Btn>
                    </div>
                </div>
            ) }

            { open && (
                <>
                    <FormRow
                        label={ __( 'Publishable key', 'dono' ) }
                        help={ __( 'Safe to expose. Used in the browser to show the payment fields.', 'dono' ) }
                    >
                        <KeyField value={ pk } onChange={ setPk } placeholder={ `pk_${ prefix }_…` } />
                    </FormRow>
                    <FormRow
                        label={ __( 'Secret key', 'dono' ) }
                        help={ __( 'Stored encrypted and never shown again. Dono verifies it with Stripe before saving.', 'dono' ) }
                    >
                        <KeyField value={ sk } onChange={ setSk } placeholder={ `sk_${ prefix }_…` } secret />
                    </FormRow>
                    <div className="dono-stripe-mode__actions">
                        <Btn variant="primary" size="sm" onClick={ save } isBusy={ busy } disabled={ busy }>
                            { __( 'Save and verify', 'dono' ) }
                        </Btn>
                        { saved && (
                            <Btn variant="ghost" size="sm" onClick={ () => { setOpen( false ); setSk( '' ); setPk( '' ); } }>
                                { __( 'Cancel', 'dono' ) }
                            </Btn>
                        ) }
                    </div>
                </>
            ) }
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
        apiFetch( { path: '/dono/v1/gateways/stripe/status' } )
            .then( ( r ) => setStatus( r ) )
            .catch( () => { setStatus( null ); setLoadError( true ); } )
            .finally( () => setLoading( false ) );
    }, [] );

    useEffect( () => { load(); }, [ load ] );

    const removeKeys = useCallback( ( mode ) => {
        const all = mode === 'all';
        setConfirm( {
            title: all ? __( 'Remove Stripe keys', 'dono' ) : __( 'Remove these keys', 'dono' ),
            message: all
                ? __( 'Remove both key pairs? Card donations will stop until you add keys again.', 'dono' )
                : __( 'Remove this key pair? Donations in this mode will stop until you add keys again.', 'dono' ),
            confirmLabel: __( 'Remove', 'dono' ),
            destructive: true,
            onConfirm: async () => {
                setRemoving( true );
                apiFetch( { path: `/dono/v1/gateways/stripe/keys?mode=${ mode }`, method: 'DELETE' } )
                    .then( ( res ) => setStatus( res ) )
                    .catch( ( err ) => notify.error( err?.message || __( 'Could not remove the keys.', 'dono' ) ) )
                    .finally( () => setRemoving( false ) );
            },
        } );
    }, [] );

    const head = {
        leading: <BrandMark letter="S" variant="stripe" />,
        title:   __( 'Stripe', 'dono' ),
    };
    const sub = __( 'Cards, SEPA, Apple Pay, Google Pay', 'dono' );

    if ( loading ) {
        return (
            <Card { ...head } sub={ sub } meta={ <Pill tone="gray">{ __( 'Checking…', 'dono' ) }</Pill> }>
                <p className="dono-connect-p">{ __( 'Loading Stripe status…', 'dono' ) }</p>
            </Card>
        );
    }

    // Status request failed: say so and offer a retry, rather than falling
    // through to a state that misreports the real setup.
    if ( loadError ) {
        return (
            <Card { ...head } sub={ sub } meta={ <Pill tone="amber">{ __( 'Unavailable', 'dono' ) }</Pill> }>
                <Notice tone="amber" icon="!">
                    <strong>{ __( 'Could not check your Stripe setup.', 'dono' ) }</strong>{ ' ' }
                    { __( 'Something went wrong loading the status. Please try again.', 'dono' ) }
                </Notice>
                <div style={ { marginTop: 18 } }>
                    <Btn variant="primary" onClick={ load }>{ __( 'Retry', 'dono' ) }</Btn>
                </div>
            </Card>
        );
    }

    const account   = status?.account || null;
    const connected = !! status?.connected;
    const canCharge = !! status?.can_charge;

    let meta = <Pill tone="gray">{ __( 'Not set up', 'dono' ) }</Pill>;
    if ( connected && canCharge ) meta = <Pill tone="green">{ __( 'Ready', 'dono' ) }</Pill>;
    else if ( connected ) meta = <Pill tone="amber">{ __( 'Action needed', 'dono' ) }</Pill>;

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
                    <p className="dono-connect-p">
                        { __( 'Add the API keys from your own Stripe account. Donations are charged directly on your account and pay out to your bank, and Dono never takes a cut.', 'dono' ) }
                    </p>
                    <p className="dono-connect-p">
                        { __( 'Find them in the Stripe dashboard under Developers, API keys. Add your test keys first to try a donation safely.', 'dono' ) }
                    </p>
                </>
            ) }

            { connected && ! canCharge && (
                <Notice tone="amber" icon="⚠">
                    <strong>{ __( 'Your Stripe account cannot take payments yet.', 'dono' ) }</strong>{ ' ' }
                    { __( 'Stripe still needs some verification details (ID, bank account, business info). Finish that in your Stripe dashboard; live donations will fail until you do.', 'dono' ) }
                </Notice>
            ) }

            { connected && canCharge && (
                <Notice tone="accent" icon="✓">
                    <strong>{ __( 'You are all set.', 'dono' ) }</strong>{ ' ' }
                    { __( 'Donations are charged on your Stripe account and paid out to your bank.', 'dono' ) }
                </Notice>
            ) }

            <div className="dono-stripe-modes">
                <ModeKeys
                    mode="test"
                    saved={ !! account?.has_test }
                    hint={ account?.secret_hint_test || '' }
                    publishable={ account?.publishable_test || '' }
                    onSaved={ setStatus }
                    onRemove={ removeKeys }
                />
                <ModeKeys
                    mode="live"
                    saved={ !! account?.has_live }
                    hint={ account?.secret_hint_live || '' }
                    publishable={ account?.publishable_live || '' }
                    onSaved={ setStatus }
                    onRemove={ removeKeys }
                />
            </div>

            <div className="dono-connect-options">
                <p className="dono-connect-p">
                    { __( 'Webhooks tell Dono when a payment succeeds, fails or is refunded. Dono registers this endpoint on your account automatically when you save keys. On a local site Stripe cannot reach it, so add the endpoint yourself and paste its signing secret below.', 'dono' ) }
                </p>
                <FormRow label={ __( 'Webhook endpoint', 'dono' ) }>
                    { /* No onChange: KeyField renders read-only with a Copy button. */ }
                    <KeyField value={ status?.webhook_url || '' } />
                </FormRow>
                { s && (
                    <>
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
                    </>
                ) }
            </div>
        </Card>
        <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
        </>
    );
}
