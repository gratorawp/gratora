import { __ } from '@wordpress/i18n';

import { formatAmount, canRefundDonation, canResendReceipt, isDonorRedacted } from '../helpers';
import { IconRefund, IconMail, IconDownload, IconNote, IconCheck, IconAlert, IconTrash } from '../icons';
import { downloadFile } from '../../../_shared/download';
import notify from '../../../_shared/notify';
import { userCan } from '../../../_shared/caps';

export default function ActionsCard( {
    donation, donor, receipts,
    onRefund, onResend, onAddNote,
    onMarkPaid, onMarkFailed,
    onTrash, onRestore,
} ) {
    // What the routes behind these buttons enforce. Offering a button whose
    // request is refused makes the reader find out after filling a dialog in.
    const mayChange = userCan( 'refund_donations' );
    const mayResendReceipt = userCan( 'resend_receipt' );
    const mayNote   = userCan( 'edit_donations' );
    const mayReadPii = userCan( 'view_donors' );

    const canRefund     = canRefundDonation( donation );
    const isRedacted    = isDonorRedacted( donation, donor );
    const canResend     = canResendReceipt( donation, donor );
    // `processing` is a bank debit on its way: it can still land, and it can
    // still bounce, so both actions stay open until it resolves.
    const canMarkPaid   = mayChange && [ 'pending', 'processing', 'failed' ].includes( donation.status );
    const canMarkFailed = mayChange && [ 'pending', 'processing' ].includes( donation.status );
    const isTrashed     = !! donation.trashed_at;
    const primaryReceipt = ( receipts || [] ).find( ( r ) => ! r.voided );

    return (
        <div className="dd-rail-card">
            <div className="dd-rail-card__head">
                <span className="dd-rail-card__title">{ __( 'Actions', 'gratora-donation-platform' ) }</span>
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
                            { __( 'Mark as paid', 'gratora-donation-platform' ) }
                        </button>
                    ) }
                    { canMarkFailed && (
                        <button
                            type="button"
                            className="btn btn--block"
                            onClick={ onMarkFailed }
                        >
                            <IconAlert className="ic" />
                            { __( 'Mark as failed', 'gratora-donation-platform' ) }
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
                            { __( 'Refund donation', 'gratora-donation-platform' ) }
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
                                ? __( 'Donor erased, cannot email', 'gratora-donation-platform' )
                                : __( 'Resend receipt', 'gratora-donation-platform' ) }
                        </button>
                    ) }
                    { mayReadPii && ( primaryReceipt
                        ? (
                            <button
                                type="button"
                                className="btn btn--block"
                                onClick={ () => downloadFile( `/gratora/v1/admin/receipts/${ primaryReceipt.id }/pdf`, `${ primaryReceipt.receipt_number }.pdf` ).catch( ( e ) => notify.error( e?.message || __( 'Could not download the receipt.', 'gratora-donation-platform' ) ) ) }
                            >
                                <IconDownload className="ic" />
                                { __( 'Download receipt PDF', 'gratora-donation-platform' ) }
                            </button>
                        )
                        : (
                            <button type="button" className="btn btn--block" disabled>
                                <IconDownload className="ic" />
                                { __( 'No receipt yet', 'gratora-donation-platform' ) }
                            </button>
                        ) ) }
                    { mayNote && (
                        <button
                            type="button"
                            className="btn btn--block"
                            onClick={ onAddNote }
                        >
                            <IconNote className="ic" />
                            { __( 'Add note', 'gratora-donation-platform' ) }
                        </button>
                    ) }
                    { /* Stopping a live payment is charge-tier work, so it
                         rides the same capability the money actions do. */ }
                    { mayChange && ! isTrashed && (
                        <button
                            type="button"
                            className="btn btn--danger btn--block"
                            disabled={ ! donation.trashable }
                            onClick={ onTrash }
                        >
                            <IconTrash className="ic" />
                            { __( 'Move to trash', 'gratora-donation-platform' ) }
                        </button>
                    ) }
                    { mayChange && isTrashed && (
                        <button
                            type="button"
                            className="btn btn--block"
                            onClick={ onRestore }
                        >
                            <IconRefund className="ic" />
                            { __( 'Restore', 'gratora-donation-platform' ) }
                        </button>
                    ) }
                </div>
                { /* The reason, not just a dead button: an admin who cannot
                     trash a row needs to know which rule is holding it. */ }
                { mayChange && ! isTrashed && ! donation.trashable && donation.untrashable_reason && (
                    <div className="dd-rail-actions__hint">{ donation.untrashable_reason }</div>
                ) }
                { canRefund && (
                    <div className="dd-rail-actions__hint">
                        { /* translators: %s: max refund amount */ }
                        { __( 'Refunds capped at', 'gratora-donation-platform' ) }{ ' ' }
                        <strong>{ formatAmount( donation.refundable_cents, donation.currency ) }</strong>.
                    </div>
                ) }
            </div>
        </div>
    );
}
