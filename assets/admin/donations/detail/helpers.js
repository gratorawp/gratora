// Money in minor units; REST dates are MySQL strings in UTC with no zone
// marker, which a browser reads as local time. parseTimestamp marks them.
import { __, sprintf } from '@wordpress/i18n';
import { parseTimestamp } from '@giveflow/ui/utils/format';

export { formatAmount, formatAmountCompact, currencyDecimals, amountEntry } from '../../_shared/format';

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

export function timeAgo( iso ) {
    if ( ! iso ) return '-';
    const d = parseTimestamp( iso );
    if ( Number.isNaN( d.getTime() ) ) return iso;
    const diff = Math.max( 0, ( Date.now() - d.getTime() ) / 1000 );
    if ( diff < 60 )      return __( 'just now', 'giveflow-fundraising-campaigns' );
    if ( diff < 3600 )    return sprintf( /* translators: %d: number of minutes */ __( '%dm ago', 'giveflow-fundraising-campaigns' ),  Math.floor( diff / 60 ) );
    if ( diff < 86400 )   return sprintf( /* translators: %d: number of hours */ __( '%dh ago', 'giveflow-fundraising-campaigns' ),  Math.floor( diff / 3600 ) );
    if ( diff < 604800 )  return sprintf( /* translators: %d: number of days */ __( '%dd ago', 'giveflow-fundraising-campaigns' ),  Math.floor( diff / 86400 ) );
    if ( diff < 2628000 ) return sprintf( /* translators: %d: number of weeks */ __( '%dw ago', 'giveflow-fundraising-campaigns' ),  Math.floor( diff / 604800 ) );
    return formatDateShort( iso );
}

export function initials( name ) {
    if ( ! name ) return '?';
    const parts = String( name ).trim().split( /\s+/ ).slice( 0, 2 );
    return parts.map( ( p ) => p[ 0 ] || '' ).join( '' ).toUpperCase() || '?';
}

export function donationStatusPill( status ) {
    switch ( status ) {
        case 'paid':           return { cls: 'is-ok',     label: __( 'Paid',     'giveflow-fundraising-campaigns' ) };
        case 'pending':        return { cls: 'is-warn',   label: __( 'Pending',  'giveflow-fundraising-campaigns' ) };
        case 'failed':         return { cls: 'is-error',  label: __( 'Failed',   'giveflow-fundraising-campaigns' ) };
        case 'refunded':       return { cls: 'is-muted',  label: __( 'Refunded', 'giveflow-fundraising-campaigns' ) };
        case 'partial_refund': return { cls: 'is-warn',   label: __( 'Partial',  'giveflow-fundraising-campaigns' ) };
        case 'disputed':       return { cls: 'is-error',  label: __( 'Disputed', 'giveflow-fundraising-campaigns' ) };
        case 'abandoned':      return { cls: 'is-muted',  label: __( 'Abandoned','giveflow-fundraising-campaigns' ) };
        default:               return { cls: 'is-muted',  label: status };
    }
}

export const CHANNEL_LABEL = {
    direct:        __( 'Direct',         'giveflow-fundraising-campaigns' ),
    email:         __( 'Email',          'giveflow-fundraising-campaigns' ),
    social:        __( 'Social',         'giveflow-fundraising-campaigns' ),
    'paid-social': __( 'Paid social',    'giveflow-fundraising-campaigns' ),
    organic:       __( 'Organic search', 'giveflow-fundraising-campaigns' ),
    cpc:           __( 'Paid search',    'giveflow-fundraising-campaigns' ),
    referral:      __( 'Referral',       'giveflow-fundraising-campaigns' ),
    qr:            __( 'QR code',        'giveflow-fundraising-campaigns' ),
    peer:          __( 'Peer-to-peer',   'giveflow-fundraising-campaigns' ),
    other:         __( 'Other',          'giveflow-fundraising-campaigns' ),
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

export const canRefundDonation = ( donation ) =>
    ( donation?.refundable_cents ?? 0 ) > 0 && isSettled( donation );

/** An erased donor has no address left, so there is nowhere to send. */
export const isDonorRedacted = ( donation, donor ) =>
    !! ( donor?.redacted ?? donation?.donor?.redacted );

export const canResendReceipt = ( donation, donor ) =>
    isSettled( donation ) && ! isDonorRedacted( donation, donor );
