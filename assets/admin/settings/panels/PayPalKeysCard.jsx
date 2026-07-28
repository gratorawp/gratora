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

/**
 * One mode's PayPal REST app credentials. The webhook id sits here too because
 * PayPal cannot verify a webhook signature without it.
 */
function ModeKeys( { mode, account, onSaved, onRemove } ) {
    const isTest = mode === 'test';
    const saved  = isTest ? !! account?.has_test : !! account?.has_live;
    const hint   = ( isTest ? account?.secret_hint_test : account?.secret_hint_live ) || '';
    const clientId = ( isTest ? account?.client_id_test : account?.client_id_live ) || '';
    const hasHook  = isTest ? !! account?.webhook_test : !! account?.webhook_live;

    const [ open, setOpen ] = useState( ! saved );
    const [ id, setId ]     = useState( '' );
    const [ secret, setSecret ] = useState( '' );
    const [ hook, setHook ] = useState( '' );
    const [ busy, setBusy ] = useState( false );

    const label = isTest ? __( 'Sandbox credentials', 'dono' ) : __( 'Live credentials', 'dono' );

    const save = () => {
        if ( ! id.trim() || ! secret.trim() ) {
            notify.error( __( 'Enter both the client id and the secret.', 'dono' ) );
            return;
        }
        setBusy( true );
        apiFetch( {
            path:   '/dono/v1/gateways/paypal/keys',
            method: 'POST',
            data:   {
                mode,
                client_id:     id.trim(),
                client_secret: secret.trim(),
                webhook_id:    hook.trim(),
            },
        } )
            .then( ( res ) => {
                setId( '' );
                setSecret( '' );
                setHook( '' );
                setOpen( false );
                notify.success(
                    isTest
                        ? __( 'Sandbox credentials verified and saved.', 'dono' )
                        : __( 'Live credentials verified and saved.', 'dono' )
                );
                onSaved( res );
            } )
            .catch( ( err ) => notify.error( err?.message || __( 'Could not verify those credentials.', 'dono' ) ) )
            .finally( () => setBusy( false ) );
    };

    return (
        <div className="dono-stripe-mode">
            <div className="dono-stripe-mode__head">
                <strong>{ label }</strong>
                { saved
                    ? <Pill tone="green">{ sprintf( /* translators: %s: last 4 characters of the secret */ __( 'Saved, ending %s', 'dono' ), hint ) }</Pill>
                    : <Pill tone="gray">{ __( 'Not set', 'dono' ) }</Pill> }
            </div>

            { saved && ! open && (
                <div className="dono-stripe-mode__saved">
                    <span className="is-mono is-muted">{ clientId }</span>
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
                    { __( 'No webhook id saved for this mode. Refunds and renewals from PayPal will be rejected until you add one.', 'dono' ) }
                </p>
            ) }

            { open && (
                <>
                    <FormRow
                        label={ __( 'Client id', 'dono' ) }
                        help={ __( 'Public. Used in the browser to show the PayPal buttons.', 'dono' ) }
                    >
                        <KeyField value={ id } onChange={ setId } placeholder="AeA1QIZ..." />
                    </FormRow>
                    <FormRow
                        label={ __( 'Secret', 'dono' ) }
                        help={ __( 'Stored encrypted and never shown again. Dono verifies it with PayPal before saving.', 'dono' ) }
                    >
                        <KeyField value={ secret } onChange={ setSecret } placeholder="EO422dn3..." secret />
                    </FormRow>
                    <FormRow
                        label={ __( 'Webhook id (optional)', 'dono' ) }
                        help={ __( 'From the webhook you created in the PayPal dashboard. PayPal cannot verify incoming events without it.', 'dono' ) }
                    >
                        <KeyField value={ hook } onChange={ setHook } placeholder="WH-..." />
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

export default function PayPalKeysCard() {
    const [ status, setStatus ]       = useState( null );
    const [ loading, setLoading ]     = useState( true );
    const [ loadError, setLoadError ] = useState( false );
    const [ confirm, setConfirm ]     = useState( null );

    const load = useCallback( () => {
        setLoading( true );
        setLoadError( false );
        apiFetch( { path: '/dono/v1/gateways/paypal/status' } )
            .then( ( r ) => setStatus( r ) )
            .catch( () => { setStatus( null ); setLoadError( true ); } )
            .finally( () => setLoading( false ) );
    }, [] );

    useEffect( () => { load(); }, [ load ] );

    const removeKeys = useCallback( ( mode ) => {
        const all = mode === 'all';
        setConfirm( {
            title: __( 'Remove PayPal credentials', 'dono' ),
            message: all
                ? __( 'Remove both credential sets? PayPal donations will stop until you add them again.', 'dono' )
                : __( 'Remove these credentials? PayPal donations in this mode will stop until you add them again.', 'dono' ),
            confirmLabel: __( 'Remove', 'dono' ),
            destructive: true,
            onConfirm: async () => {
                apiFetch( { path: `/dono/v1/gateways/paypal/keys?mode=${ mode }`, method: 'DELETE' } )
                    .then( ( res ) => setStatus( res ) )
                    .catch( ( err ) => notify.error( err?.message || __( 'Could not remove the credentials.', 'dono' ) ) );
            },
        } );
    }, [] );

    const head = {
        leading: <BrandMark letter="P" variant="paypal" />,
        title:   __( 'PayPal', 'dono' ),
    };
    const sub = __( 'PayPal, Venmo, Pay Later and cards', 'dono' );

    if ( loading ) {
        return (
            <Card { ...head } sub={ sub } meta={ <Pill tone="gray">{ __( 'Checking…', 'dono' ) }</Pill> }>
                <p className="dono-connect-p">{ __( 'Loading PayPal status…', 'dono' ) }</p>
            </Card>
        );
    }

    if ( loadError ) {
        return (
            <Card { ...head } sub={ sub } meta={ <Pill tone="amber">{ __( 'Unavailable', 'dono' ) }</Pill> }>
                <Notice tone="amber" icon="!">
                    <strong>{ __( 'Could not check your PayPal setup.', 'dono' ) }</strong>{ ' ' }
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
            sub={ connected && account?.email ? account.email : sub }
            meta={ connected
                ? <Pill tone="green">{ __( 'Ready', 'dono' ) }</Pill>
                : <Pill tone="gray">{ __( 'Not set up', 'dono' ) }</Pill> }
        >
            { ! connected && (
                <>
                    <p className="dono-connect-p">
                        { __( 'Add the credentials from your own PayPal REST app. Donations are paid straight into your PayPal account, and Dono never takes a cut.', 'dono' ) }
                    </p>
                    <p className="dono-connect-p">
                        { __( 'Create an app at developer.paypal.com under Apps and Credentials. Sandbox and live are separate apps, so each needs its own credentials here.', 'dono' ) }
                    </p>
                </>
            ) }

            { connected && (
                <Notice tone="accent" icon="✓">
                    <strong>{ __( 'You are all set.', 'dono' ) }</strong>{ ' ' }
                    { __( 'PayPal buttons will appear on your donation forms.', 'dono' ) }
                </Notice>
            ) }

            <div className="dono-stripe-modes">
                <ModeKeys mode="test" account={ account } onSaved={ setStatus } onRemove={ removeKeys } />
                <ModeKeys mode="live" account={ account } onSaved={ setStatus } onRemove={ removeKeys } />
            </div>

            <div className="dono-connect-options">
                <p className="dono-connect-p">
                    { __( 'Add this URL as a webhook in your PayPal app, subscribe it to the payment and subscription events, then paste the webhook id above. PayPal verifies every event against that id.', 'dono' ) }
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
