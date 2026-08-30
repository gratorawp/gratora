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
                path:   `/fundkit/v1/admin/donors/${ donor.id }/redact`,
                method: 'POST',
                data:   { confirmation: typed.trim() },
            } );
            onDone();
        } catch ( err ) {
            setError( err?.message || __( 'Redact failed', 'fundkit-fundraising-campaigns' ) );
        } finally {
            setSaving( false );
        }
    };

    return (
        <Modal title={ __( 'Redact this donor', 'fundkit-fundraising-campaigns' ) } onRequestClose={ onClose } className="dp-modal">
            <form onSubmit={ submit } className="dp-edit-form">
                <p style={ { gridColumn: '1 / -1', color: '#6b7280', fontSize: 13, marginTop: 0 } }>
                    { __( 'PII (name, email, phone, address, tax id, notes) will be permanently removed, and any active recurring plan is cancelled at the gateway. Lifetime totals, donations, and receipts are retained for accounting. This cannot be undone.', 'fundkit-fundraising-campaigns' ) }
                </p>
                <label style={ { gridColumn: '1 / -1' } }>
                    { sprintf( /* translators: %s: confirmation word */ __( 'Type %s to confirm', 'fundkit-fundraising-campaigns' ), expected ) }
                    <input className="fundkit-input"
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
                        { __( 'Cancel', 'fundkit-fundraising-campaigns' ) }
                    </button>
                    <button type="submit" className="btn btn--danger" disabled={ saving || ! matches }>
                        { saving ? __( 'Redacting…', 'fundkit-fundraising-campaigns' ) : __( 'Redact donor', 'fundkit-fundraising-campaigns' ) }
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

    const setPublicHidden = async ( hidden ) => {
        setHiding( true );
        try {
            await apiFetch( {
                path:   `/fundkit/v1/admin/donors/${ donor.id }`,
                method: 'PATCH',
                data:   { public_hidden: hidden },
            } );
            onChanged && onChanged();
        } catch ( err ) {
            notify.error( err?.message || __( 'Could not change this.', 'fundkit-fundraising-campaigns' ) );
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
                            title={ __( 'No consent records yet', 'fundkit-fundraising-campaigns' ) }
                            body={ __( 'Each donation captures opt-ins for the purposes you configure. They land here for audit and right-to-withdraw requests.', 'fundkit-fundraising-campaigns' ) }
                        />
                    )
                    : (
                        <div style={ { overflowX: 'auto' } }>
                            <table className="dp-table">
                                <thead>
                                    <tr>
                                        <th>{ __( 'Purpose',     'fundkit-fundraising-campaigns' ) }</th>
                                        <th>{ __( 'Status',      'fundkit-fundraising-campaigns' ) }</th>
                                        <th>{ __( 'Granted at',  'fundkit-fundraising-campaigns' ) }</th>
                                        <th>{ __( 'Source',      'fundkit-fundraising-campaigns' ) }</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    { current.map( ( c ) => (
                                        <tr key={ c.purpose }>
                                            <td>{ c.purpose }</td>
                                            <td>
                                                { ! c.occurred_at
                                                    ? <span className="dp-pill is-muted">{ __( 'No response', 'fundkit-fundraising-campaigns' ) }</span>
                                                    : c.granted
                                                        ? <span className="dp-pill is-ok">{ __( 'Granted', 'fundkit-fundraising-campaigns' ) }</span>
                                                        : <span className="dp-pill is-muted">{ __( 'Revoked', 'fundkit-fundraising-campaigns' ) }</span> }
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
                            <div className="dp-data-action__title">{ __( 'Public visibility', 'fundkit-fundraising-campaigns' ) }</div>
                            <div className="dp-data-action__sub">
                                { donor?.public_hidden
                                    ? __( 'Hidden. This donor does not appear in supporter walls, recent donations or top donor lists, and their picture and message are not shown. Their donations still count toward campaign totals.', 'fundkit-fundraising-campaigns' )
                                    : __( 'Visible. This donor can appear by name in supporter walls, recent donations and top donor lists, with their picture and any public message.', 'fundkit-fundraising-campaigns' ) }
                            </div>
                        </div>
                        { donor && ! donor.redacted_at && (
                            <button
                                type="button"
                                className="btn"
                                disabled={ hiding }
                                onClick={ () => setPublicHidden( ! donor.public_hidden ) }
                            >
                                { donor.public_hidden ? __( 'Show publicly', 'fundkit-fundraising-campaigns' ) : __( 'Hide from public pages', 'fundkit-fundraising-campaigns' ) }
                            </button>
                        ) }
                    </div>

                    <div className="dp-data-action">
                        <div className="dp-data-action__body">
                            <div className="dp-data-action__title">{ __( 'Data export', 'fundkit-fundraising-campaigns' ) }</div>
                            <div className="dp-data-action__sub">
                                { __( 'Bundles donor record, donations, receipts, consents, and event log into a single JSON file.', 'fundkit-fundraising-campaigns' ) }
                            </div>
                        </div>
                        { donor && (
                            <button
                                type="button"
                                className="btn"
                                onClick={ () => downloadFile( `/fundkit/v1/admin/donors/${ donor.id }/export`, `fundkit-donor-${ donor.id }.json` ).catch( ( e ) => notify.error( e?.message || __( 'Could not export personal data.', 'fundkit-fundraising-campaigns' ) ) ) }
                            >
                                <IconDownload className="ic" />
                                { __( 'Export personal data', 'fundkit-fundraising-campaigns' ) }
                            </button>
                        ) }
                    </div>
                </div>
                <div className="dp-danger-foot">
                    <div className="dp-danger-foot__body">
                        <div className="dp-danger-foot__title">
                            <IconAlert width="14" height="14" />
                            { __( 'Redact donor', 'fundkit-fundraising-campaigns' ) }
                        </div>
                        <div className="dp-danger-foot__sub">
                            { __( 'Drops PII (name, email, phone, address, tax id), cancels any active recurring plan at the gateway, and sets redacted_at. Lifetime totals and donation records are kept for accounting. This cannot be undone.', 'fundkit-fundraising-campaigns' ) }
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
                            { donor?.redacted_at ? __( 'Already redacted', 'fundkit-fundraising-campaigns' ) : __( 'Redact donor', 'fundkit-fundraising-campaigns' ) }
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
                                        { h.granted ? __( 'Granted', 'fundkit-fundraising-campaigns' ) : __( 'Revoked', 'fundkit-fundraising-campaigns' ) }
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
