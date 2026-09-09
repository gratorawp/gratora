import { __ } from '@wordpress/i18n';

import { formatAmount, formatDateTime, donationStatusPill, canRefundDonation, canResendReceipt } from './helpers';
import { IconMail, IconRefund } from './icons';
import { detailHref as campaignHref } from '../../_shared/format';

export default function Header( { donation, donor, onResendReceipt, onRefund, onBack } ) {
    const pill = donationStatusPill( donation.status );
    const isFullRefund    = donation.status === 'refunded';
    const isPartialRefund = donation.refunded_cents > 0 && donation.status !== 'refunded';
    const isRefundable    = canRefundDonation( donation );
    const canResend       = canResendReceipt( donation, donor );

    // Anonymity is about public displays, not about hiding a donor from the
    // org that has to receipt them.
    const name = donor?.name || donation.donor?.name || __( 'Donor', 'gratora' );

    return (
        <header className="dd-head">
            <div className="dd-crumbs">
                <button type="button" onClick={ onBack }>{ __( 'Fundraising', 'gratora' ) }</button>
                <span className="sep">›</span>
                <button type="button" onClick={ onBack }>{ __( 'Donations', 'gratora' ) }</button>
                <span className="sep">›</span>
                <span className="mono">{ donation.reference }</span>
            </div>

            <div className="dd-page-head">
                <div className="dd-page-head__left">
                    <h1>{ name }</h1>
                    <div className="dd-page-head__meta">
                        { !! donation.is_anonymous && (
                            <>
                                <span
                                    className="dd-pill is-muted"
                                    title={ __( 'Their name is hidden from public donor lists. It still appears here and on their receipt.', 'gratora' ) }
                                >
                                    { __( 'Anonymous publicly', 'gratora' ) }
                                </span>
                                <span className="dot-sep">·</span>
                            </>
                        ) }
                        <span className="mono">{ donation.reference }</span>
                        { donation.campaign && (
                            <>
                                <span className="dot-sep">·</span>
                                <span>{ __( 'Donated to', 'gratora' ) } <a href={ campaignHref( donation.campaign.id ) }>{ donation.campaign.title }</a></span>
                            </>
                        ) }
                        <span className="dot-sep">·</span>
                        <span style={ { textTransform: 'capitalize' } }>{ donation.gateway }</span>
                        { donation.paid_at && (
                            <>
                                <span className="dot-sep">·</span>
                                <span>{ formatDateTime( donation.paid_at ) }</span>
                            </>
                        ) }
                    </div>
                </div>

                <div className="dd-page-head__right">
                    <div className={ `dd-page-head__amount num${ isFullRefund ? ' is-strike' : '' }` }>
                        { formatAmount( donation.amount_cents, donation.currency ) }
                    </div>
                    { isPartialRefund && (
                        <div className="dd-page-head__amount-sub">
                            { __( 'Refunded', 'gratora' ) } <strong>{ formatAmount( donation.refunded_cents, donation.currency ) }</strong>
                            <span> · { __( 'net', 'gratora' ) } </span>
                            <strong className="num">{ formatAmount( donation.amount_cents - donation.refunded_cents, donation.currency ) }</strong>
                        </div>
                    ) }
                    <span className={ `dd-pill dd-pill--lg ${ pill.cls }` }>{ pill.label }</span>

                    <div className="dd-page-head__actions">
                        { canResend && (
                            <button type="button" className="btn" onClick={ onResendReceipt }>
                                <IconMail className="ic" />
                                { __( 'Resend receipt', 'gratora' ) }
                            </button>
                        ) }
                        { isRefundable && (
                            <button type="button" className="btn btn--danger" onClick={ onRefund }>
                                <IconRefund className="ic" />
                                { isPartialRefund
                                    ? __( 'Refund remaining', 'gratora' )
                                    : __( 'Refund', 'gratora' ) }
                            </button>
                        ) }
                    </div>
                </div>
            </div>
        </header>
    );
}
