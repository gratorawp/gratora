import { __ } from '@wordpress/i18n';

import { formatAmount, formatDateTime, donationStatusPill } from './helpers';
import { IconMail, IconRefund } from './icons';
import { detailHref as campaignHref } from '../../_shared/format';

export default function Header( { donation, donor, onResendReceipt, onRefund, onBack } ) {
    const pill = donationStatusPill( donation.status );
    const isFullRefund    = donation.status === 'refunded';
    const isPartialRefund = donation.refunded_cents > 0 && donation.status !== 'refunded';
    const isRefundable    = donation.refundable_cents > 0 && donation.status === 'paid';
    // An erased donor has no address left, so there is nowhere to send.
    const isRedacted      = !! ( donor?.redacted ?? donation.donor?.redacted );
    const canResend       = donation.status === 'paid' && ! isRedacted;

    const name = donation.is_anonymous
        ? __( 'Anonymous donor', 'dono' )
        : (donor?.name || donation.donor?.name || __( 'Donor', 'dono' ));

    return (
        <header className="dd-head">
            <div className="dd-crumbs">
                <button type="button" onClick={ onBack }>{ __( 'Dono', 'dono' ) }</button>
                <span className="sep">›</span>
                <button type="button" onClick={ onBack }>{ __( 'Donations', 'dono' ) }</button>
                <span className="sep">›</span>
                <span className="mono">{ donation.reference }</span>
            </div>

            <div className="dd-page-head">
                <div className="dd-page-head__left">
                    <h1 className={ donation.is_anonymous ? 'is-anon' : '' }>{ name }</h1>
                    <div className="dd-page-head__meta">
                        <span className="mono">{ donation.reference }</span>
                        { donation.campaign && (
                            <>
                                <span className="dot-sep">·</span>
                                <span>{ __( 'Donated to', 'dono' ) } <a href={ campaignHref( donation.campaign.id ) }>{ donation.campaign.title }</a></span>
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
                            { __( 'Refunded', 'dono' ) } <strong>{ formatAmount( donation.refunded_cents, donation.currency ) }</strong>
                            <span> · { __( 'net', 'dono' ) } </span>
                            <strong className="num">{ formatAmount( donation.amount_cents - donation.refunded_cents, donation.currency ) }</strong>
                        </div>
                    ) }
                    <span className={ `dd-pill dd-pill--lg ${ pill.cls }` }>{ pill.label }</span>

                    <div className="dd-page-head__actions">
                        { canResend && (
                            <button type="button" className="btn" onClick={ onResendReceipt }>
                                <IconMail className="ic" />
                                { __( 'Resend receipt', 'dono' ) }
                            </button>
                        ) }
                        { isRefundable && (
                            <button type="button" className="btn btn--danger" onClick={ onRefund }>
                                <IconRefund className="ic" />
                                { isPartialRefund
                                    ? __( 'Refund remaining', 'dono' )
                                    : __( 'Refund', 'dono' ) }
                            </button>
                        ) }
                    </div>
                </div>
            </div>
        </header>
    );
}
