import { __ } from '@wordpress/i18n';

import { formatAmount } from '../helpers';
import { IconRefund, IconMail, IconDownload, IconNote, IconCheck, IconAlert } from '../icons';
import { downloadFile } from '../../../_shared/download';
import notify from '../../../_shared/notify';

export default function ActionsCard( {
    donation, donor, receipts,
    onRefund, onResend, onAddNote,
    onMarkPaid, onMarkFailed,
} ) {
    const canRefund     = donation.refundable_cents > 0 && donation.status === 'paid';
    // An erased donor has no address left, so there is nowhere to send.
    const isRedacted    = !! ( donor?.redacted ?? donation.donor?.redacted );
    const canResend     = donation.status === 'paid' && ! isRedacted;
    // `processing` is a bank debit on its way: it can still land, and it can
    // still bounce, so both actions stay open until it resolves.
    const canMarkPaid   = [ 'pending', 'processing', 'failed' ].includes( donation.status );
    const canMarkFailed = [ 'pending', 'processing' ].includes( donation.status );
    const primaryReceipt = ( receipts || [] ).find( ( r ) => ! r.voided );

    return (
        <div className="dd-rail-card">
            <div className="dd-rail-card__head">
                <span className="dd-rail-card__title">{ __( 'Actions', 'dono-fundraising-platform' ) }</span>
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
                            { __( 'Mark as paid', 'dono-fundraising-platform' ) }
                        </button>
                    ) }
                    { canMarkFailed && (
                        <button
                            type="button"
                            className="btn btn--block"
                            onClick={ onMarkFailed }
                        >
                            <IconAlert className="ic" />
                            { __( 'Mark as failed', 'dono-fundraising-platform' ) }
                        </button>
                    ) }
                    <button
                        type="button"
                        className="btn btn--danger btn--block"
                        disabled={ ! canRefund }
                        onClick={ onRefund }
                    >
                        <IconRefund className="ic" />
                        { __( 'Refund donation', 'dono-fundraising-platform' ) }
                    </button>
                    <button
                        type="button"
                        className="btn btn--block"
                        disabled={ ! canResend }
                        onClick={ onResend }
                    >
                        <IconMail className="ic" />
                        { isRedacted
                            ? __( 'Donor erased, cannot email', 'dono-fundraising-platform' )
                            : __( 'Resend receipt', 'dono-fundraising-platform' ) }
                    </button>
                    { primaryReceipt
                        ? (
                            <button
                                type="button"
                                className="btn btn--block"
                                onClick={ () => downloadFile( `/dono/v1/admin/receipts/${ primaryReceipt.id }/pdf`, `${ primaryReceipt.receipt_number }.pdf` ).catch( ( e ) => notify.error( e?.message || __( 'Could not download the receipt.', 'dono-fundraising-platform' ) ) ) }
                            >
                                <IconDownload className="ic" />
                                { __( 'Download receipt PDF', 'dono-fundraising-platform' ) }
                            </button>
                        )
                        : (
                            <button type="button" className="btn btn--block" disabled>
                                <IconDownload className="ic" />
                                { __( 'No receipt yet', 'dono-fundraising-platform' ) }
                            </button>
                        ) }
                    <button
                        type="button"
                        className="btn btn--block"
                        onClick={ onAddNote }
                    >
                        <IconNote className="ic" />
                        { __( 'Add note', 'dono-fundraising-platform' ) }
                    </button>
                </div>
                { canRefund && (
                    <div className="dd-rail-actions__hint">
                        { /* translators: %s: max refund amount */ }
                        { __( 'Refunds capped at', 'dono-fundraising-platform' ) }{ ' ' }
                        <strong>{ formatAmount( donation.refundable_cents, donation.currency ) }</strong>.
                    </div>
                ) }
            </div>
        </div>
    );
}
