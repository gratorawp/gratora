import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

// Reads savedRecord (not pending edits) so the checklist reflects persisted state.
export default function SetupPanel( { org, brand, gateways, email, onJumpTo } ) {
    // Stripe Connect status lives in its own option (dono_connect_stripe), not
    // in dono_gateway_config, so the Gateway step has to fetch it separately.
    const [ stripeConnect, setStripeConnect ] = useState( null );
    useEffect( () => {
        apiFetch( { path: '/dono/v1/connect/stripe/status' } )
            .then( setStripeConnect )
            .catch( () => setStripeConnect( null ) );
    }, [] );

    const steps = buildSteps( {
        org:           org.savedRecord || {},
        brand:         brand.savedRecord || {},
        gateways:      gateways.savedRecord || {},
        email:         email.savedRecord || {},
        stripeConnect: stripeConnect || {},
    } );

    const loading = org.isLoading || brand.isLoading || gateways.isLoading || email.isLoading;
    const done = steps.filter( ( s ) => s.state === 'done' ).length;
    const total = steps.length;

    const health = buildHealth( gateways.savedRecord || {}, stripeConnect || {} );

    return (
        <div className="dono-panel">
            <div className="dono-setup">
                <div className="dono-setup__head">
                    <div className="dono-setup__title">
                        { done === total
                            ? __( 'Ready to accept donations', 'dono' )
                            : __( 'Setup checklist', 'dono' ) }
                        <small>
                            { done === total
                                ? __( 'Everything below is configured.', 'dono' )
                                : __( 'Finish these to start receiving live donations.', 'dono' ) }
                        </small>
                    </div>
                    <div className="dono-setup__progress">
                        { loading
                            ? __( 'Checking…', 'dono' )
                            : sprintf(
                                /* translators: 1: number of completed setup steps. 2: total number of steps. */
                                __( '%1$d of %2$d complete', 'dono' ),
                                done,
                                total,
                            ) }
                    </div>
                </div>

                <div className="dono-setup__bar" aria-hidden="true">
                    { steps.map( ( s ) => (
                        <div
                            key={ s.id }
                            className={ `dono-setup__bar-seg${ s.state === 'done' ? ' is-done' : '' }${ s.state === 'doing' ? ' is-doing' : '' }` }
                        />
                    ) ) }
                </div>

                <div className="dono-setup__steps">
                    { steps.map( ( s ) => (
                        <button
                            key={ s.id }
                            type="button"
                            className={ `dono-setup-step${ s.state === 'done' ? ' is-done' : '' }${ s.state === 'doing' ? ' is-doing' : '' }` }
                            onClick={ () => onJumpTo( s.tab ) }
                        >
                            <span className="dono-setup-step__check">
                                { s.state === 'done' && (
                                    <svg width="10" height="10" viewBox="0 0 12 12" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                        <polyline points="2 6 5 9 10 3" />
                                    </svg>
                                ) }
                                { s.state === 'doing' && (
                                    <svg width="10" height="10" viewBox="0 0 12 12" fill="none">
                                        <circle cx="6" cy="6" r="2.5" fill="currentColor" />
                                    </svg>
                                ) }
                                { s.state === 'todo' && (
                                    <svg width="8" height="8" viewBox="0 0 8 8" fill="currentColor">
                                        <circle cx="4" cy="4" r="2" />
                                    </svg>
                                ) }
                            </span>
                            <div className="dono-setup-step__body">
                                <div className="dono-setup-step__title">{ s.title }</div>
                                <div className="dono-setup-step__sub">{ s.sub }</div>
                            </div>
                        </button>
                    ) ) }
                </div>
            </div>

            <div className="dono-health-grid">
                { health.map( ( h ) => (
                    <div key={ h.id } className="dono-health">
                        <div className="dono-health__head">
                            <span className={ `dono-health__dot${ h.tone === 'amber' ? ' is-amber' : '' }${ h.tone === 'red' ? ' is-red' : '' }` } />
                            <span className="dono-health__label">{ h.label }</span>
                        </div>
                        <div className="dono-health__value num">{ h.value }</div>
                        <div className="dono-health__meta">{ h.meta }</div>
                        { h.actionLabel && (
                            <button
                                type="button"
                                className="dono-health__action"
                                onClick={ h.action ? h.action : ( () => onJumpTo( h.tab ) ) }
                            >
                                { h.actionLabel } →
                            </button>
                        ) }
                    </div>
                ) ) }
            </div>
        </div>
    );
}

