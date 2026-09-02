import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Modal } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

import { formatAmount, amountEntry, formatDate } from './helpers';
import AmountInput from '../../_shared/components/AmountInput';
import { IconAlert } from './icons';

// A schedule in one of these will not charge again, so it needs no warning.
const PLAN_ENDED = [ 'cancelled', 'expired' ];

export default function RefundDialog( { donation, onClose, onSuccess, plan = null } ) {
    const planLive = !! plan && ! PLAN_ENDED.includes( plan.status );
    const maxCents = donation.refundable_cents;
    const pendingCents = donation.refund_pending_cents || 0;
    const { step } = amountEntry( donation.currency );
    const [ amount, setAmount ] = useState( maxCents / 100 );
    const [ reason, setReason ] = useState( '' );
    const [ saving, setSaving ] = useState( false );
    const [ error, setError ]   = useState( null );
    // Unchecked: refusing to decide leaves the schedule exactly as it is.
    const [ cancelPlan, setCancelPlan ] = useState( false );

    const submit = async ( e ) => {
        e.preventDefault();
        const cents = Math.round( Number( amount ) * 100 );
        if ( ! cents || cents <= 0 || cents > maxCents ) {
            setError( sprintf( /* translators: 1: minimum amount, 2: maximum amount */ __( 'Amount must be between %1$s and %2$s', 'fundraising-toolkit' ), formatAmount( 1, donation.currency ), formatAmount( maxCents, donation.currency ) ) );
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
                path:   `/fundkit/v1/admin/donations/${ donation.reference }/refund`,
                method: 'POST',
                data,
            } );
            onSuccess( result );
        } catch ( err ) {
            setError( err?.message || __( 'Refund failed', 'fundraising-toolkit' ) );
        } finally {
            setSaving( false );
        }
    };

    return (
        <Modal
            title={ __( 'Issue refund', 'fundraising-toolkit' ) }
            onRequestClose={ onClose }
            className="dd-modal"
        >
            <form onSubmit={ submit } className="dd-edit-form">
                <p style={ { gridColumn: '1 / -1', color: 'var(--dd-text-muted, #6b7280)', fontSize: 13, marginTop: 0 } }>
                    { sprintf(
                        /* translators: %s: amount */ __( 'Up to %s can be refunded back to the donor. Stripe refunds typically settle in 5-10 business days.', 'fundraising-toolkit' ),
                        formatAmount( maxCents, donation.currency )
                    ) }
                    { pendingCents > 0 && ' ' + sprintf(
                        /* translators: %s: amount already sent to the gateway */
                        __( '%s is already on its way back to the donor and has not settled yet, so it is not offered here.', 'fundraising-toolkit' ),
                        formatAmount( pendingCents, donation.currency )
                    ) }
                </p>
                <label>
                    { __( 'Amount', 'fundraising-toolkit' ) }
                    <AmountInput
                        value={ amount }
                        onChange={ setAmount }
                        currency={ donation.currency }
                        min={ step }
                        max={ maxCents / 100 }
                        autoFocus
                    />
                </label>
                <label>
                    { __( 'Reason', 'fundraising-toolkit' ) }
                    <input
                        className="fundkit-input"
                        type="text"
                        value={ reason }
                        onChange={ ( e ) => setReason( e.target.value ) }
                        placeholder={ __( 'optional', 'fundraising-toolkit' ) }
                        maxLength={ 200 }
                    />
                </label>
                { planLive && (
                    <>
                        <div className="dd-banner dd-banner--warn" style={ { gridColumn: '1 / -1' } } role="status">
                            <IconAlert className="dd-banner__icon" width="20" height="20" />
                            <div className="dd-banner__body">
                                <strong>{ __( 'The recurring schedule is still running.', 'fundraising-toolkit' ) }</strong>{ ' ' }
                                { plan.next_payment_at
                                    ? sprintf(
                                        /* translators: %s: date of the next scheduled payment */
                                        __( 'Refunding this donation does not stop it, and the donor will be charged again on %s.', 'fundraising-toolkit' ),
                                        formatDate( plan.next_payment_at )
                                    )
                                    : __( 'Refunding this donation does not stop it, and the donor will be charged again.', 'fundraising-toolkit' ) }
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
                                { __( 'Cancel the recurring schedule as well', 'fundraising-toolkit' ) }
                                <span style={ { display: 'block', marginTop: 2, fontSize: 12.5 } }>
                                    { __( 'The donor is emailed when a schedule is cancelled.', 'fundraising-toolkit' ) }
                                </span>
                            </span>
                        </label>
                    </>
                ) }
                { error && <div className="dd-edit-form__error">{ error }</div> }
                <div className="dd-edit-form__actions">
                    <button type="button" className="btn" onClick={ onClose } disabled={ saving }>
                        { __( 'Cancel', 'fundraising-toolkit' ) }
                    </button>
                    <button type="submit" className="btn btn--danger" disabled={ saving }>
                        { saving ? __( 'Refunding…', 'fundraising-toolkit' ) : __( 'Issue refund', 'fundraising-toolkit' ) }
                    </button>
                </div>
            </form>
        </Modal>
    );
}
