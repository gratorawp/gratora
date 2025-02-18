import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Modal } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

import { formatAmount } from './helpers';

export default function RefundDialog( { donation, onClose, onSuccess } ) {
    const maxCents = donation.refundable_cents;
    const [ amount, setAmount ] = useState( ( maxCents / 100 ).toFixed( 2 ) );
    const [ reason, setReason ] = useState( '' );
    const [ saving, setSaving ] = useState( false );
    const [ error, setError ]   = useState( null );

    const submit = async ( e ) => {
        e.preventDefault();
        const cents = Math.round( Number( amount ) * 100 );
        if ( ! cents || cents <= 0 || cents > maxCents ) {
            setError( sprintf( /* translators: 1: minimum amount, 2: maximum amount */ __( 'Amount must be between %1$s and %2$s', 'dono' ), formatAmount( 1, donation.currency ), formatAmount( maxCents, donation.currency ) ) );
            return;
        }
        setSaving( true );
        setError( null );
        try {
            await apiFetch( {
                path:   `/dono/v1/admin/donations/${ donation.reference }/refund`,
                method: 'POST',
                data:   { amount_cents: cents, reason: reason.trim() || undefined },
            } );
            onSuccess();
        } catch ( err ) {
            setError( err?.message || __( 'Refund failed', 'dono' ) );
        } finally {
            setSaving( false );
        }
    };

    return (
        <Modal
            title={ __( 'Issue refund', 'dono' ) }
            onRequestClose={ onClose }
            className="dd-modal"
        >
            <form onSubmit={ submit } className="dd-edit-form">
                <p style={ { gridColumn: '1 / -1', color: 'var(--dd-text-muted, #6b7280)', fontSize: 13, marginTop: 0 } }>
                    { sprintf(
                        /* translators: %s: amount */ __( 'Up to %s can be refunded back to the donor. Stripe refunds typically settle in 5-10 business days.', 'dono' ),
                        formatAmount( maxCents, donation.currency )
                    ) }
                </p>
                <label>
                    { __( 'Amount', 'dono' ) }
                    <input
                        className="dono-input"
                        type="number"
                        step="0.01"
                        min="0.01"
                        max={ ( maxCents / 100 ).toFixed( 2 ) }
                        value={ amount }
                        onChange={ ( e ) => setAmount( e.target.value ) }
                    />
                </label>
                <label>
                    { __( 'Reason', 'dono' ) }
                    <input
                        className="dono-input"
                        type="text"
                        value={ reason }
                        onChange={ ( e ) => setReason( e.target.value ) }
                        placeholder={ __( 'optional', 'dono' ) }
                        maxLength={ 200 }
                    />
                </label>
                { error && <div className="dd-edit-form__error">{ error }</div> }
                <div className="dd-edit-form__actions">
                    <button type="button" className="btn" onClick={ onClose } disabled={ saving }>
                        { __( 'Cancel', 'dono' ) }
                    </button>
                    <button type="submit" className="btn btn--danger" disabled={ saving }>
                        { saving ? __( 'Refunding…', 'dono' ) : __( 'Issue refund', 'dono' ) }
                    </button>
                </div>
            </form>
        </Modal>
    );
}
