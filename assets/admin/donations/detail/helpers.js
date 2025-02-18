// Money in minor units; REST dates are MySQL strings needing the T separator.
import { __, sprintf } from '@wordpress/i18n';

export { formatAmount, formatAmountCompact } from '../../_shared/format';

export function formatDateTime( iso ) {
    if ( ! iso ) return '-';
    const d = new Date( String( iso ).replace( ' ', 'T' ) );
    if ( Number.isNaN( d.getTime() ) ) return iso;
    return d.toLocaleString( undefined, {
        month: 'short', day: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    } );
}

export function formatDateShort( iso ) {
    if ( ! iso ) return '-';
    const d = new Date( String( iso ).replace( ' ', 'T' ) );
    if ( Number.isNaN( d.getTime() ) ) return iso;
    return d.toLocaleDateString( undefined, { month: 'short', day: '2-digit' } );
}

export function formatDate( iso ) {
    if ( ! iso ) return '-';
    const d = new Date( String( iso ).replace( ' ', 'T' ) );
    if ( Number.isNaN( d.getTime() ) ) return iso;
    return d.toLocaleDateString( undefined, { year: 'numeric', month: 'short', day: '2-digit' } );
}

export function timeAgo( iso ) {
    if ( ! iso ) return '-';
    const d = new Date( String( iso ).replace( ' ', 'T' ) );
    if ( Number.isNaN( d.getTime() ) ) return iso;
    const diff = Math.max( 0, ( Date.now() - d.getTime() ) / 1000 );
    if ( diff < 60 )      return __( 'just now', 'dono' );
    if ( diff < 3600 )    return sprintf( /* translators: %d: number of minutes */ __( '%dm ago', 'dono' ),  Math.floor( diff / 60 ) );
    if ( diff < 86400 )   return sprintf( /* translators: %d: number of hours */ __( '%dh ago', 'dono' ),  Math.floor( diff / 3600 ) );
    if ( diff < 604800 )  return sprintf( /* translators: %d: number of days */ __( '%dd ago', 'dono' ),  Math.floor( diff / 86400 ) );
    if ( diff < 2628000 ) return sprintf( /* translators: %d: number of weeks */ __( '%dw ago', 'dono' ),  Math.floor( diff / 604800 ) );
    return formatDateShort( iso );
}

export function initials( name ) {
    if ( ! name ) return '?';
    const parts = String( name ).trim().split( /\s+/ ).slice( 0, 2 );
    return parts.map( ( p ) => p[ 0 ] || '' ).join( '' ).toUpperCase() || '?';
}

export function donationStatusPill( status ) {
    switch ( status ) {
        case 'paid':           return { cls: 'is-ok',     label: __( 'Paid',     'dono' ) };
        case 'pending':        return { cls: 'is-warn',   label: __( 'Pending',  'dono' ) };
        case 'failed':         return { cls: 'is-error',  label: __( 'Failed',   'dono' ) };
        case 'refunded':       return { cls: 'is-muted',  label: __( 'Refunded', 'dono' ) };
        case 'partial_refund': return { cls: 'is-warn',   label: __( 'Partial',  'dono' ) };
        case 'disputed':       return { cls: 'is-error',  label: __( 'Disputed', 'dono' ) };
        case 'abandoned':      return { cls: 'is-muted',  label: __( 'Abandoned','dono' ) };
        default:               return { cls: 'is-muted',  label: status };
    }
}

export const RECEIPT_RENDERER_LABEL = {
    'generic.v1': __( 'Generic receipt', 'dono' ),
};

export const CHANNEL_LABEL = {
    direct:        __( 'Direct',         'dono' ),
    email:         __( 'Email',          'dono' ),
    social:        __( 'Social',         'dono' ),
    'paid-social': __( 'Paid social',    'dono' ),
    organic:       __( 'Organic search', 'dono' ),
    cpc:           __( 'Paid search',    'dono' ),
    referral:      __( 'Referral',       'dono' ),
    qr:            __( 'QR code',        'dono' ),
    peer:          __( 'Peer-to-peer',   'dono' ),
    other:         __( 'Other',          'dono' ),
};
