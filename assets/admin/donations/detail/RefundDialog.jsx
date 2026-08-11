import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Modal } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

import { formatAmount, currencyDecimals, formatDate } from './helpers';
import { IconAlert } from './icons';

// A schedule in one of these will not charge again, so it needs no warning.
const PLAN_ENDED = [ 'cancelled', 'expired' ];

export default function RefundDialog( { donation, onClose, onSuccess, plan = null } ) {
    const planLive = !! plan && ! PLAN_ENDED.includes( plan.status );
    const maxCents = donation.refundable_cents;
    // Entry decimals follow the currency (JPY none, BHD three); the stored value
    // stays minor units (major x 100), so /100 and *100 are unchanged.
    const dp   = currencyDecimals( donation.currency );
    const step = dp > 0 ? '0.' + '0'.repeat( dp - 1 ) + '1' : '1';
    const [ amount, setAmount ] = useState( ( maxCents / 100 ).toFixed( dp ) );
    const [ reason, setReason ] = useState( '' );
    const [ saving, setSaving ] = useState( false );
    const [ error, setError ]   = useState( null );
    // Unchecked: refusing to decide leaves the schedule exactly as it is.
    const [ cancelPlan, setCancelPlan ] = useState( false );

    const submit = async ( e ) => {
        e.preventDefault();
        const cents = Math.round( Number( amount ) * 100 );
        if ( ! cents || cents <= 0 || cents > maxCents ) {
            setError( sprintf( /* translators: 1: minimum amount, 2: maximum amount */ __( 'Amount must be between %1$s and %2$s', 'dono' ), formatAmount( 1, donation.currency ), formatAmount( maxCents, donation.currency ) ) );
            return;
        }
        const data = { amount_cents: cents, reason: reason.trim() || undefined };
        if ( planLive && cancelPlan ) {
            data.cancel_plan = true;
        }
        setSaving( true );
        setError( null );
        try {
            const result = await apiFetch( {
                path:   `/dono/v1/admin/donations/${ donation.reference }/refund`,
                method: 'POST',
                data,
            } );
            onSuccess( result );
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
                        step={ step }
                        min={ step }
                        max={ ( maxCents / 100 ).toFixed( dp ) }
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
                { planLive && (
                    <>
                        <div className="dd-banner dd-banner--warn" style={ { gridColumn: '1 / -1' } } role="status">
                            <IconAlert className="dd-banner__icon" width="20" height="20" />
                            <div className="dd-banner__body">
                                <strong>{ __( 'The recurring schedule is still running.', 'dono' ) }</strong>{ ' ' }
                                { plan.next_payment_at
                                    ? sprintf(
                                        /* translators: %s: date of the next scheduled payment */
                                        __( 'Refunding this donation does not stop it, and the donor will be charged again on %s.', 'dono' ),
                                        formatDate( plan.next_payment_at )
                                    )
                                    : __( 'Refunding this donation does not stop it, and the donor will be charged again.', 'dono' ) }
                            </div>
                        </div>
                        <label style={ { gridColumn: '1 / -1', flexDirection: 'row', alignItems: 'flex-start', gap: 8 } }>
                            <input
                                type="checkbox"
                                checked={ cancelPlan }
                                onChange={ ( e ) => setCancelPlan( e.target.checked ) }
                                style={ { marginTop: 2 } }
                            />
                            <span>
                                { __( 'Cancel the recurring schedule as well', 'dono' ) }
                                <span style={ { display: 'block', marginTop: 2, fontSize: 12.5 } }>
                                    { __( 'The donor is emailed when a schedule is cancelled.', 'dono' ) }
                                </span>
                            </span>
                        </label>
                    </>
                ) }
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
