import { useState, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import Card from '../_shared/components/Card';
import Btn from '../_shared/components/Btn';
import Notice from '../_shared/components/Notice';
import Field from '../_shared/components/Field';
import { notify } from '../_shared/notify';

const BASE = '/dono/v1/admin/license';

const STATUS_META = {
    active:   { tone: 'green', label: __( 'Active', 'dono-fundraising-platform' ) },
    grace:    { tone: 'amber', label: __( 'Grace period', 'dono-fundraising-platform' ) },
    expired:  { tone: 'amber', label: __( 'Expired', 'dono-fundraising-platform' ) },
    revoked:  { tone: 'red',   label: __( 'Revoked', 'dono-fundraising-platform' ) },
    inactive: { tone: 'gray',  label: __( 'Inactive', 'dono-fundraising-platform' ) },
};

// Per add-on, from the licensing client. 'unknown' means nothing checked it,
// which is the honest state when no client is installed.
const ADDON_PILL = {
    active: 'green',
    grace: 'amber',
    expired: 'amber',
    over_limit: 'amber',
    revoked: 'red',
    invalid: 'red',
    not_entitled: 'red',
    inactive: 'gray',
};

const ADDON_LABEL = {
    active: __( 'Licensed', 'dono-fundraising-platform' ),
    grace: __( 'In grace period', 'dono-fundraising-platform' ),
    expired: __( 'Expired', 'dono-fundraising-platform' ),
    over_limit: __( 'Site limit reached', 'dono-fundraising-platform' ),
    revoked: __( 'Revoked', 'dono-fundraising-platform' ),
    invalid: __( 'Key not accepted', 'dono-fundraising-platform' ),
    not_entitled: __( 'Not on this key', 'dono-fundraising-platform' ),
    inactive: __( 'Not activated', 'dono-fundraising-platform' ),
};

function StatusBadge( { status } ) {
    const meta = STATUS_META[ status ] || STATUS_META.inactive;
    return (
        <span className={ `dono-lic-badge dono-lic-badge--${ meta.tone }` }>
            <span className="dono-lic-badge__dot" aria-hidden="true" />
            { meta.label }
        </span>
    );
}

// State-specific banner. Active is quiet (badge only); the rest explain what
// changed and whether Pro features still run.
function StateNotice( { status } ) {
    if ( status === 'grace' ) {
        return (
            <Notice status="warning" isDismissible={ false }>
                <strong>{ __( 'We could not confirm your renewal.', 'dono-fundraising-platform' ) }</strong>{ ' ' }
                { __( 'Your Pro add-ons keep working, so update your payment method to keep receiving updates.', 'dono-fundraising-platform' ) }
            </Notice>
        );
    }
    if ( status === 'expired' ) {
        return (
            <Notice status="warning" isDismissible={ false }>
                <strong>{ __( 'Your license expired.', 'dono-fundraising-platform' ) }</strong>{ ' ' }
                { __( 'Your Pro add-ons keep working; only updates pause. Renew to restore updates and support.', 'dono-fundraising-platform' ) }
            </Notice>
        );
    }
    if ( status === 'invalid' || status === 'not_entitled' ) {
        return (
            <Notice status="warning" isDismissible={ false }>
                <strong>{ __( 'This key was not accepted.', 'dono-fundraising-platform' ) }</strong>{ ' ' }
                { __( 'Check it against your purchase email. Until it is fixed your add-ons will not receive updates or security fixes.', 'dono-fundraising-platform' ) }
            </Notice>
        );
    }
    if ( status === 'over_limit' ) {
        return (
            <Notice status="warning" isDismissible={ false }>
                <strong>{ __( 'This key has no seats left.', 'dono-fundraising-platform' ) }</strong>{ ' ' }
                { __( 'Deactivate it on a site you no longer use, or upgrade, to receive updates here.', 'dono-fundraising-platform' ) }
            </Notice>
        );
    }
    if ( status === 'revoked' ) {
        return (
            <Notice status="error" isDismissible={ false }>
                <strong>{ __( 'This license was revoked.', 'dono-fundraising-platform' ) }</strong>{ ' ' }
                { __( 'Pro features are disabled on this site. Contact support if this is unexpected.', 'dono-fundraising-platform' ) }
            </Notice>
        );
    }
    return null;
}

// Shown whether or not a key is stored: an operator with unlicensed add-ons
// installed needs to see them, which was the whole reason the screen looked
// empty before a key was entered.
function AddonsCard( { addons } ) {
    if ( addons.length === 0 ) {
        return null;
    }

    return (
        <Card
            title={ __( 'Your add-ons', 'dono-fundraising-platform' ) }
            sub={ __( 'Installed Pro add-ons unlocked by your key.', 'dono-fundraising-platform' ) }
            meta={ sprintf(
                /* translators: 1: entitled add-ons, 2: installed add-ons */
                __( '%1$d of %2$d licensed', 'dono-fundraising-platform' ),
                addons.filter( ( a ) => a.entitled ).length,
                addons.length,
            ) }
        >
            <div className="dono-lic-addons">
                { addons.map( ( a ) => (
                    <div className="dono-lic-addon" key={ a.id }>
                        <div className="dono-lic-addon__main">
                            <div className="dono-lic-addon__name">{ a.name }</div>
                            <div className="dono-lic-addon__id">{ a.id }</div>
                        </div>
                        <span className={ `dono-lic-pill dono-lic-pill--${ ADDON_PILL[ a.status ] || 'gray' }` }>
                            { ADDON_LABEL[ a.status ] || __( 'Not checked', 'dono-fundraising-platform' ) }
                        </span>
                    </div>
                ) ) }
            </div>
        </Card>
    );
}

export default function Licenses() {
    const [ snap, setSnap ]           = useState( null );
    const [ loadError, setLoadError ] = useState( false );
    const [ keyInput, setKeyInput ]   = useState( '' );
    const [ changing, setChanging ]   = useState( false );
    const [ busy, setBusy ]           = useState( false );

    useEffect( () => {
        let aborted = false;
        apiFetch( { path: BASE } )
            .then( ( data ) => { if ( ! aborted ) setSnap( data ); } )
            .catch( () => { if ( ! aborted ) setLoadError( true ); } );
        return () => { aborted = true; };
    }, [] );

    const activate = async () => {
        const key = keyInput.trim();
        if ( ! key || busy ) return;
        setBusy( true );
        try {
            const data = await apiFetch( { path: BASE, method: 'POST', data: { key } } );
            setSnap( data );
            setKeyInput( '' );
            setChanging( false );
            notify.success( __( 'License activated.', 'dono-fundraising-platform' ) );
        } catch ( e ) {
            notify.error( e?.message || __( 'Could not activate this key.', 'dono-fundraising-platform' ) );
        } finally {
            setBusy( false );
        }
    };

    const deactivate = async () => {
        if ( busy ) return;
        setBusy( true );
        try {
            const data = await apiFetch( { path: BASE, method: 'DELETE' } );
            setSnap( data );
            setKeyInput( '' );
            setChanging( false );
            notify.success( __( 'License deactivated on this site.', 'dono-fundraising-platform' ) );
        } catch ( e ) {
            notify.error( e?.message || __( 'Could not deactivate.', 'dono-fundraising-platform' ) );
        } finally {
            setBusy( false );
        }
    };

    const recheck = async () => {
        if ( busy ) return;
        setBusy( true );
        try {
            const data = await apiFetch( { path: `${ BASE }/recheck`, method: 'POST' } );
            setSnap( data );
            notify.success( __( 'License re-checked.', 'dono-fundraising-platform' ) );
        } catch ( e ) {
            notify.error( e?.message || __( 'Could not re-check right now.', 'dono-fundraising-platform' ) );
        } finally {
            setBusy( false );
        }
    };

    let body;
    if ( loadError ) {
        body = (
            <Card>
                <p className="dono-lic-muted">
                    { __( 'Could not load license status. Refresh to try again.', 'dono-fundraising-platform' ) }
                </p>
            </Card>
        );
    } else if ( snap === null ) {
        body = (
            <Card>
                <p className="dono-lic-muted">{ __( 'Loading license status…', 'dono-fundraising-platform' ) }</p>
            </Card>
        );
    } else if ( ! snap.has_key || changing ) {
        const pending = Array.isArray( snap.addons ) ? snap.addons : [];
        body = (
            <>
            <Card
                title={ changing ? __( 'Change license key', 'dono-fundraising-platform' ) : __( 'Activate your license', 'dono-fundraising-platform' ) }
                meta={ changing ? null : (
                    <span className="dono-lic-pill dono-lic-pill--gray">{ __( 'Not activated', 'dono-fundraising-platform' ) }</span>
                ) }
            >
                <Field
                    label={ __( 'License key', 'dono-fundraising-platform' ) }
                    footer={ __( 'Paste the key from your purchase email. It activates every installed add-on at once.', 'dono-fundraising-platform' ) }
                >
                    <div className="dono-lic-keyrow">
                        <input
                            className="dono-input dono-input--mono"
                            type="text"
                            value={ keyInput }
                            placeholder="DONO-XXXX-XXXX-XXXX"
                            aria-label={ __( 'License key', 'dono-fundraising-platform' ) }
                            disabled={ busy }
                            onChange={ ( e ) => setKeyInput( e.target.value ) }
                            onKeyDown={ ( e ) => { if ( e.key === 'Enter' ) activate(); } }
                        />
                        <Btn
                            variant="primary"
                            onClick={ activate }
                            isBusy={ busy }
                            disabled={ ! keyInput.trim() }
                        >
                            { __( 'Activate', 'dono-fundraising-platform' ) }
                        </Btn>
                        { changing && (
                            <Btn
                                variant="ghost"
                                onClick={ () => { setChanging( false ); setKeyInput( '' ); } }
                                disabled={ busy }
                            >
                                { __( 'Cancel', 'dono-fundraising-platform' ) }
                            </Btn>
                        ) }
                    </div>
                </Field>
            </Card>
            { ! changing && pending.length > 0 && (
                <Notice status="warning" isDismissible={ false }>
                    { __( 'These add-ons are installed but not licensed, so they will not receive updates or security fixes.', 'dono-fundraising-platform' ) }
                </Notice>
            ) }
            <AddonsCard addons={ pending } />
            </>
        );
    } else {
        const addons = Array.isArray( snap.addons ) ? snap.addons : [];
        body = (
            <>
                <Card title={ __( 'License', 'dono-fundraising-platform' ) } meta={ <StatusBadge status={ snap.status } /> }>
                    <StateNotice status={ snap.status } />
                    <p className="dono-lic-lead">
                        { snap.status === 'revoked'
                            ? __( 'Pro features are disabled on this site.', 'dono-fundraising-platform' )
                            : __( 'This key unlocks every installed Dono Pro add-on.', 'dono-fundraising-platform' ) }
                    </p>
                    <Field label={ __( 'License key', 'dono-fundraising-platform' ) }>
                        <div className="dono-lic-keyrow">
                            <input
                                className="dono-input dono-input--mono"
                                type="text"
                                value={ snap.key_masked || '' }
                                readOnly
                                aria-label={ __( 'License key, masked', 'dono-fundraising-platform' ) }
                            />
                            <div className="dono-lic-keyactions">
                                <button
                                    type="button"
                                    className="dono-lic-link dono-lic-link--muted"
                                    onClick={ deactivate }
                                    disabled={ busy }
                                >
                                    { __( 'Deactivate this site', 'dono-fundraising-platform' ) }
                                </button>
                                <button
                                    type="button"
                                    className="dono-lic-link"
                                    onClick={ () => { setChanging( true ); setKeyInput( '' ); } }
                                    disabled={ busy }
                                >
                                    { __( 'Change key', 'dono-fundraising-platform' ) }
                                </button>
                            </div>
                        </div>
                    </Field>
                    <div className="dono-lic-foot">
                        <Btn onClick={ recheck } isBusy={ busy }>
                            { __( 'Re-check entitlements', 'dono-fundraising-platform' ) }
                        </Btn>
                    </div>
                </Card>

                <AddonsCard addons={ addons } />
            </>
        );
    }

    // A Settings tab now, so the page header and Toaster belong to Settings.
    return (
        <div className="dono-licenses-page">
            <p className="dono-lic-intro">
                { __( 'One key activates every Dono Pro add-on on this site.', 'dono-fundraising-platform' ) }
            </p>
            { body }
        </div>
    );
}
