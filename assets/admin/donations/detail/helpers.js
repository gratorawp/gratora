// Money in minor units; REST dates are MySQL strings in UTC with no zone
// marker, which a browser reads as local time. parseTimestamp marks them.
import { __ } from '@wordpress/i18n';
import { parseTimestamp } from '@gratora/ui/utils/format';
import { userCan } from '../../_shared/caps';

export { formatAmount, formatAmountCompact, currencyDecimals, amountEntry, timeAgo } from '../../_shared/format';

export function formatDateTime( iso ) {
    if ( ! iso ) return '-';
    const d = parseTimestamp( iso );
    if ( Number.isNaN( d.getTime() ) ) return iso;
    return d.toLocaleString( undefined, {
        month: 'short', day: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    } );
}

export function formatDateShort( iso ) {
    if ( ! iso ) return '-';
    const d = parseTimestamp( iso );
    if ( Number.isNaN( d.getTime() ) ) return iso;
    return d.toLocaleDateString( undefined, { month: 'short', day: '2-digit' } );
}

export function formatDate( iso ) {
    if ( ! iso ) return '-';
    const d = parseTimestamp( iso );
    if ( Number.isNaN( d.getTime() ) ) return iso;
    return d.toLocaleDateString( undefined, { year: 'numeric', month: 'short', day: '2-digit' } );
}


export function initials( name ) {
    if ( ! name ) return '?';
    const parts = String( name ).trim().split( /\s+/ ).slice( 0, 2 );
    return parts.map( ( p ) => p[ 0 ] || '' ).join( '' ).toUpperCase() || '?';
}

export function donationStatusPill( status ) {
    switch ( status ) {
        case 'paid':           return { cls: 'is-ok',     label: __( 'Paid',     'gratora' ) };
        case 'pending':        return { cls: 'is-warn',   label: __( 'Pending',  'gratora' ) };
        case 'processing':     return { cls: 'is-info',   label: __( 'Processing', 'gratora' ) };
        case 'failed':         return { cls: 'is-error',  label: __( 'Failed',   'gratora' ) };
        case 'refunded':       return { cls: 'is-muted',  label: __( 'Refunded', 'gratora' ) };
        case 'partial_refund': return { cls: 'is-warn',   label: __( 'Partial',  'gratora' ) };
        case 'disputed':       return { cls: 'is-error',  label: __( 'Disputed', 'gratora' ) };
        default:               return { cls: 'is-muted',  label: status };
    }
}

export function refundStatusPill( status ) {
    switch ( status ) {
        case 'succeeded': return { cls: 'is-ok',    label: __( 'Issued',          'gratora' ) };
        case 'pending':   return { cls: 'is-warn',  label: __( 'Not settled yet', 'gratora' ) };
        case 'failed':    return { cls: 'is-error', label: __( 'Failed',          'gratora' ) };
        case 'reversed':  return { cls: 'is-muted', label: __( 'Reversed',        'gratora' ) };
        default:          return { cls: 'is-muted', label: status };
    }
}

export const CHANNEL_LABEL = {
    direct:        __( 'Direct',         'gratora' ),
    email:         __( 'Email',          'gratora' ),
    social:        __( 'Social',         'gratora' ),
    'paid-social': __( 'Paid social',    'gratora' ),
    organic:       __( 'Organic search', 'gratora' ),
    cpc:           __( 'Paid search',    'gratora' ),
    referral:      __( 'Referral',       'gratora' ),
    qr:            __( 'QR code',        'gratora' ),
    peer:          __( 'Peer-to-peer',   'gratora' ),
    manual:        __( 'Manual',         'gratora' ),
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
export const canRefundDonation = ( donation ) =>
    userCan( 'refund_donations' ) && ( donation?.refundable_cents ?? 0 ) > 0 && isSettled( donation );

/** An erased donor has no address left, so there is nowhere to send. */
export const isDonorRedacted = ( donation, donor ) =>
    !! ( donor?.redacted ?? donation?.donor?.redacted );

export const canResendReceipt = ( donation, donor ) =>
    userCan( 'resend_receipt' ) && isSettled( donation ) && ! isDonorRedacted( donation, donor );
