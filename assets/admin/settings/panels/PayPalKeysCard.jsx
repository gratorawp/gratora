import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

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
        <span className={ `fundkit-pill fundkit-pill--${ tone }` }>
            <span className="fundkit-pill__dot" />
            { children }
        </span>
    );
}

function Notice( { tone, icon, children } ) {
    return (
        <div className={ `fundkit-connect-notice fundkit-connect-notice--${ tone }` }>
            <span className="fundkit-connect-notice__icon" aria-hidden="true">{ icon }</span>
            <div>{ children }</div>
        </div>
    );
}

/**
 * One mode's PayPal REST app credentials. The webhook id sits here too because
 * PayPal cannot verify a webhook signature without it.
 */
function ModeKeys( { mode, account, onSaved, onRemove, askConfirm } ) {
    const isTest = mode === 'test';
    const saved  = isTest ? !! account?.has_test : !! account?.has_live;
    const clientId = ( isTest ? account?.client_id_test : account?.client_id_live ) || '';
    const hasHook  = isTest ? !! account?.webhook_test : !! account?.webhook_live;

    const [ open, setOpen ] = useState( ! saved );
    const [ hookOpen, setHookOpen ] = useState( false );
    const [ id, setId ]     = useState( '' );
    const [ secret, setSecret ] = useState( '' );
    const [ hook, setHook ] = useState( '' );
    const [ busy, setBusy ] = useState( false );

    const label = isTest ? __( 'Sandbox credentials', 'fundraising-toolkit' ) : __( 'Live credentials', 'fundraising-toolkit' );

    const post = ( data ) => {
        setBusy( true );
        return apiFetch( {
            path:   '/fundkit/v1/gateways/paypal/keys',
            method: 'POST',
            data:   { mode, ...data },
        } )
            .then( ( res ) => {
                onSaved( res );
                return res;
            } )
            .finally( () => setBusy( false ) );
    };

    const save = () => {
        if ( ! id.trim() || ! secret.trim() ) {
            notify.error( __( 'Enter both the client id and the secret.', 'fundraising-toolkit' ) );
            return;
        }
        post( {
            client_id:     id.trim(),
            client_secret: secret.trim(),
            webhook_id:    hook.trim(),
        } )
            .then( ( res ) => {
                setId( '' );
                setSecret( '' );
                setOpen( false );
                notify.success(
                    isTest
                        ? __( 'Sandbox credentials verified and saved.', 'fundraising-toolkit' )
                        : __( 'Live credentials verified and saved.', 'fundraising-toolkit' )
                );

                /* The credentials went in without the webhook id. Hold the
                   toast open and keep the typed id in the field so it can go
                   straight back once the reason is dealt with. */
                if ( res?.webhook_warning ) {
                    setHookOpen( true );
                    notify.warning( res.webhook_warning, { duration: 0 } );
                    return;
                }
                setHook( '' );
                setHookOpen( false );
            } )
            .catch( ( err ) => notify.error( err?.message || __( 'Could not verify those credentials.', 'fundraising-toolkit' ) ) );
    };

    const saveHook = () => {
        if ( ! hook.trim() ) {
            notify.error( __( 'Enter the webhook id from your PayPal app.', 'fundraising-toolkit' ) );
            return;
        }
        post( { webhook_id: hook.trim() } )
            .then( () => {
                setHook( '' );
                setHookOpen( false );
                notify.success( __( 'Webhook id checked with PayPal and saved.', 'fundraising-toolkit' ) );
            } )
            .catch( ( err ) => notify.error( err?.message || __( 'Could not check that webhook id with PayPal.', 'fundraising-toolkit' ) ) );
    };

    const removeHook = () => {
        askConfirm( {
            title: __( 'Remove webhook id', 'fundraising-toolkit' ),
            message: __( 'Remove the saved webhook id? The client id and secret stay on file, but PayPal notifications for this mode will be rejected until you add another one.', 'fundraising-toolkit' ),
            confirmLabel: __( 'Remove', 'fundraising-toolkit' ),
            destructive: true,
            onConfirm: () => {
                setBusy( true );
                return apiFetch( {
                    path:   `/fundkit/v1/gateways/paypal/webhook?mode=${ mode }`,
                    method: 'DELETE',
                } )
                    .then( ( res ) => {
                        onSaved( res );
                        notify.success( __( 'Webhook id removed.', 'fundraising-toolkit' ) );
                    } )
                    .catch( ( err ) => notify.error( err?.message || __( 'Could not remove the webhook id.', 'fundraising-toolkit' ) ) )
                    .finally( () => setBusy( false ) );
            },
        } );
    };

    return (
        <div className="fundkit-stripe-mode">
            <div className="fundkit-stripe-mode__head">
                <strong>{ label }</strong>
                { saved
                    ? <Pill tone="green">{ __( 'Saved', 'fundraising-toolkit' ) }</Pill>
                    : <Pill tone="gray">{ __( 'Not set', 'fundraising-toolkit' ) }</Pill> }
            </div>

            { saved && ! hasHook && (
                <p className="fundkit-connect-p">
                    { __( 'No webhook id saved for this mode. Every PayPal notification will be rejected until you add one, so donations PayPal settles later will stay unpaid, and refunds, disputes and renewals will not reach this site.', 'fundraising-toolkit' ) }
                </p>
            ) }

            { saved && ! open && (
                <>
                    <div className="fundkit-stripe-mode__saved">
                        <span className="is-mono is-muted">{ clientId }</span>
                        <div className="fundkit-stripe-mode__actions">
                            <Btn variant="secondary" size="sm" onClick={ () => { setOpen( true ); setHookOpen( false ); } }>
                                { __( 'Replace', 'fundraising-toolkit' ) }
                            </Btn>
                            <Btn variant="ghost" size="sm" onClick={ () => onRemove( mode ) }>
                                { __( 'Remove', 'fundraising-toolkit' ) }
                            </Btn>
                        </div>
                    </div>

                    { hookOpen ? (
                        <>
                            <FormRow
                                label={ __( 'Webhook id', 'fundraising-toolkit' ) }
                                help={ __( 'From the webhook you created in the PayPal dashboard. Fundraising Toolkit checks it against this app, and the credentials on file stay as they are.', 'fundraising-toolkit' ) }
                            >
                                <KeyField value={ hook } onChange={ setHook } placeholder="5ML12345AB678901C" />
                            </FormRow>
                            <div className="fundkit-stripe-mode__actions">
                                <Btn variant="primary" size="sm" onClick={ saveHook } isBusy={ busy } disabled={ busy }>
                                    { __( 'Save webhook id', 'fundraising-toolkit' ) }
                                </Btn>
                                <Btn variant="ghost" size="sm" onClick={ () => { setHookOpen( false ); setHook( '' ); } }>
                                    { __( 'Cancel', 'fundraising-toolkit' ) }
                                </Btn>
                            </div>
                        </>
                    ) : (
                        <div className="fundkit-stripe-mode__saved" style={ { marginTop: 12 } }>
                            <span className="is-muted">
                                { hasHook ? __( 'Webhook id checked with PayPal and saved', 'fundraising-toolkit' ) : __( 'Webhook id not set', 'fundraising-toolkit' ) }
                            </span>
                            <div className="fundkit-stripe-mode__actions">
                                <Btn variant="secondary" size="sm" onClick={ () => setHookOpen( true ) }>
                                    { hasHook ? __( 'Replace webhook id', 'fundraising-toolkit' ) : __( 'Add webhook id', 'fundraising-toolkit' ) }
                                </Btn>
                                { hasHook && (
                                    <Btn variant="ghost" size="sm" onClick={ removeHook } disabled={ busy }>
                                        { __( 'Remove', 'fundraising-toolkit' ) }
                                    </Btn>
                                ) }
                            </div>
                        </div>
                    ) }
                </>
            ) }

            { open && (
                <>
                    <FormRow
                        label={ __( 'Client id', 'fundraising-toolkit' ) }
                        help={ __( 'Public. Used in the browser to show the PayPal buttons.', 'fundraising-toolkit' ) }
                    >
                        <KeyField value={ id } onChange={ setId } placeholder="AeA1QIZ..." />
                    </FormRow>
                    <FormRow
                        label={ __( 'Secret', 'fundraising-toolkit' ) }
                        help={ __( 'Stored encrypted and never shown again. Fundraising Toolkit verifies it with PayPal before saving.', 'fundraising-toolkit' ) }
                    >
                        <KeyField value={ secret } onChange={ setSecret } placeholder="EO422dn3..." secret />
                    </FormRow>
                    <FormRow
                        label={ __( 'Webhook id', 'fundraising-toolkit' ) }
                        help={ __( 'From the webhook you created in the PayPal dashboard. Without it PayPal cannot prove an event came from PayPal, so every notification is rejected and donations PayPal settles after checkout stay unpaid. You can add it after these credentials, but PayPal will not work properly until you do. Fundraising Toolkit checks it against your app and only saves an id PayPal confirms.', 'fundraising-toolkit' ) }
                    >
                        { /* WH-... is the format of a PayPal event id, not of a
                             webhook id, and the two sit next to each other in
                             PayPal's dashboard. */ }
                        <KeyField value={ hook } onChange={ setHook } placeholder="5ML12345AB678901C" />
                    </FormRow>
                    <div className="fundkit-stripe-mode__actions">
                        <Btn variant="primary" size="sm" onClick={ save } isBusy={ busy } disabled={ busy }>
                            { __( 'Save and verify', 'fundraising-toolkit' ) }
                        </Btn>
                        { saved && (
                            <Btn variant="ghost" size="sm" onClick={ () => { setOpen( false ); setId( '' ); setSecret( '' ); setHook( '' ); } }>
                                { __( 'Cancel', 'fundraising-toolkit' ) }
                            </Btn>
                        ) }
                    </div>
                </>
            ) }
        </div>
    );
}

