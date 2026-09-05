// Money in minor units; REST dates are MySQL strings in UTC with no zone
// marker, which a browser reads as local time. parseTimestamp marks them.
import { __ } from '@wordpress/i18n';
import { parseTimestamp } from '@fundkit/ui/utils/format';

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
        case 'paid':           return { cls: 'is-ok',     label: __( 'Paid',     'fundraising-toolkit' ) };
        case 'pending':        return { cls: 'is-warn',   label: __( 'Pending',  'fundraising-toolkit' ) };
        case 'processing':     return { cls: 'is-info',   label: __( 'Processing', 'fundraising-toolkit' ) };
        case 'failed':         return { cls: 'is-error',  label: __( 'Failed',   'fundraising-toolkit' ) };
        case 'refunded':       return { cls: 'is-muted',  label: __( 'Refunded', 'fundraising-toolkit' ) };
        case 'partial_refund': return { cls: 'is-warn',   label: __( 'Partial',  'fundraising-toolkit' ) };
        case 'disputed':       return { cls: 'is-error',  label: __( 'Disputed', 'fundraising-toolkit' ) };
        case 'abandoned':      return { cls: 'is-muted',  label: __( 'Abandoned','fundraising-toolkit' ) };
        default:               return { cls: 'is-muted',  label: status };
    }
}

export const CHANNEL_LABEL = {
    direct:        __( 'Direct',         'fundraising-toolkit' ),
    email:         __( 'Email',          'fundraising-toolkit' ),
    social:        __( 'Social',         'fundraising-toolkit' ),
    'paid-social': __( 'Paid social',    'fundraising-toolkit' ),
    organic:       __( 'Organic search', 'fundraising-toolkit' ),
    cpc:           __( 'Paid search',    'fundraising-toolkit' ),
    referral:      __( 'Referral',       'fundraising-toolkit' ),
    qr:            __( 'QR code',        'fundraising-toolkit' ),
    peer:          __( 'Peer-to-peer',   'fundraising-toolkit' ),
    other:         __( 'Other',          'fundraising-toolkit' ),
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
