import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Modal } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { ShieldCheck } from 'lucide-react';

import EmptyState from '../../../_shared/components/EmptyState';
import { formatDateTime } from '../helpers';
import { IconAlert, IconDownload, IconTrash } from '../icons';
import { downloadFile } from '@gratora/ui/utils/download';
import notify from '../../../_shared/notify';
import { userCan } from '../../../_shared/caps';

function RedactDialog( { donor, onClose, onDone } ) {
    const expected = donor.email || `DONOR_${ donor.id }`;
    const [ typed, setTyped ]   = useState( '' );
    const [ saving, setSaving ] = useState( false );
    const [ error, setError ]   = useState( null );
    const matches = typed.trim().toLowerCase() === expected.toLowerCase();

    const submit = async ( e ) => {
        e.preventDefault();
        if ( ! matches ) return;
        setSaving( true );
        setError( null );
        try {
            await apiFetch( {
                path:   `/gratora/v1/admin/donors/${ donor.id }/redact`,
                method: 'POST',
                data:   { confirmation: typed.trim() },
            } );
            onDone();
        } catch ( err ) {
            setError( err?.message || __( 'Redact failed', 'gratora-donation-platform' ) );
        } finally {
            setSaving( false );
        }
    };

    return (
        <Modal
            title={ __( 'Redact this donor', 'gratora-donation-platform' ) }
            onRequestClose={ onClose }
            className="dp-modal"
            size="medium"
        >
            <form onSubmit={ submit } className="dp-edit-form">
                <p style={ { gridColumn: '1 / -1', color: '#6b7280', fontSize: 13, marginTop: 0 } }>
                    { __( 'PII (name, email, phone, address, tax id, notes) will be permanently removed, and any active recurring plan is cancelled at the gateway. Lifetime totals, donations, and receipts are retained for accounting. This cannot be undone.', 'gratora-donation-platform' ) }
                </p>
                <label style={ { gridColumn: '1 / -1' } }>
                    { sprintf( /* translators: %s: confirmation word */ __( 'Type %s to confirm', 'gratora-donation-platform' ), expected ) }
                    <input className="gratora-input"
                        type="text"
                        value={ typed }
                        onChange={ ( e ) => setTyped( e.target.value ) }
                        autoFocus
                        autoComplete="off"
                        spellCheck="false"
                    />
                </label>
                { error && <div className="dp-edit-form__error">{ error }</div> }
                <div className="dp-edit-form__actions">
                    <button type="button" className="btn" onClick={ onClose } disabled={ saving }>
                        { __( 'Cancel', 'gratora-donation-platform' ) }
                    </button>
                    <button type="submit" className="btn btn--danger" disabled={ saving || ! matches }>
                        { saving ? __( 'Redacting…', 'gratora-donation-platform' ) : __( 'Redact donor', 'gratora-donation-platform' ) }
                    </button>
                </div>
            </form>
        </Modal>
    );
}

