import { __ } from '@wordpress/i18n';
import { userCan } from '../../_shared/caps';

export { formatAmount, formatAmountCompact, amountEntry, formatDate, formatDateTime, timeAgo } from '../../_shared/format';
export { initials } from '@gratora/ui/utils/text';

export function donationStatusPill( status ) {
    switch ( status ) {
        case 'paid':           return { cls: 'is-ok',     label: __( 'Paid',     'gratora-donation-platform' ) };
        case 'pending':        return { cls: 'is-warn',   label: __( 'Pending',  'gratora-donation-platform' ) };
        case 'processing':     return { cls: 'is-info',   label: __( 'Processing', 'gratora-donation-platform' ) };
        case 'failed':         return { cls: 'is-error',  label: __( 'Failed',   'gratora-donation-platform' ) };
        case 'refunded':       return { cls: 'is-muted',  label: __( 'Refunded', 'gratora-donation-platform' ) };
        case 'partial_refund': return { cls: 'is-warn',   label: __( 'Partial',  'gratora-donation-platform' ) };
        case 'disputed':       return { cls: 'is-error',  label: __( 'Disputed', 'gratora-donation-platform' ) };
        default:               return { cls: 'is-muted',  label: status };
    }
}

export function refundStatusPill( status ) {
    switch ( status ) {
        case 'succeeded': return { cls: 'is-ok',    label: __( 'Issued',          'gratora-donation-platform' ) };
        case 'pending':   return { cls: 'is-warn',  label: __( 'Not settled yet', 'gratora-donation-platform' ) };
        case 'failed':    return { cls: 'is-error', label: __( 'Failed',          'gratora-donation-platform' ) };
        case 'reversed':  return { cls: 'is-muted', label: __( 'Reversed',        'gratora-donation-platform' ) };
        default:          return { cls: 'is-muted', label: status };
    }
}

export const CHANNEL_LABEL = {
    direct:        __( 'Direct',         'gratora-donation-platform' ),
    email:         __( 'Email',          'gratora-donation-platform' ),
    social:        __( 'Social',         'gratora-donation-platform' ),
    'paid-social': __( 'Paid social',    'gratora-donation-platform' ),
    organic:       __( 'Organic search', 'gratora-donation-platform' ),
    cpc:           __( 'Paid search',    'gratora-donation-platform' ),
    referral:      __( 'Referral',       'gratora-donation-platform' ),
    qr:            __( 'QR code',        'gratora-donation-platform' ),
    peer:          __( 'Peer-to-peer',   'gratora-donation-platform' ),
    embed:         __( 'Embedded form',  'gratora-donation-platform' ),
    manual:        __( 'Manual',         'gratora-donation-platform' ),
};

/**
 * A donation that settled, whether or not part of it has since gone back.
 *
 * DonationService::refund accepts partial_refund as readily as paid, and the
 * receipt for a partly refunded donation is still the donor's, so a control
 * that tests for 'paid' alone goes dead the moment the first refund lands.
 */
export const isSettled = ( donation ) =>
    donation?.status === 'paid' || donation?.status === 'partial_refund';

// The capability lives in the predicate, not in each card: the header, the
// rail, the receipt card and the refunds card all ask these, and gating only
// one of them leaves the same action offered three other ways.
/** A row an admin took off the list is not one to act money on from here. */
export const isTrashed = ( donation ) => !! donation?.trashed_at;

// A belt on top of the status rule rather than a replacement for it: nothing
// trashable is settled today, and this keeps the rail honest if that set ever
// widens.
export const canRefundDonation = ( donation ) =>
    userCan( 'refund_donations' ) && ( donation?.refundable_cents ?? 0 ) > 0
    && isSettled( donation ) && ! isTrashed( donation );

/** An erased donor has no address left, so there is nowhere to send. */
export const isDonorRedacted = ( donation, donor ) =>
    !! ( donor?.redacted ?? donation?.donor?.redacted );

export const canResendReceipt = ( donation, donor ) =>
    userCan( 'resend_receipt' ) && isSettled( donation )
    && ! isDonorRedacted( donation, donor ) && ! isTrashed( donation );
