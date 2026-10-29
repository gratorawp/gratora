import apiFetch from '@wordpress/api-fetch';
import { createInterpolateElement, useEffect, useState, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { ChevronDown } from 'lucide-react';

import Card from '../../_shared/components/Card';
import { ToggleRow } from '../../_shared/components/Switch';
import Btn from '../../_shared/components/Btn';
import BrandMark from '../../_shared/components/BrandMark';
import FormRow from '../../_shared/components/FormRow';
import KeyField from '../../_shared/components/KeyField';
import NewTabLink from '../../_shared/components/NewTabLink';
import ConfirmDialog from '../../_shared/components/ConfirmDialog';
import Notice from '../../_shared/components/Notice';
import { notify } from '../../_shared/notify';
import useCardOpen from '../../_shared/useCardOpen';

const PAYPAL_BUSINESS  = 'https://www.paypal.com/business/open-business-account';
const PAYPAL_DASHBOARD = 'https://developer.paypal.com/dashboard/';

function FirstSteps( { onOffline } ) {
    return (
        <div className="gratora-stripe-start">
            <p className="gratora-connect-p">
                { __( 'To take donations through PayPal you need a PayPal Business account.', 'gratora-donation-platform' ) }
            </p>
            <ol className="gratora-stripe-start__steps">
                <li>
                    { createInterpolateElement(
                        __( 'No PayPal Business account yet? <a>Open a PayPal Business account</a>. PayPal asks you to verify it before it gives you live credentials.', 'gratora-donation-platform' ),
                        { a: <NewTabLink href={ PAYPAL_BUSINESS } /> }
                    ) }
                </li>
                <li>
                    { createInterpolateElement(
                        __( '<a>Open PayPal\'s developer dashboard</a>, log in with your Business account and choose Apps & Credentials. Switch to Live and press Create App.', 'gratora-donation-platform' ),
                        { a: <NewTabLink href={ PAYPAL_DASHBOARD } /> }
                    ) }
                </li>
                <li>{ __( 'Copy the app\'s Client ID and secret, paste them under Live credentials below and press Save and verify.', 'gratora-donation-platform' ) }</li>
            </ol>
            { onOffline && (
                <div className="gratora-stripe-start__offline">
                    <p>{ __( 'No PayPal account yet? Take checks and bank transfers today, and add PayPal later.', 'gratora-donation-platform' ) }</p>
                    <Btn variant="secondary" size="sm" onClick={ onOffline }>
                        { __( 'Set up checks and bank transfers', 'gratora-donation-platform' ) }
                    </Btn>
                </div>
            ) }
        </div>
    );
}

function WebhookSettings( { url, needed } ) {
    const [ open, setOpen ] = useState( needed );

    useEffect( () => {
        if ( needed ) setOpen( true );
    }, [ needed ] );

    return (
        <div className="gratora-connect-options gratora-stripe-webhooks">
            <button
                type="button"
                className="gratora-stripe-webhooks__toggle"
                aria-expanded={ open }
                aria-controls="gratora-paypal-webhooks"
                onClick={ () => setOpen( ! open ) }
            >
                <ChevronDown size={ 16 } strokeWidth={ 2 } aria-hidden="true" />
                { __( 'Webhook settings (needed for recurring donations and refunds made in PayPal)', 'gratora-donation-platform' ) }
            </button>
            { open && (
                <div id="gratora-paypal-webhooks">
                    <p className="gratora-connect-p">
                        { __( 'One-time donations work without a webhook. With one, Gratora also offers recurring donations through PayPal, and hears when a payment PayPal held is cleared or a donation is refunded in PayPal. Sandbox and live apps each need their own.', 'gratora-donation-platform' ) }
                    </p>
                    <ol className="gratora-stripe-start__steps">
                        <li>{ __( 'In PayPal\'s developer dashboard, under Apps & Credentials, open the app whose Client ID you saved and press Add Webhook.', 'gratora-donation-platform' ) }</li>
                        <li>{ __( 'Paste the webhook endpoint below as its URL, select all events in the Event types list and press Save.', 'gratora-donation-platform' ) }</li>
                        <li>{ __( 'Copy the webhook\'s ID, press Add webhook id under the saved credentials above and paste it.', 'gratora-donation-platform' ) }</li>
                    </ol>
                    <FormRow label={ __( 'Webhook endpoint', 'gratora-donation-platform' ) }>
                        { /* No onChange: KeyField renders read-only with a Copy button. */ }
                        <KeyField value={ url } />
                    </FormRow>
                </div>
            ) }
        </div>
    );
}

function Pill( { tone, children } ) {
    return (
        <span className={ `gratora-pill gratora-pill--${ tone }` }>
            <span className="gratora-pill__dot" />
            { children }
        </span>
    );
}

/**
 * One mode's PayPal REST app credentials. The webhook id sits here too because
 * PayPal cannot verify a webhook signature without it.
 */
function ModeKeys( { mode, account, onSaved, onRemove, askConfirm, optional = false } ) {
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

    let label = isTest ? __( 'Sandbox credentials', 'gratora-donation-platform' ) : __( 'Live credentials', 'gratora-donation-platform' );
    if ( optional ) label = __( 'Sandbox credentials (optional)', 'gratora-donation-platform' );

    const post = ( data ) => {
        setBusy( true );
        return apiFetch( {
            path:   '/gratora/v1/gateways/paypal/keys',
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
            notify.error( __( 'Enter both the client id and the secret.', 'gratora-donation-platform' ) );
            return;
        }
        post( {
            client_id:     id.trim(),
            client_secret: secret.trim(),
        } )
            .then( () => {
                setId( '' );
                setSecret( '' );
                setOpen( false );
                notify.success(
                    isTest
                        ? __( 'Sandbox credentials verified and saved.', 'gratora-donation-platform' )
                        : __( 'Live credentials verified and saved.', 'gratora-donation-platform' )
                );
            } )
            .catch( ( err ) => notify.error( err?.message || __( 'Could not verify those credentials.', 'gratora-donation-platform' ) ) );
    };

    const saveHook = () => {
        if ( ! hook.trim() ) {
            notify.error( __( 'Enter the webhook id from your PayPal app.', 'gratora-donation-platform' ) );
            return;
        }
        post( { webhook_id: hook.trim() } )
            .then( () => {
                setHook( '' );
                setHookOpen( false );
                notify.success( __( 'Webhook id checked with PayPal and saved.', 'gratora-donation-platform' ) );
            } )
            .catch( ( err ) => notify.error( err?.message || __( 'Could not check that webhook id with PayPal.', 'gratora-donation-platform' ) ) );
    };

    const removeHook = () => {
        askConfirm( {
            title: __( 'Remove webhook id', 'gratora-donation-platform' ),
            message: __( 'Remove the saved webhook id? The client id and secret stay on file, but PayPal notifications for this mode will be rejected until you add another one.', 'gratora-donation-platform' ),
            confirmLabel: __( 'Remove', 'gratora-donation-platform' ),
            destructive: true,
            onConfirm: () => {
                setBusy( true );
                return apiFetch( {
                    path:   `/gratora/v1/gateways/paypal/webhook?mode=${ mode }`,
                    method: 'DELETE',
                } )
                    .then( ( res ) => {
                        onSaved( res );
                        notify.success( __( 'Webhook id removed.', 'gratora-donation-platform' ) );
                    } )
                    .catch( ( err ) => notify.error( err?.message || __( 'Could not remove the webhook id.', 'gratora-donation-platform' ) ) )
                    .finally( () => setBusy( false ) );
            },
        } );
    };

    return (
        <div className="gratora-stripe-mode">
            <div className="gratora-stripe-mode__head">
                <strong>{ label }</strong>
                { saved
                    ? <Pill tone="green">{ __( 'Saved', 'gratora-donation-platform' ) }</Pill>
                    : <Pill tone="gray">{ __( 'Not set', 'gratora-donation-platform' ) }</Pill> }
            </div>

            { saved && ! hasHook && (
                <p className="gratora-connect-p">
                    { __( 'No webhook id saved for this mode. Every PayPal notification will be rejected until you add one, so donations PayPal settles later will stay unpaid, and refunds, disputes and renewals will not reach this site.', 'gratora-donation-platform' ) }
                </p>
            ) }

            { saved && ! open && (
                <>
                    <div className="gratora-stripe-mode__saved">
                        <span className="is-mono is-muted">{ clientId }</span>
                        <div className="gratora-stripe-mode__actions">
                            <Btn variant="secondary" size="sm" onClick={ () => { setOpen( true ); setHookOpen( false ); } }>
                                { __( 'Replace', 'gratora-donation-platform' ) }
                            </Btn>
                            <Btn variant="ghost" size="sm" onClick={ () => onRemove( mode ) }>
                                { __( 'Remove', 'gratora-donation-platform' ) }
                            </Btn>
                        </div>
                    </div>

                    { hookOpen ? (
                        <>
                            <FormRow
                                label={ __( 'Webhook id', 'gratora-donation-platform' ) }
                                help={ __( 'From the webhook you created in the PayPal dashboard. Gratora checks it against this app, and the credentials on file stay as they are.', 'gratora-donation-platform' ) }
                            >
                                { /* WH-... is the format of a PayPal event id, not of a
                                     webhook id, and the two sit next to each other in
                                     PayPal's dashboard. */ }
                                <KeyField value={ hook } onChange={ setHook } placeholder="5ML12345AB678901C" />
                            </FormRow>
                            <div className="gratora-stripe-mode__actions">
                                <Btn variant="primary" size="sm" onClick={ saveHook } isBusy={ busy } disabled={ busy }>
                                    { __( 'Save webhook id', 'gratora-donation-platform' ) }
                                </Btn>
                                <Btn variant="ghost" size="sm" onClick={ () => { setHookOpen( false ); setHook( '' ); } }>
                                    { __( 'Cancel', 'gratora-donation-platform' ) }
                                </Btn>
                            </div>
                        </>
                    ) : (
                        <div className="gratora-stripe-mode__saved" style={ { marginTop: 12 } }>
                            <span className="is-muted">
                                { hasHook ? __( 'Webhook id checked with PayPal and saved', 'gratora-donation-platform' ) : __( 'Webhook id not set', 'gratora-donation-platform' ) }
                            </span>
                            <div className="gratora-stripe-mode__actions">
                                <Btn variant="secondary" size="sm" onClick={ () => setHookOpen( true ) }>
                                    { hasHook ? __( 'Replace webhook id', 'gratora-donation-platform' ) : __( 'Add webhook id', 'gratora-donation-platform' ) }
                                </Btn>
                                { hasHook && (
                                    <Btn variant="ghost" size="sm" onClick={ removeHook } disabled={ busy }>
                                        { __( 'Remove', 'gratora-donation-platform' ) }
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
                        label={ __( 'Client ID', 'gratora-donation-platform' ) }
                        help={ __( 'Public. Used in the browser to show the PayPal buttons.', 'gratora-donation-platform' ) }
                    >
                        <KeyField value={ id } onChange={ setId } placeholder="AeA1QIZ..." />
                    </FormRow>
                    <FormRow
                        label={ __( 'Secret', 'gratora-donation-platform' ) }
                        help={ __( 'Stored encrypted and never shown again. Gratora verifies it with PayPal before saving.', 'gratora-donation-platform' ) }
                    >
                        <KeyField value={ secret } onChange={ setSecret } placeholder="EO422dn3..." secret />
                    </FormRow>
                    <div className="gratora-stripe-mode__actions">
                        <Btn variant="primary" size="sm" onClick={ save } isBusy={ busy } disabled={ busy }>
                            { __( 'Save and verify', 'gratora-donation-platform' ) }
                        </Btn>
                        { saved && (
                            <Btn variant="ghost" size="sm" onClick={ () => { setOpen( false ); setId( '' ); setSecret( '' ); } }>
                                { __( 'Cancel', 'gratora-donation-platform' ) }
                            </Btn>
                        ) }
                    </div>
                </>
            ) }
        </div>
    );
}

export default function PayPalKeysCard( { s, onOffline = null } ) {
    const [ status, setStatus ]       = useState( null );
    const [ loading, setLoading ]     = useState( true );
    const [ loadError, setLoadError ] = useState( false );
    const [ confirm, setConfirm ]     = useState( null );

    const load = useCallback( () => {
        setLoading( true );
        setLoadError( false );
        apiFetch( { path: '/gratora/v1/gateways/paypal/status' } )
            .then( ( r ) => setStatus( r ) )
            .catch( () => { setStatus( null ); setLoadError( true ); } )
            .finally( () => setLoading( false ) );
    }, [] );

    useEffect( () => { load(); }, [ load ] );

    const removeKeys = useCallback( ( mode ) => {
        const all = mode === 'all';
        setConfirm( {
            title: __( 'Remove PayPal credentials', 'gratora-donation-platform' ),
            message: all
                ? __( 'Remove both credential sets? PayPal donations will stop until you add them again.', 'gratora-donation-platform' )
                : __( 'Remove these credentials? PayPal donations in this mode will stop until you add them again.', 'gratora-donation-platform' ),
            confirmLabel: __( 'Remove', 'gratora-donation-platform' ),
            destructive: true,
            onConfirm: async () => {
                apiFetch( { path: `/gratora/v1/gateways/paypal/keys?mode=${ mode }`, method: 'DELETE' } )
                    .then( ( res ) => setStatus( res ) )
                    .catch( ( err ) => notify.error( err?.message || __( 'Could not remove the credentials.', 'gratora-donation-platform' ) ) );
            },
        } );
    }, [] );

    const [ open, setOpen ] = useCardOpen( loadError, 'payments', 'paypal' );

    const head = {
        leading:     <BrandMark letter="P" variant="paypal" />,
        title:       __( 'PayPal', 'gratora-donation-platform' ),
        collapsible: true,
        open,
        onToggle:    setOpen,
    };
    const sub = __( 'PayPal, Venmo, Pay Later and cards', 'gratora-donation-platform' );

    if ( loading ) {
        return (
            <Card { ...head } sub={ sub } meta={ <Pill tone="gray">{ __( 'Checking…', 'gratora-donation-platform' ) }</Pill> }>
                <p className="gratora-connect-p">{ __( 'Loading PayPal status…', 'gratora-donation-platform' ) }</p>
            </Card>
        );
    }

    if ( loadError ) {
        return (
            <Card { ...head } sub={ sub } meta={ <Pill tone="amber">{ __( 'Unavailable', 'gratora-donation-platform' ) }</Pill> }>
                <Notice status="warning">
                    <strong>{ __( 'Could not check your PayPal setup.', 'gratora-donation-platform' ) }</strong>{ ' ' }
                    { __( 'Something went wrong loading the status. Please try again.', 'gratora-donation-platform' ) }
                </Notice>
                <div style={ { marginTop: 18 } }>
                    <Btn variant="primary" onClick={ load }>{ __( 'Retry', 'gratora-donation-platform' ) }</Btn>
                </div>
            </Card>
        );
    }

    const account   = status?.account || null;
    const connected = !! status?.connected;

    // A test donation has already worked, so the credentials still missing are the ones that take real money.
    const liveFirst = !! status?.test_donation_made;

    const hookMissing = [ 'test', 'live' ].some( ( mode ) => !! account?.[ `has_${ mode }` ] && ! account?.[ `webhook_${ mode }` ] );

    return (
        <>
        <Card
            { ...head }
            sub={ connected && account?.email ? account.email : sub }
            meta={ connected
                ? <Pill tone="green">{ __( 'Ready', 'gratora-donation-platform' ) }</Pill>
                : <Pill tone="gray">{ __( 'Not set up', 'gratora-donation-platform' ) }</Pill> }
        >
            { ! connected && <FirstSteps onOffline={ onOffline } /> }

            <ToggleRow
                title={ __( 'Enable the PayPal gateway', 'gratora-donation-platform' ) }
                sub={ connected
                    ? __( 'Your credentials stay on file while it is off.', 'gratora-donation-platform' )
                    : __( 'Available once your credentials are saved.', 'gratora-donation-platform' ) }
                checked={ connected && !! s.value( 'paypal.enabled', true ) }
                onChange={ s.setValue( 'paypal.enabled' ) }
                disabled={ ! connected }
            />

            { connected && (
                <Notice status="success">
                    <strong>{ __( 'You are all set.', 'gratora-donation-platform' ) }</strong>{ ' ' }
                    { __( 'PayPal buttons will appear on your donation forms.', 'gratora-donation-platform' ) }
                </Notice>
            ) }

            <div className="gratora-stripe-modes">
                { ( liveFirst ? [ 'live', 'test' ] : [ 'test', 'live' ] ).map( ( mode ) => (
                    <ModeKeys
                        key={ mode }
                        mode={ mode }
                        optional={ liveFirst && mode === 'test' }
                        account={ account }
                        onSaved={ setStatus }
                        onRemove={ removeKeys }
                        askConfirm={ setConfirm }
                    />
                ) ) }
            </div>

            <WebhookSettings url={ status?.webhook_url || '' } needed={ hookMissing } />
        </Card>
        <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
        </>
    );
}
