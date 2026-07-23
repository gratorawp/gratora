import { useState, useEffect } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import Card from '../_shared/components/Card';
import Btn from '../_shared/components/Btn';
import Notice from '../_shared/components/Notice';
import Field from '../_shared/components/Field';
import Toaster from '../_shared/components/Toaster';
import { notify } from '../_shared/notify';

const BASE = '/dono/v1/admin/license';

const STATUS_META = {
    active:   { tone: 'green', label: __( 'Active', 'dono' ) },
    grace:    { tone: 'amber', label: __( 'Grace period', 'dono' ) },
    expired:  { tone: 'amber', label: __( 'Expired', 'dono' ) },
    revoked:  { tone: 'red',   label: __( 'Revoked', 'dono' ) },
    inactive: { tone: 'gray',  label: __( 'Inactive', 'dono' ) },
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
                <strong>{ __( 'We could not confirm your renewal.', 'dono' ) }</strong>{ ' ' }
                { __( 'Your Pro add-ons keep working, so update your payment method to keep receiving updates.', 'dono' ) }
            </Notice>
        );
    }
    if ( status === 'expired' ) {
        return (
            <Notice status="warning" isDismissible={ false }>
                <strong>{ __( 'Your license expired.', 'dono' ) }</strong>{ ' ' }
                { __( 'Your Pro add-ons keep working; only updates pause. Renew to restore updates and support.', 'dono' ) }
            </Notice>
        );
    }
    if ( status === 'revoked' ) {
        return (
            <Notice status="error" isDismissible={ false }>
                <strong>{ __( 'This license was revoked.', 'dono' ) }</strong>{ ' ' }
                { __( 'Pro features are disabled on this site. Contact support if this is unexpected.', 'dono' ) }
            </Notice>
        );
    }
    return null;
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
            notify.success( __( 'License activated.', 'dono' ) );
        } catch ( e ) {
            notify.error( e?.message || __( 'Could not activate this key.', 'dono' ) );
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
            notify.success( __( 'License deactivated on this site.', 'dono' ) );
        } catch ( e ) {
            notify.error( e?.message || __( 'Could not deactivate.', 'dono' ) );
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
            notify.success( __( 'License re-checked.', 'dono' ) );
        } catch ( e ) {
            notify.error( e?.message || __( 'Could not re-check right now.', 'dono' ) );
        } finally {
            setBusy( false );
        }
    };

    let body;
    if ( loadError ) {
        body = (
            <Card>
                <p className="dono-lic-muted">
                    { __( 'Could not load license status. Refresh to try again.', 'dono' ) }
                </p>
            </Card>
        );
    } else if ( snap === null ) {
        body = (
            <Card>
                <p className="dono-lic-muted">{ __( 'Loading license status...', 'dono' ) }</p>
            </Card>
        );
    } else if ( ! snap.has_key || changing ) {
        body = (
            <Card
                title={ changing ? __( 'Change license key', 'dono' ) : __( 'Activate your license', 'dono' ) }
                meta={ changing ? null : (
                    <span className="dono-lic-pill dono-lic-pill--gray">{ __( 'Not activated', 'dono' ) }</span>
                ) }
            >
                <Field
                    label={ __( 'License key', 'dono' ) }
                    footer={ __( 'Paste the key from your purchase email. It activates every installed add-on at once.', 'dono' ) }
                >
                    <div className="dono-lic-keyrow">
                        <input
                            className="dono-input dono-input--mono"
                            type="text"
                            value={ keyInput }
                            placeholder="DONO-XXXX-XXXX-XXXX"
                            aria-label={ __( 'License key', 'dono' ) }
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
                            { __( 'Activate', 'dono' ) }
                        </Btn>
                        { changing && (
                            <Btn
                                variant="ghost"
                                onClick={ () => { setChanging( false ); setKeyInput( '' ); } }
                                disabled={ busy }
                            >
                                { __( 'Cancel', 'dono' ) }
                            </Btn>
                        ) }
                    </div>
                </Field>
            </Card>
        );
    } else {
        const addons = Array.isArray( snap.addons ) ? snap.addons : [];
        body = (
            <>
                <Card title={ __( 'License', 'dono' ) } meta={ <StatusBadge status={ snap.status } /> }>
                    <StateNotice status={ snap.status } />
                    <p className="dono-lic-lead">
                        { snap.status === 'revoked'
                            ? __( 'Pro features are disabled on this site.', 'dono' )
                            : __( 'This key unlocks every installed Dono Pro add-on.', 'dono' ) }
                    </p>
                    <Field label={ __( 'License key', 'dono' ) }>
                        <div className="dono-lic-keyrow">
                            <input
                                className="dono-input dono-input--mono"
                                type="text"
                                value={ snap.key_masked || '' }
                                readOnly
                                aria-label={ __( 'License key, masked', 'dono' ) }
                            />
                            <div className="dono-lic-keyactions">
                                <button
                                    type="button"
                                    className="dono-lic-link dono-lic-link--muted"
                                    onClick={ deactivate }
                                    disabled={ busy }
                                >
                                    { __( 'Deactivate this site', 'dono' ) }
                                </button>
                                <button
                                    type="button"
                                    className="dono-lic-link"
                                    onClick={ () => { setChanging( true ); setKeyInput( '' ); } }
                                    disabled={ busy }
                                >
                                    { __( 'Change key', 'dono' ) }
                                </button>
                            </div>
                        </div>
                    </Field>
                    <div className="dono-lic-foot">
                        <Btn onClick={ recheck } isBusy={ busy }>
                            { __( 'Re-check entitlements', 'dono' ) }
                        </Btn>
                    </div>
                </Card>

                <Card
                    title={ __( 'Your add-ons', 'dono' ) }
                    sub={ __( 'Installed Pro add-ons unlocked by your key.', 'dono' ) }
                    meta={ addons.length > 0
                        ? sprintf(
                            /* translators: %d: number of licensed Pro add-ons */
                            _n( '%d add-on licensed', '%d add-ons licensed', addons.length, 'dono' ),
                            addons.length,
                        )
                        : null }
                >
                    { addons.length === 0 ? (
                        <p className="dono-lic-muted">{ __( 'No Pro add-ons installed yet.', 'dono' ) }</p>
                    ) : (
                        <div className="dono-lic-addons">
                            { addons.map( ( a ) => (
                                <div className="dono-lic-addon" key={ a.id }>
                                    <div className="dono-lic-addon__main">
                                        <div className="dono-lic-addon__name">{ a.name }</div>
                                        <div className="dono-lic-addon__id">{ a.id }</div>
                                    </div>
                                    <span className="dono-lic-pill dono-lic-pill--green">
                                        { __( 'Licensed', 'dono' ) }
                                    </span>
                                </div>
                            ) ) }
                        </div>
                    ) }
                </Card>
            </>
        );
    }

    return (
        <div className="dono-licenses-page">
            <div className="dono-lic-crumbs">
                <a href="admin.php?page=dono">{ __( 'Dono', 'dono' ) }</a>
                <span className="sep" aria-hidden="true" />
                <span className="here">{ __( 'Licenses', 'dono' ) }</span>
            </div>

            <div className="dono-lic-head">
                <h1>{ __( 'Licenses', 'dono' ) }</h1>
                <p>{ __( 'One key activates every Dono Pro add-on on this site.', 'dono' ) }</p>
            </div>

            <Toaster />

            { body }
        </div>
    );
}