function buildSteps( { org, brand, gateways, email, stripeConnect } ) {
    const stripe = gateways.stripe || {};
    const offline = gateways.offline || {};
    const connect = stripeConnect || {};

    const orgName    = String( org.name || '' ).trim();
    const orgEmail   = String( org.email || '' ).trim();
    const orgFilled  = orgName !== '' && orgEmail !== '';

    // Untouched defaults are functional; mark "doing" not "todo".
    const defaultId      = String( brand.default_id || 'classic' );
    const savedPresets   = Array.isArray( brand.presets ) ? brand.presets : [];
    const brandCustom    = defaultId !== 'classic' || savedPresets.length > 0;
    const brandState     = brandCustom ? 'done' : 'doing';
    const brandSub       = brandCustom
        ? sprintf( /* translators: %s: brand preset id */ __( 'Default: %s', 'dono' ), defaultId )
        : __( 'Using Classic. Pick or customise to make it yours.', 'dono' );

    // Stripe is "ready" when a Connect account is on file via the broker flow,
    // not when API keys are filled in the gateways option (Connect doesn't use
    // keys here). Connect status endpoint returns at least { connected, account_id }.
    const stripeConfigured  = !! connect.connected;
    const offlineConfigured = offline.enabled && String( offline.instructions || '' ).trim() !== '';
    let gatewayState = 'todo';
    let gatewaySub   = __( 'Connect Stripe or enable offline donations.', 'dono' );
    if ( stripeConfigured ) {
        gatewayState = 'done';
        gatewaySub   = __( 'Stripe connected', 'dono' );
    } else if ( offlineConfigured ) {
        gatewayState = 'done';
        gatewaySub   = __( 'Offline donations enabled', 'dono' );
    }

    const fromName  = String( email.from_name || '' ).trim();
    const fromEmail = String( email.from_email || '' ).trim();
    const senderSet = fromName !== '' && fromEmail !== '';

    return [
        {
            id:    'org',
            title: __( 'Organisation', 'dono' ),
            sub:   orgFilled ? ( orgName || __( 'Saved', 'dono' ) ) : __( 'Name and contact email', 'dono' ),
            state: orgFilled ? 'done' : 'todo',
            tab:   'organization',
        },
        {
            id:    'brand',
            title: __( 'Brand preset', 'dono' ),
            sub:   brandSub,
            state: brandState,
            tab:   'brand',
        },
        {
            id:    'gateway',
            title: __( 'Payment gateway', 'dono' ),
            sub:   gatewaySub,
            state: gatewayState,
            tab:   'gateways',
        },
        {
            id:    'sender',
            title: __( 'Sender email', 'dono' ),
            sub:   senderSet ? ( fromEmail || __( 'Configured', 'dono' ) ) : __( 'From name and a verified address', 'dono' ),
            state: senderSet ? 'done' : 'todo',
            tab:   'email',
        },
    ];
}

function buildHealth( gateways, stripeConnect ) {
    const stripe  = gateways.stripe || {};
    const connect = stripeConnect || {};

    let gw = { value: __( 'Not connected', 'dono' ), tone: 'red',   meta: __( 'Set up a gateway to accept donations.', 'dono' ) };
    if ( connect.connected ) {
        gw = { value: __( 'Stripe connected', 'dono' ), tone: 'green', meta: __( 'Donations are flowing to your Stripe account.', 'dono' ) };
    }

    // The webhook secret field lives on the Stripe card and only appears once an
    // account is connected, so don't present it as an independent step before then.
    let webhook;
    if ( ! connect.connected ) {
        webhook = { value: __( 'After connecting', 'dono' ), tone: 'amber', meta: __( 'Connect Stripe first, then add its webhook secret on the Stripe card.', 'dono' ) };
    } else if ( stripe.webhook_secret_test || stripe.webhook_secret_live ) {
        webhook = { value: __( 'Configured', 'dono' ), tone: 'green', meta: __( 'Refunds and disputes will sync.', 'dono' ) };
    } else {
        webhook = { value: __( 'Not configured', 'dono' ), tone: 'amber', meta: __( 'Add the webhook signing secret on the Stripe card.', 'dono' ) };
    }

    return [
        {
            id:    'gateway',
            label: __( 'Gateway', 'dono' ),
            value: gw.value,
            tone:  gw.tone,
            meta:  gw.meta,
            actionLabel: __( 'Configure', 'dono' ),
            tab:   'gateways',
        },
        {
            id:    'webhook',
            label: __( 'Webhook', 'dono' ),
            value: webhook.value,
            tone:  webhook.tone,
            meta:  webhook.meta,
            actionLabel: __( 'Configure', 'dono' ),
            tab:   'gateways',
        },
        {
            id:    'donation',
            label: __( 'Last donation', 'dono' ),
            value: '-',
            tone:  'green',
            meta:  __( 'No donations yet', 'dono' ),
            actionLabel: null,
        },
        {
            id:    'cron',
            label: __( 'Cron', 'dono' ),
            value: __( 'Running', 'dono' ),
            tone:  'green',
            meta:  __( 'Last beat recently', 'dono' ),
            actionLabel: null,
        },
    ];
}
