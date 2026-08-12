import { __ } from '@wordpress/i18n';

import { formatDateTime } from '../helpers';
import { IconReceipt, IconDownload } from '../icons';
import { downloadFile } from '../../../_shared/download';
import notify from '../../../_shared/notify';

export default function ReceiptCard( { donation, receipts, onResend } ) {
    if ( ! receipts || receipts.length === 0 ) {
        if ( donation.status !== 'paid' ) return null;
        return (
            <div className="dd-card">
                <div className="dd-card__body">
                    <div className="dd-receipt-row">
                        <span className="dd-receipt-row__icon"><IconReceipt width="18" height="18" /></span>
                        <div className="dd-receipt-row__main">
                            <span className="dd-pill is-warn">{ __( 'Receipt queued', 'dono-fundraising-platform' ) }</span>
                            <div className="dd-receipt-row__meta">
                                { __( 'A receipt will be generated as soon as the renderer runs.', 'dono-fundraising-platform' ) }
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        );
    }

    return (
        <div className="dd-card">
            <div className="dd-card__body">
                { receipts.map( ( r ) => {
                    return (
                    <div key={ r.id } className="dd-receipt-row">
                        <span className="dd-receipt-row__icon"><IconReceipt width="18" height="18" /></span>
                        <div className="dd-receipt-row__main">
                            <span className="dd-receipt-row__line">
                                <span className={ `dd-receipt-row__num mono${ r.voided ? ' is-strike' : '' }` }>{ r.receipt_number }</span>
                            </span>
                            <div className="dd-receipt-row__meta">
                                { __( 'Issued', 'dono-fundraising-platform' ) } <strong>{ formatDateTime( r.issued_at ) }</strong>
                                { r.sent_to_email_at && (
                                    <>
                                        { ' · ' }
                                        { __( 'emailed', 'dono-fundraising-platform' ) } <strong>{ formatDateTime( r.sent_to_email_at ) }</strong>
                                    </>
                                ) }
                                { r.voided && (
                                    <>{ ' · ' }<span style={ { color: 'var(--dd-red, #b42318)' } }>{ __( 'Voided', 'dono-fundraising-platform' ) }</span></>
                                ) }
                            </div>
                        </div>
                        <div className="dd-receipt-row__actions">
                            { ! r.voided && (
                                <button type="button" className="btn btn--sm" onClick={ onResend }>
                                    { __( 'Resend', 'dono-fundraising-platform' ) }
                                </button>
                            ) }
                            <button
                                type="button"
                                className="btn btn--sm"
                                onClick={ () => downloadFile( `/dono/v1/admin/receipts/${ r.id }/pdf`, `${ r.receipt_number }.pdf` ).catch( ( e ) => notify.error( e?.message || __( 'Could not download the receipt.', 'dono-fundraising-platform' ) ) ) }
                            >
                                <IconDownload className="ic" />
                                { __( 'PDF', 'dono-fundraising-platform' ) }
                            </button>
                        </div>
                    </div>
                    );
                } ) }
            </div>
        </div>
    );
}
