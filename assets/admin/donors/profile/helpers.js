// Shared formatters and event-mapping for the donor profile views.

import { __, sprintf } from '@wordpress/i18n';

export { formatAmount, formatAmountCompact } from '../../_shared/format';

export function formatMonth( iso ) {
    if ( ! iso ) return '-';
    const d = new Date( String( iso ).replace( ' ', 'T' ) );
    if ( Number.isNaN( d.getTime() ) ) return iso;
    return d.toLocaleDateString( undefined, { month: 'short', year: 'numeric' } );
}

export function formatDate( iso ) {
    if ( ! iso ) return '-';
    const d = new Date( String( iso ).replace( ' ', 'T' ) );
    if ( Number.isNaN( d.getTime() ) ) return iso;
    return d.toLocaleDateString( undefined, { month: 'short', day: '2-digit' } );
}

export function formatDateTime( iso ) {
    if ( ! iso ) return '-';
    const d = new Date( String( iso ).replace( ' ', 'T' ) );
    if ( Number.isNaN( d.getTime() ) ) return iso;
    return d.toLocaleString( undefined, {
        month: 'short', day: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    } );
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
    return formatDate( iso );
}

export function initials( name ) {
    if ( ! name ) return '?';
    const parts = String( name ).trim().split( /\s+/ ).slice( 0, 2 );
    return parts.map( ( p ) => p[ 0 ] || '' ).join( '' ).toUpperCase() || '?';
}

// Donation status → pill class + label.
export function donationStatusPill( status ) {
    switch ( status ) {
        case 'paid':           return { cls: 'is-ok',    label: __( 'Paid',     'dono' ) };
        case 'pending':        return { cls: 'is-warn',  label: __( 'Pending',  'dono' ) };
        case 'failed':         return { cls: 'is-error', label: __( 'Failed',   'dono' ) };
        case 'refunded':       return { cls: 'is-info',  label: __( 'Refunded', 'dono' ) };
        case 'partial_refund': return { cls: 'is-info',  label: __( 'Partial',  'dono' ) };
        case 'disputed':       return { cls: 'is-warn',  label: __( 'Disputed', 'dono' ) };
        default:               return { cls: 'is-muted', label: status };
    }
}

// Plan status → pill class + label.
export function planStatusPill( status ) {
    switch ( status ) {
        case 'active':    return { cls: 'is-ok',    label: __( 'Active',    'dono' ) };
        case 'past_due':  return { cls: 'is-warn',  label: __( 'Past due',  'dono' ) };
        case 'paused':    return { cls: 'is-muted', label: __( 'Paused',    'dono' ) };
        case 'cancelled': return { cls: 'is-muted', label: __( 'Cancelled', 'dono' ) };
        default:          return { cls: 'is-muted', label: status };
    }
}

// Event type → timeline dot variant + label builder.
export function eventMeta( event ) {
    const { type } = event;
    switch ( type ) {
        case 'donation.paid':
        case 'recurring_plan.renewed':
            return { dot: 'is-ok',     label: __( 'Donation paid',   'dono' ) };
        case 'donation.created':
            return { dot: 'is-muted',  label: __( 'Donation created','dono' ) };
        case 'donation.refunded':
        case 'refund.issued':
            return { dot: 'is-error',  label: __( 'Refund issued',   'dono' ) };
        case 'donation.disputed':
            return { dot: 'is-warn',   label: __( 'Dispute opened',  'dono' ) };
        case 'receipt.issued':
        case 'receipt.sent':
            return { dot: 'is-info',   label: __( 'Receipt issued',  'dono' ) };
        case 'recurring_plan.created':
        case 'recurring_plan.updated':
            return { dot: 'is-violet', label: __( 'Recurring plan',  'dono' ) };
        case 'recurring_plan.cancelled':
            return { dot: 'is-muted',  label: __( 'Plan cancelled',  'dono' ) };
        case 'consent.granted':
            return { dot: 'is-ok',     label: __( 'Consent granted', 'dono' ) };
        case 'consent.revoked':
            return { dot: 'is-muted',  label: __( 'Consent revoked', 'dono' ) };
        case 'magic_link.issued':
            return { dot: 'is-info',   label: __( 'Magic link issued','dono' ) };
        case 'note.added':
        case 'donor.note_added':
            return { dot: 'is-rose',   label: __( 'Note added',      'dono' ) };
        case 'donor.created':
            return { dot: 'is-ok',     label: __( 'Donor created',   'dono' ) };
        case 'donor.redacted':
            return { dot: 'is-muted',  label: __( 'Donor redacted',  'dono' ) };
        default:
            return { dot: 'is-muted',  label: type };
    }
}

export const SEGMENT_LABELS = {
    champions:   __( 'Champion',     'dono' ),
    loyal:       __( 'Loyal',        'dono' ),
    new:         __( 'New',          'dono' ),
    at_risk:     __( 'At risk',      'dono' ),
    hibernating: __( 'Hibernating',  'dono' ),
    lost:        __( 'Lost',         'dono' ),
    other:       __( 'Other',        'dono' ),
};