export default function PayPalKeysCard( { s } ) {
    const [ status, setStatus ]       = useState( null );
    const [ loading, setLoading ]     = useState( true );
    const [ loadError, setLoadError ] = useState( false );
    const [ confirm, setConfirm ]     = useState( null );

    const load = useCallback( () => {
        setLoading( true );
        setLoadError( false );
        apiFetch( { path: '/fundkit/v1/gateways/paypal/status' } )
            .then( ( r ) => setStatus( r ) )
            .catch( () => { setStatus( null ); setLoadError( true ); } )
            .finally( () => setLoading( false ) );
    }, [] );

    useEffect( () => { load(); }, [ load ] );

    const removeKeys = useCallback( ( mode ) => {
        const all = mode === 'all';
        setConfirm( {
            title: __( 'Remove PayPal credentials', 'fundraising-toolkit' ),
            message: all
                ? __( 'Remove both credential sets? PayPal donations will stop until you add them again.', 'fundraising-toolkit' )
                : __( 'Remove these credentials? PayPal donations in this mode will stop until you add them again.', 'fundraising-toolkit' ),
            confirmLabel: __( 'Remove', 'fundraising-toolkit' ),
            destructive: true,
            onConfirm: async () => {
                apiFetch( { path: `/fundkit/v1/gateways/paypal/keys?mode=${ mode }`, method: 'DELETE' } )
                    .then( ( res ) => setStatus( res ) )
                    .catch( ( err ) => notify.error( err?.message || __( 'Could not remove the credentials.', 'fundraising-toolkit' ) ) );
            },
        } );
    }, [] );

    const [ open, setOpen ] = useCardOpen( loadError, 'payments', 'paypal' );

    const head = {
        leading:     <BrandMark letter="P" variant="paypal" />,
        title:       __( 'PayPal', 'fundraising-toolkit' ),
        collapsible: true,
        open,
        onToggle:    setOpen,
    };
    const sub = __( 'PayPal, Venmo, Pay Later and cards', 'fundraising-toolkit' );

    if ( loading ) {
        return (
            <Card { ...head } sub={ sub } meta={ <Pill tone="gray">{ __( 'Checking…', 'fundraising-toolkit' ) }</Pill> }>
                <p className="fundkit-connect-p">{ __( 'Loading PayPal status…', 'fundraising-toolkit' ) }</p>
            </Card>
        );
    }

    if ( loadError ) {
        return (
            <Card { ...head } sub={ sub } meta={ <Pill tone="amber">{ __( 'Unavailable', 'fundraising-toolkit' ) }</Pill> }>
                <Notice tone="amber" icon="!">
                    <strong>{ __( 'Could not check your PayPal setup.', 'fundraising-toolkit' ) }</strong>{ ' ' }
                    { __( 'Something went wrong loading the status. Please try again.', 'fundraising-toolkit' ) }
                </Notice>
                <div style={ { marginTop: 18 } }>
                    <Btn variant="primary" onClick={ load }>{ __( 'Retry', 'fundraising-toolkit' ) }</Btn>
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
                ? <Pill tone="green">{ __( 'Ready', 'fundraising-toolkit' ) }</Pill>
                : <Pill tone="gray">{ __( 'Not set up', 'fundraising-toolkit' ) }</Pill> }
        >
            { ! connected && (
                <>
                    <p className="fundkit-connect-p">
                        { __( 'Add the credentials from your own PayPal REST app. Donations are paid straight into your PayPal account, and Fundraising Toolkit never takes a cut.', 'fundraising-toolkit' ) }
                    </p>
                    <p className="fundkit-connect-p">
                        { __( 'Create an app at developer.paypal.com under Apps and Credentials. Sandbox and live are separate apps, so each needs its own credentials here.', 'fundraising-toolkit' ) }
                    </p>
                </>
            ) }

            <ToggleRow
                title={ __( 'Enable the PayPal gateway', 'fundraising-toolkit' ) }
                sub={ connected
                    ? __( 'Your credentials stay on file while it is off.', 'fundraising-toolkit' )
                    : __( 'Available once your credentials are saved.', 'fundraising-toolkit' ) }
                checked={ connected && !! s.value( 'paypal.enabled', true ) }
                onChange={ s.setValue( 'paypal.enabled' ) }
                disabled={ ! connected }
            />

            { connected && (
                <Notice tone="accent" icon="✓">
                    <strong>{ __( 'You are all set.', 'fundraising-toolkit' ) }</strong>{ ' ' }
                    { __( 'PayPal buttons will appear on your donation forms.', 'fundraising-toolkit' ) }
                </Notice>
            ) }

            <div className="fundkit-stripe-modes">
                <ModeKeys mode="test" account={ account } onSaved={ setStatus } onRemove={ removeKeys } askConfirm={ setConfirm } />
                <ModeKeys mode="live" account={ account } onSaved={ setStatus } onRemove={ removeKeys } askConfirm={ setConfirm } />
            </div>

            <div className="fundkit-connect-options">
                <p className="fundkit-connect-p">
                    { __( 'Add this URL as a webhook in your PayPal app, subscribe it to the payment and subscription events, then paste the webhook id above. PayPal verifies every event against that id.', 'fundraising-toolkit' ) }
                </p>
                <FormRow label={ __( 'Webhook endpoint', 'fundraising-toolkit' ) }>
                    { /* No onChange: KeyField renders read-only with a Copy button. */ }
                    <KeyField value={ status?.webhook_url || '' } />
                </FormRow>
            </div>
        </Card>
        <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
        </>
    );
}