export default function ConsentTab( { consents, donor, onChanged } ) {
    const [ showRedact, setShowRedact ] = useState( false );
    const [ hiding, setHiding ] = useState( false );
    const current = consents?.current || [];
    const history = consents?.history || [];
    const historyTotal = Number( consents?.history_total ?? history.length );

    const setPublicHidden = async ( hidden ) => {
        setHiding( true );
        try {
            await apiFetch( {
                path:   `/gratora/v1/admin/donors/${ donor.id }`,
                method: 'PATCH',
                data:   { public_hidden: hidden },
            } );
            onChanged && onChanged();
        } catch ( err ) {
            notify.error( err?.message || __( 'Could not change this.', 'gratora-donation-platform' ) );
        } finally {
            setHiding( false );
        }
    };

    return (
        <div>
            <div className="dp-card">
                { current.length === 0
                    ? (
                        <EmptyState
                            compact
                            icon={ <ShieldCheck size={ 22 } strokeWidth={ 1.75 } /> }
                            title={ __( 'No consent records yet', 'gratora-donation-platform' ) }
                            body={ __( 'Each donation captures opt-ins for the purposes you configure. They land here for audit and right-to-withdraw requests.', 'gratora-donation-platform' ) }
                        />
                    )
                    : (
                        <div style={ { overflowX: 'auto' } }>
                            <table className="dp-table">
                                <thead>
                                    <tr>
                                        <th>{ __( 'Purpose',     'gratora-donation-platform' ) }</th>
                                        <th>{ __( 'Status',      'gratora-donation-platform' ) }</th>
                                        <th>{ __( 'Granted at',  'gratora-donation-platform' ) }</th>
                                        <th>{ __( 'Source',      'gratora-donation-platform' ) }</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    { current.map( ( c ) => (
                                        <tr key={ c.purpose }>
                                            <td>{ c.purpose }</td>
                                            <td>
                                                { ! c.occurred_at
                                                    ? <span className="dp-pill is-muted">{ __( 'No response', 'gratora-donation-platform' ) }</span>
                                                    : c.granted
                                                        ? <span className="dp-pill is-ok">{ __( 'Granted', 'gratora-donation-platform' ) }</span>
                                                        : <span className="dp-pill is-muted">{ __( 'Revoked', 'gratora-donation-platform' ) }</span> }
                                            </td>
                                            <td>{ c.occurred_at ? formatDateTime( c.occurred_at ) : '-' }</td>
                                            <td className="consent-source">{ c.source || '-' }</td>
                                        </tr>
                                    ) ) }
                                </tbody>
                            </table>
                        </div>
                    ) }
            </div>

            <div className="dp-card" style={ { marginTop: 14 } }>
                <div className="dp-card__body">
                    <div className="dp-data-action">
                        <div className="dp-data-action__body">
                            <div className="dp-data-action__title">{ __( 'Public visibility', 'gratora-donation-platform' ) }</div>
                            <div className="dp-data-action__sub">
                                { donor?.public_hidden
                                    ? __( 'Hidden. This donor does not appear in supporter walls, recent donations or top donor lists, and their picture and message are not shown. Their donations still count toward campaign totals.', 'gratora-donation-platform' )
                                    : __( 'Visible. This donor can appear by name in supporter walls, recent donations and top donor lists, with their picture and any public message.', 'gratora-donation-platform' ) }
                            </div>
                        </div>
                        { donor && ! donor.redacted_at && userCan( 'edit_donors' ) && (
                            <button
                                type="button"
                                className="btn"
                                disabled={ hiding }
                                onClick={ () => setPublicHidden( ! donor.public_hidden ) }
                            >
                                { donor.public_hidden ? __( 'Show publicly', 'gratora-donation-platform' ) : __( 'Hide from public pages', 'gratora-donation-platform' ) }
                            </button>
                        ) }
                    </div>

                    <div className="dp-data-action">
                        <div className="dp-data-action__body">
                            <div className="dp-data-action__title">{ __( 'Data export', 'gratora-donation-platform' ) }</div>
                            <div className="dp-data-action__sub">
                                { __( 'Bundles donor record, donations, receipts, consents, and event log into a single JSON file.', 'gratora-donation-platform' ) }
                            </div>
                        </div>
                        { donor && userCan( 'export_donors' ) && (
                            <button
                                type="button"
                                className="btn"
                                onClick={ () => downloadFile( `/gratora/v1/admin/donors/${ donor.id }/export`, `gratora-donor-${ donor.id }.json` ).catch( ( e ) => notify.error( e?.message || __( 'Could not export personal data.', 'gratora-donation-platform' ) ) ) }
                            >
                                <IconDownload className="ic" />
                                { __( 'Export personal data', 'gratora-donation-platform' ) }
                            </button>
                        ) }
                    </div>
                </div>
                { userCan( 'redact_donors' ) && (
                <div className="dp-danger-foot">
                    <div className="dp-danger-foot__body">
                        <div className="dp-danger-foot__title">
                            <IconAlert width="14" height="14" />
                            { __( 'Redact donor', 'gratora-donation-platform' ) }
                        </div>
                        <div className="dp-danger-foot__sub">
                            { __( 'Drops PII (name, email, phone, address, tax id), cancels any active recurring plan at the gateway, and sets redacted_at. Lifetime totals and donation records are kept for accounting. This cannot be undone.', 'gratora-donation-platform' ) }
                        </div>
                    </div>
                    <div className="dp-danger-foot__actions">
                        <button
                            type="button"
                            className="btn btn--danger"
                            onClick={ () => setShowRedact( true ) }
                            disabled={ ! donor || !! donor?.redacted_at }
                        >
                            <IconTrash className="ic" />
                            { donor?.redacted_at ? __( 'Already redacted', 'gratora-donation-platform' ) : __( 'Redact donor', 'gratora-donation-platform' ) }
                        </button>
                    </div>
                </div>
                ) }
                { showRedact && donor && (
                    <RedactDialog
                        donor={ donor }
                        onClose={ () => setShowRedact( false ) }
                        onDone={ () => { setShowRedact( false ); onChanged?.(); } }
                    />
                ) }
            </div>

            { history.length > 0 && (
                <div className="dp-card" style={ { marginTop: 14 } }>
                    <div className="dp-card__body" style={ { padding: '16px 18px' } }>
                        <ul className="dp-consent-log">
                            { history.map( ( h ) => (
                                <li key={ h.id }>
                                    <span className={ `dp-pill ${ h.granted ? 'is-ok' : 'is-muted' }` }>
                                        { h.granted ? __( 'Granted', 'gratora-donation-platform' ) : __( 'Revoked', 'gratora-donation-platform' ) }
                                    </span>
                                    { /* The wording as it stood then, where the row kept it: the
                                         registry entry can have been edited since. */ }
                                    <span style={ { fontWeight: 500 } } title={ h.purpose_description || undefined }>
                                        { h.purpose_label || h.purpose }
                                    </span>
                                    <span className="dp-consent-log__src">{ h.source }</span>
                                    <span className="dp-consent-log__when">{ formatDateTime( h.occurred_at ) }</span>
                                </li>
                            ) ) }
                        </ul>
                        { historyTotal > history.length && (
                            <p className="dp-muted" style={ { margin: '10px 0 0', fontSize: 13 } }>
                                { sprintf(
                                    /* translators: 1: rows shown, 2: rows this donor has in total */
                                    __( 'Showing the %1$s most recent of %2$s entries.', 'gratora-donation-platform' ),
                                    history.length.toLocaleString(),
                                    historyTotal.toLocaleString()
                                ) }
                            </p>
                        ) }
                    </div>
                </div>
            ) }
        </div>
    );
}
