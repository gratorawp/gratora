// Money in minor units; REST dates are MySQL strings in UTC with no zone
// marker, which a browser reads as local time. parseTimestamp marks them.
import { __, sprintf } from '@wordpress/i18n';
import { parseTimestamp } from '@dono/ui/utils/format';

export { formatAmount, formatAmountCompact, currencyDecimals } from '../../_shared/format';

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
    if ( diff < 60 )      return __( 'just now', 'dono-fundraising-platform' );
    if ( diff < 3600 )    return sprintf( /* translators: %d: number of minutes */ __( '%dm ago', 'dono-fundraising-platform' ),  Math.floor( diff / 60 ) );
    if ( diff < 86400 )   return sprintf( /* translators: %d: number of hours */ __( '%dh ago', 'dono-fundraising-platform' ),  Math.floor( diff / 3600 ) );
    if ( diff < 604800 )  return sprintf( /* translators: %d: number of days */ __( '%dd ago', 'dono-fundraising-platform' ),  Math.floor( diff / 86400 ) );
    if ( diff < 2628000 ) return sprintf( /* translators: %d: number of weeks */ __( '%dw ago', 'dono-fundraising-platform' ),  Math.floor( diff / 604800 ) );
    return formatDateShort( iso );
}

export function initials( name ) {
    if ( ! name ) return '?';
    const parts = String( name ).trim().split( /\s+/ ).slice( 0, 2 );
    return parts.map( ( p ) => p[ 0 ] || '' ).join( '' ).toUpperCase() || '?';
}

export function donationStatusPill( status ) {
    switch ( status ) {
        case 'paid':           return { cls: 'is-ok',     label: __( 'Paid',     'dono-fundraising-platform' ) };
        case 'pending':        return { cls: 'is-warn',   label: __( 'Pending',  'dono-fundraising-platform' ) };
        case 'failed':         return { cls: 'is-error',  label: __( 'Failed',   'dono-fundraising-platform' ) };
        case 'refunded':       return { cls: 'is-muted',  label: __( 'Refunded', 'dono-fundraising-platform' ) };
        case 'partial_refund': return { cls: 'is-warn',   label: __( 'Partial',  'dono-fundraising-platform' ) };
        case 'disputed':       return { cls: 'is-error',  label: __( 'Disputed', 'dono-fundraising-platform' ) };
        case 'abandoned':      return { cls: 'is-muted',  label: __( 'Abandoned','dono-fundraising-platform' ) };
        default:               return { cls: 'is-muted',  label: status };
    }
}

export const CHANNEL_LABEL = {
    direct:        __( 'Direct',         'dono-fundraising-platform' ),
    email:         __( 'Email',          'dono-fundraising-platform' ),
    social:        __( 'Social',         'dono-fundraising-platform' ),
    'paid-social': __( 'Paid social',    'dono-fundraising-platform' ),
    organic:       __( 'Organic search', 'dono-fundraising-platform' ),
    cpc:           __( 'Paid search',    'dono-fundraising-platform' ),
    referral:      __( 'Referral',       'dono-fundraising-platform' ),
    qr:            __( 'QR code',        'dono-fundraising-platform' ),
    peer:          __( 'Peer-to-peer',   'dono-fundraising-platform' ),
    other:         __( 'Other',          'dono-fundraising-platform' ),
};
