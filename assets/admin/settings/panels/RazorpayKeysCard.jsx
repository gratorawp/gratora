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
import useCardOpen from '../../_shared/useCardOpen';

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

/**
 * One mode's Razorpay keys. The webhook secret sits here too: Razorpay signs
 * deliveries with it, and without it every incoming event is rejected.
 */
function ModeKeys( { mode, account, onSaved, onRemove } ) {
    const isTest = mode === 'test';
    const saved  = isTest ? !! account?.has_test : !! account?.has_live;
    const hint   = ( isTest ? account?.secret_hint_test : account?.secret_hint_live ) || '';
    const keyId  = ( isTest ? account?.key_id_test : account?.key_id_live ) || '';
    const hasHook = isTest ? !! account?.webhook_test : !! account?.webhook_live;

    const [ open, setOpen ]     = useState( ! saved );
    const [ id, setId ]         = useState( '' );
    const [ secret, setSecret ] = useState( '' );
    const [ hook, setHook ]     = useState( '' );
    const [ busy, setBusy ]     = useState( false );

    const label  = isTest ? __( 'Test keys', 'dono' ) : __( 'Live keys', 'dono' );
    const prefix = isTest ? 'rzp_test_' : 'rzp_live_';

    const save = () => {
        if ( ! id.trim() || ! secret.trim() ) {
            notify.error( __( 'Enter both the key id and the key secret.', 'dono' ) );
            return;
        }
        setBusy( true );
        apiFetch( {
            path:   '/dono/v1/gateways/razorpay/keys',
            method: 'POST',
            data:   {
                mode,
                key_id:         id.trim(),
                key_secret:     secret.trim(),
                webhook_secret: hook.trim(),
            },
        } )
            .then( ( res ) => {
                setId( '' );
                setSecret( '' );
                setHook( '' );
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
                    ? <Pill tone="green">{ sprintf( /* translators: %s: last 4 characters of the key secret */ __( 'Saved, ending %s', 'dono' ), hint ) }</Pill>
                    : <Pill tone="gray">{ __( 'Not set', 'dono' ) }</Pill> }
            </div>

            { saved && ! open && (
                <div className="dono-stripe-mode__saved">
                    <span className="is-mono is-muted">{ keyId }</span>
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

            { saved && ! hasHook && (
                <p className="dono-connect-p">
                    { __( 'No webhook secret saved for this mode. Renewals, refunds and failed payments from Razorpay will be rejected until you add one.', 'dono' ) }
                </p>
            ) }

            { open && (
                <>
                    <FormRow
                        label={ __( 'Key id', 'dono' ) }
                        help={ __( 'Public. Used in the browser to open Razorpay Checkout.', 'dono' ) }
                    >
                        <KeyField value={ id } onChange={ setId } placeholder={ `${ prefix }…` } />
                    </FormRow>
                    <FormRow
                        label={ __( 'Key secret', 'dono' ) }
                        help={ __( 'Stored encrypted and never shown again. Dono verifies it with Razorpay before saving.', 'dono' ) }
                    >
                        <KeyField value={ secret } onChange={ setSecret } placeholder="…" secret />
                    </FormRow>
                    <FormRow
                        label={ __( 'Webhook secret (optional)', 'dono' ) }
                        help={ __( 'The secret you set on the webhook in the Razorpay dashboard. Dono cannot verify incoming events without it.', 'dono' ) }
                    >
                        <KeyField value={ hook } onChange={ setHook } placeholder="…" secret />
                    </FormRow>
                    <div className="dono-stripe-mode__actions">
                        <Btn variant="primary" size="sm" onClick={ save } isBusy={ busy } disabled={ busy }>
                            { __( 'Save and verify', 'dono' ) }
                        </Btn>
                        { saved && (
                            <Btn variant="ghost" size="sm" onClick={ () => { setOpen( false ); setId( '' ); setSecret( '' ); setHook( '' ); } }>
                                { __( 'Cancel', 'dono' ) }
                            </Btn>
                        ) }
                    </div>
                </>
            ) }
        </div>
    );
}

export default function RazorpayKeysCard() {
    const [ status, setStatus ]       = useState( null );
    const [ loading, setLoading ]     = useState( true );
    const [ loadError, setLoadError ] = useState( false );
    const [ confirm, setConfirm ]     = useState( null );

    const load = useCallback( () => {
        setLoading( true );
        setLoadError( false );
        apiFetch( { path: '/dono/v1/gateways/razorpay/status' } )
            .then( ( r ) => setStatus( r ) )
            .catch( () => { setStatus( null ); setLoadError( true ); } )
            .finally( () => setLoading( false ) );
    }, [] );

    useEffect( () => { load(); }, [ load ] );

    const removeKeys = useCallback( ( mode ) => {
        const all = mode === 'all';
        setConfirm( {
            title: __( 'Remove Razorpay keys', 'dono' ),
            message: all
                ? __( 'Remove both key pairs? Razorpay donations will stop until you add keys again.', 'dono' )
                : __( 'Remove this key pair? Razorpay donations in this mode will stop until you add keys again.', 'dono' ),
            confirmLabel: __( 'Remove', 'dono' ),
            destructive: true,
            onConfirm: async () => {
                apiFetch( { path: `/dono/v1/gateways/razorpay/keys?mode=${ mode }`, method: 'DELETE' } )
                    .then( ( res ) => setStatus( res ) )
                    .catch( ( err ) => notify.error( err?.message || __( 'Could not remove the keys.', 'dono' ) ) );
            },
        } );
    }, [] );

    const [ open, setOpen ] = useCardOpen( loadError );

    const head = {
        leading:     <BrandMark letter="R" variant="razorpay" />,
        title:       __( 'Razorpay', 'dono' ),
        collapsible: true,
        open,
        onToggle:    setOpen,
    };
    const sub = __( 'UPI, cards, netbanking and wallets, in India', 'dono' );

    if ( loading ) {
        return (
            <Card { ...head } sub={ sub } meta={ <Pill tone="gray">{ __( 'Checking…', 'dono' ) }</Pill> }>
                <p className="dono-connect-p">{ __( 'Loading Razorpay status…', 'dono' ) }</p>
            </Card>
        );
    }

    if ( loadError ) {
        return (
            <Card { ...head } sub={ sub } meta={ <Pill tone="amber">{ __( 'Unavailable', 'dono' ) }</Pill> }>
                <Notice tone="amber" icon="!">
                    <strong>{ __( 'Could not check your Razorpay setup.', 'dono' ) }</strong>{ ' ' }
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

    return (
        <>
        <Card
            { ...head }
            sub={ sub }
            meta={ connected
                ? <Pill tone="green">{ __( 'Ready', 'dono' ) }</Pill>
                : <Pill tone="gray">{ __( 'Not set up', 'dono' ) }</Pill> }
        >
            { ! connected && (
                <>
                    <p className="dono-connect-p">
                        { __( 'Add the API keys from your own Razorpay account. Donations settle straight into your bank account, and Dono never takes a cut.', 'dono' ) }
                    </p>
                    <p className="dono-connect-p">
                        { __( 'Find them in the Razorpay dashboard under Account and Settings, API Keys. Add your test keys first to try a donation safely.', 'dono' ) }
                    </p>
                </>
            ) }

            { connected && (
                <Notice tone="accent" icon="✓">
                    <strong>{ __( 'You are all set.', 'dono' ) }</strong>{ ' ' }
                    { __( 'Razorpay Checkout will open on your donation forms for donations in rupees.', 'dono' ) }
                </Notice>
            ) }

            <div className="dono-stripe-modes">
                <ModeKeys mode="test" account={ account } onSaved={ setStatus } onRemove={ removeKeys } />
                <ModeKeys mode="live" account={ account } onSaved={ setStatus } onRemove={ removeKeys } />
            </div>

            <div className="dono-connect-options">
                <p className="dono-connect-p">
                    { __( 'Add this URL as a webhook in the Razorpay dashboard, subscribe it to the payment, refund and subscription events, then paste the secret you set there above. Without it Dono cannot tell a real event from a forged one, so every delivery is rejected.', 'dono' ) }
                </p>
                <FormRow label={ __( 'Webhook endpoint', 'dono' ) }>
                    { /* No onChange: KeyField renders read-only with a Copy button. */ }
                    <KeyField value={ status?.webhook_url || '' } />
                </FormRow>
            </div>
        </Card>
        <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
        </>
    );
}
