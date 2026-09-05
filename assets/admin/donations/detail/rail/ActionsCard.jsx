import { __ } from '@wordpress/i18n';

import { formatAmount, canRefundDonation, canResendReceipt, isDonorRedacted } from '../helpers';
import { IconRefund, IconMail, IconDownload, IconNote, IconCheck, IconAlert } from '../icons';
import { downloadFile } from '../../../_shared/download';
import notify from '../../../_shared/notify';
import { userCan } from '../../../_shared/caps';

export default function ActionsCard( {
    donation, donor, receipts,
    onRefund, onResend, onAddNote,
    onMarkPaid, onMarkFailed,
} ) {
    // What the routes behind these buttons enforce. Offering a button whose
    // request is refused makes the reader find out after filling a dialog in.
    const mayChange = userCan( 'refund_donations' );
    const mayResendReceipt = userCan( 'resend_receipt' );
    const mayNote   = userCan( 'edit_donations' );
    const mayReadPii = userCan( 'view_donors' );

    const canRefund     = mayChange && canRefundDonation( donation );
    const isRedacted    = isDonorRedacted( donation, donor );
    const canResend     = mayResendReceipt && canResendReceipt( donation, donor );
    // `processing` is a bank debit on its way: it can still land, and it can
    // still bounce, so both actions stay open until it resolves.
    const canMarkPaid   = mayChange && [ 'pending', 'processing', 'failed' ].includes( donation.status );
    const canMarkFailed = mayChange && [ 'pending', 'processing' ].includes( donation.status );
    const primaryReceipt = ( receipts || [] ).find( ( r ) => ! r.voided );

    return (
        <div className="dd-rail-card">
            <div className="dd-rail-card__head">
                <span className="dd-rail-card__title">{ __( 'Actions', 'fundraising-toolkit' ) }</span>
            </div>
            <div className="dd-rail-card__body">
                <div className="dd-rail-actions">
                    { canMarkPaid && (
                        <button
                            type="button"
                            className="btn btn--primary btn--block"
                            onClick={ onMarkPaid }
                        >
                            <IconCheck className="ic" />
                            { __( 'Mark as paid', 'fundraising-toolkit' ) }
                        </button>
                    ) }
                    { canMarkFailed && (
                        <button
                            type="button"
                            className="btn btn--block"
                            onClick={ onMarkFailed }
                        >
                            <IconAlert className="ic" />
                            { __( 'Mark as failed', 'fundraising-toolkit' ) }
                        </button>
                    ) }
                    { mayChange && (
                        <button
                            type="button"
                            className="btn btn--danger btn--block"
                            disabled={ ! canRefund }
                            onClick={ onRefund }
                        >
                            <IconRefund className="ic" />
                            { __( 'Refund donation', 'fundraising-toolkit' ) }
                        </button>
                    ) }
                    { mayResendReceipt && (
                        <button
                            type="button"
                            className="btn btn--block"
                            disabled={ ! canResend }
                            onClick={ onResend }
                        >
                            <IconMail className="ic" />
                            { isRedacted
                                ? __( 'Donor erased, cannot email', 'fundraising-toolkit' )
                                : __( 'Resend receipt', 'fundraising-toolkit' ) }
                        </button>
                    ) }
                    { mayReadPii && ( primaryReceipt
                        ? (
                            <button
                                type="button"
                                className="btn btn--block"
                                onClick={ () => downloadFile( `/fundkit/v1/admin/receipts/${ primaryReceipt.id }/pdf`, `${ primaryReceipt.receipt_number }.pdf` ).catch( ( e ) => notify.error( e?.message || __( 'Could not download the receipt.', 'fundraising-toolkit' ) ) ) }
                            >
                                <IconDownload className="ic" />
                                { __( 'Download receipt PDF', 'fundraising-toolkit' ) }
                            </button>
                        )
                        : (
                            <button type="button" className="btn btn--block" disabled>
                                <IconDownload className="ic" />
                                { __( 'No receipt yet', 'fundraising-toolkit' ) }
                            </button>
                        ) ) }
                    { mayNote && (
                        <button
                            type="button"
                            className="btn btn--block"
                            onClick={ onAddNote }
                        >
                            <IconNote className="ic" />
                            { __( 'Add note', 'fundraising-toolkit' ) }
                        </button>
                    ) }
                </div>
                { canRefund && (
                    <div className="dd-rail-actions__hint">
                        { /* translators: %s: max refund amount */ }
                        { __( 'Refunds capped at', 'fundraising-toolkit' ) }{ ' ' }
                        <strong>{ formatAmount( donation.refundable_cents, donation.currency ) }</strong>.
                    </div>
                ) }
            </div>
        </div>
    );
}
