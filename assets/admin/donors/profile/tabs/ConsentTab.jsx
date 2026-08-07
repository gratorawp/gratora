import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Modal } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { ShieldCheck } from 'lucide-react';

import EmptyState from '../../../_shared/components/EmptyState';
import { formatDateTime } from '../helpers';
import { IconAlert, IconDownload, IconTrash } from '../icons';
import { downloadFile } from '../../../_shared/download';
import notify from '../../../_shared/notify';

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
                path:   `/dono/v1/admin/donors/${ donor.id }/redact`,
                method: 'POST',
                data:   { confirmation: typed.trim() },
            } );
            onDone();
        } catch ( err ) {
            setError( err?.message || __( 'Redact failed', 'dono' ) );
        } finally {
            setSaving( false );
        }
    };

    return (
        <Modal title={ __( 'Redact this donor', 'dono' ) } onRequestClose={ onClose } className="dp-modal">
            <form onSubmit={ submit } className="dp-edit-form">
                <p style={ { gridColumn: '1 / -1', color: '#6b7280', fontSize: 13, marginTop: 0 } }>
                    { __( 'PII (name, email, phone, address, tax id, notes) will be permanently removed. Lifetime totals, donations, and receipts are retained for accounting. This cannot be undone.', 'dono' ) }
                </p>
                <label style={ { gridColumn: '1 / -1' } }>
                    { sprintf( /* translators: %s: confirmation word */ __( 'Type %s to confirm', 'dono' ), expected ) }
                    <input className="dono-input"
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
                        { __( 'Cancel', 'dono' ) }
                    </button>
                    <button type="submit" className="btn btn--danger" disabled={ saving || ! matches }>
                        { saving ? __( 'Redacting…', 'dono' ) : __( 'Redact donor', 'dono' ) }
                    </button>
                </div>
            </form>
        </Modal>
    );
}

export default function ConsentTab( { consents, donor, onChanged } ) {
    const [ showRedact, setShowRedact ] = useState( false );
    const current = consents?.current || [];
    const history = consents?.history || [];

    return (
        <div>
            <div className="dp-card">
                { current.length === 0
                    ? (
                        <EmptyState
                            compact
                            icon={ <ShieldCheck size={ 22 } strokeWidth={ 1.75 } /> }
                            title={ __( 'No consent records yet', 'dono' ) }
                            body={ __( 'Each donation captures opt-ins for the purposes you configure. They land here for audit and right-to-withdraw requests.', 'dono' ) }
                        />
                    )
                    : (
                        <div style={ { overflowX: 'auto' } }>
                            <table className="dp-table">
                                <thead>
                                    <tr>
                                        <th>{ __( 'Purpose',     'dono' ) }</th>
                                        <th>{ __( 'Status',      'dono' ) }</th>
                                        <th>{ __( 'Granted at',  'dono' ) }</th>
                                        <th>{ __( 'Source',      'dono' ) }</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    { current.map( ( c ) => (
                                        <tr key={ c.purpose }>
                                            <td>{ c.purpose }</td>
                                            <td>
                                                { ! c.occurred_at
                                                    ? <span className="dp-pill is-muted">{ __( 'No response', 'dono' ) }</span>
                                                    : c.granted
                                                        ? <span className="dp-pill is-ok">{ __( 'Granted', 'dono' ) }</span>
                                                        : <span className="dp-pill is-muted">{ __( 'Revoked', 'dono' ) }</span> }
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
                            <div className="dp-data-action__title">{ __( 'Data export', 'dono' ) }</div>
                            <div className="dp-data-action__sub">
                                { __( 'Bundles donor record, donations, receipts, consents, and event log into a single JSON file.', 'dono' ) }
                            </div>
                        </div>
                        { donor && (
                            <button
                                type="button"
                                className="btn"
                                onClick={ () => downloadFile( `/dono/v1/admin/donors/${ donor.id }/export`, `dono-donor-${ donor.id }.json` ).catch( ( e ) => notify.error( e?.message || __( 'Could not export personal data.', 'dono' ) ) ) }
                            >
                                <IconDownload className="ic" />
                                { __( 'Export personal data', 'dono' ) }
                            </button>
                        ) }
                    </div>
                </div>
                <div className="dp-danger-foot">
                    <div className="dp-danger-foot__body">
                        <div className="dp-danger-foot__title">
                            <IconAlert width="14" height="14" />
                            { __( 'Redact donor', 'dono' ) }
                        </div>
                        <div className="dp-danger-foot__sub">
                            { __( 'Drops PII (name, email, phone, address, tax id) and sets redacted_at. Lifetime totals and donation records are kept for accounting. This cannot be undone.', 'dono' ) }
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
                            { donor?.redacted_at ? __( 'Already redacted', 'dono' ) : __( 'Redact donor', 'dono' ) }
                        </button>
                    </div>
                </div>
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
                                        { h.granted ? __( 'Granted', 'dono' ) : __( 'Revoked', 'dono' ) }
                                    </span>
                                    <span style={ { fontWeight: 500 } }>{ h.purpose }</span>
                                    <span className="dp-consent-log__src">{ h.source }</span>
                                    <span className="dp-consent-log__when">{ formatDateTime( h.occurred_at ) }</span>
                                </li>
                            ) ) }
                        </ul>
                    </div>
                </div>
            ) }
        </div>
    );
}
