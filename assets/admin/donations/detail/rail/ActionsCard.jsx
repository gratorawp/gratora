import { __ } from '@wordpress/i18n';

import { formatAmount, canRefundDonation, canResendReceipt, isDonorRedacted } from '../helpers';
import { IconRefund, IconMail, IconDownload, IconNote, IconCheck, IconAlert } from '../icons';
import { downloadFile } from '../../../_shared/download';
import notify from '../../../_shared/notify';

export default function ActionsCard( {
    donation, donor, receipts,
    onRefund, onResend, onAddNote,
    onMarkPaid, onMarkFailed,
} ) {
    const canRefund     = canRefundDonation( donation );
    const isRedacted    = isDonorRedacted( donation, donor );
    const canResend     = canResendReceipt( donation, donor );
    // `processing` is a bank debit on its way: it can still land, and it can
    // still bounce, so both actions stay open until it resolves.
    const canMarkPaid   = [ 'pending', 'processing', 'failed' ].includes( donation.status );
    const canMarkFailed = [ 'pending', 'processing' ].includes( donation.status );
    const primaryReceipt = ( receipts || [] ).find( ( r ) => ! r.voided );

    return (
        <div className="dd-rail-card">
            <div className="dd-rail-card__head">
                <span className="dd-rail-card__title">{ __( 'Actions', 'giveflow-fundraising-campaigns' ) }</span>
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
                            { __( 'Mark as paid', 'giveflow-fundraising-campaigns' ) }
                        </button>
                    ) }
                    { canMarkFailed && (
                        <button
                            type="button"
                            className="btn btn--block"
                            onClick={ onMarkFailed }
                        >
                            <IconAlert className="ic" />
                            { __( 'Mark as failed', 'giveflow-fundraising-campaigns' ) }
                        </button>
                    ) }
                    <button
                        type="button"
                        className="btn btn--danger btn--block"
                        disabled={ ! canRefund }
                        onClick={ onRefund }
                    >
                        <IconRefund className="ic" />
                        { __( 'Refund donation', 'giveflow-fundraising-campaigns' ) }
                    </button>
                    <button
                        type="button"
                        className="btn btn--block"
                        disabled={ ! canResend }
                        onClick={ onResend }
                    >
                        <IconMail className="ic" />
                        { isRedacted
                            ? __( 'Donor erased, cannot email', 'giveflow-fundraising-campaigns' )
                            : __( 'Resend receipt', 'giveflow-fundraising-campaigns' ) }
                    </button>
                    { primaryReceipt
                        ? (
                            <button
                                type="button"
                                className="btn btn--block"
                                onClick={ () => downloadFile( `/giveflow/v1/admin/receipts/${ primaryReceipt.id }/pdf`, `${ primaryReceipt.receipt_number }.pdf` ).catch( ( e ) => notify.error( e?.message || __( 'Could not download the receipt.', 'giveflow-fundraising-campaigns' ) ) ) }
                            >
                                <IconDownload className="ic" />
                                { __( 'Download receipt PDF', 'giveflow-fundraising-campaigns' ) }
                            </button>
                        )
                        : (
                            <button type="button" className="btn btn--block" disabled>
                                <IconDownload className="ic" />
                                { __( 'No receipt yet', 'giveflow-fundraising-campaigns' ) }
                            </button>
                        ) }
                    <button
                        type="button"
                        className="btn btn--block"
                        onClick={ onAddNote }
                    >
                        <IconNote className="ic" />
                        { __( 'Add note', 'giveflow-fundraising-campaigns' ) }
                    </button>
                </div>
                { canRefund && (
                    <div className="dd-rail-actions__hint">
                        { /* translators: %s: max refund amount */ }
                        { __( 'Refunds capped at', 'giveflow-fundraising-campaigns' ) }{ ' ' }
                        <strong>{ formatAmount( donation.refundable_cents, donation.currency ) }</strong>.
                    </div>
                ) }
            </div>
        </div>
    );
}
