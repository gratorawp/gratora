// Shared formatters and event-mapping for the donor profile views.

import { __, sprintf } from '@wordpress/i18n';
// Timestamps arrive as MySQL strings in UTC with no zone marker, which a
// browser reads as local time. parseTimestamp marks them.
import { parseTimestamp } from '@fundkit/ui/utils/format';

export { formatAmount, formatAmountCompact } from '../../_shared/format';

export function formatMonth( iso ) {
    if ( ! iso ) return '-';
    const d = parseTimestamp( iso );
    if ( Number.isNaN( d.getTime() ) ) return iso;
    return d.toLocaleDateString( undefined, { month: 'short', year: 'numeric' } );
}

export function formatDate( iso ) {
    if ( ! iso ) return '-';
    const d = parseTimestamp( iso );
    if ( Number.isNaN( d.getTime() ) ) return iso;
    return d.toLocaleDateString( undefined, { month: 'short', day: '2-digit' } );
}

export function formatDateTime( iso ) {
    if ( ! iso ) return '-';
    const d = parseTimestamp( iso );
    if ( Number.isNaN( d.getTime() ) ) return iso;
    return d.toLocaleString( undefined, {
        month: 'short', day: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    } );
}

export function timeAgo( iso ) {
    if ( ! iso ) return '-';
    const d = parseTimestamp( iso );
    if ( Number.isNaN( d.getTime() ) ) return iso;
    const diff = Math.max( 0, ( Date.now() - d.getTime() ) / 1000 );
    if ( diff < 60 )      return __( 'just now', 'fundkit-fundraising-campaigns' );
    if ( diff < 3600 )    return sprintf( /* translators: %d: number of minutes */ __( '%dm ago', 'fundkit-fundraising-campaigns' ),  Math.floor( diff / 60 ) );
    if ( diff < 86400 )   return sprintf( /* translators: %d: number of hours */ __( '%dh ago', 'fundkit-fundraising-campaigns' ),  Math.floor( diff / 3600 ) );
    if ( diff < 604800 )  return sprintf( /* translators: %d: number of days */ __( '%dd ago', 'fundkit-fundraising-campaigns' ),  Math.floor( diff / 86400 ) );
    if ( diff < 2628000 ) return sprintf( /* translators: %d: number of weeks */ __( '%dw ago', 'fundkit-fundraising-campaigns' ),  Math.floor( diff / 604800 ) );
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
        case 'paid':           return { cls: 'is-ok',    label: __( 'Paid',     'fundkit-fundraising-campaigns' ) };
        case 'pending':        return { cls: 'is-warn',  label: __( 'Pending',  'fundkit-fundraising-campaigns' ) };
        // Not a warning like pending: the donor has paid and nothing is
        // expected of them, the money is simply still moving.
        case 'processing':     return { cls: 'is-info',  label: __( 'Processing', 'fundkit-fundraising-campaigns' ) };
        case 'failed':         return { cls: 'is-error', label: __( 'Failed',   'fundkit-fundraising-campaigns' ) };
        case 'refunded':       return { cls: 'is-info',  label: __( 'Refunded', 'fundkit-fundraising-campaigns' ) };
        case 'partial_refund': return { cls: 'is-info',  label: __( 'Partial',  'fundkit-fundraising-campaigns' ) };
        case 'disputed':       return { cls: 'is-warn',  label: __( 'Disputed', 'fundkit-fundraising-campaigns' ) };
        default:               return { cls: 'is-muted', label: status };
    }
}

// Plan status → pill class + label.
export function planStatusPill( status ) {
    switch ( status ) {
        case 'active':    return { cls: 'is-ok',    label: __( 'Active',    'fundkit-fundraising-campaigns' ) };
        case 'past_due':  return { cls: 'is-warn',  label: __( 'Past due',  'fundkit-fundraising-campaigns' ) };
        case 'paused':    return { cls: 'is-muted', label: __( 'Paused',    'fundkit-fundraising-campaigns' ) };
        case 'cancelled': return { cls: 'is-muted', label: __( 'Cancelled', 'fundkit-fundraising-campaigns' ) };
        default:          return { cls: 'is-muted', label: status };
    }
}

// Event type → timeline dot variant + label builder. Keys are the types
// EventRecorder actually writes; anything else falls through to readable().
export function eventMeta( event ) {
    const { type } = event;
    switch ( type ) {
        case 'donation.intent_created':
            return { dot: 'is-muted',  label: __( 'Donation started',     'fundkit-fundraising-campaigns' ) };
        case 'donation.pending':
            return { dot: 'is-muted',  label: __( 'Awaiting payment',     'fundkit-fundraising-campaigns' ) };
        case 'donation.processing':
            return { dot: 'is-info',   label: __( 'Payment processing',   'fundkit-fundraising-campaigns' ) };
        case 'donation.completed':
            return { dot: 'is-ok',     label: __( 'Donation paid',        'fundkit-fundraising-campaigns' ) };
        case 'donation.failed':
            return { dot: 'is-error',  label: __( 'Payment failed',       'fundkit-fundraising-campaigns' ) };
        case 'donation.refunded':
            return { dot: 'is-error',  label: __( 'Refund issued',        'fundkit-fundraising-campaigns' ) };
        case 'donation.refund_reversed':
            return { dot: 'is-warn',   label: __( 'Refund reversed',      'fundkit-fundraising-campaigns' ) };
        case 'donation.disputed':
            return { dot: 'is-warn',   label: __( 'Dispute opened',       'fundkit-fundraising-campaigns' ) };
        case 'donation.reversal_reinstated':
            return { dot: 'is-warn',   label: __( 'Reversal reinstated',  'fundkit-fundraising-campaigns' ) };
        case 'receipt.issued':
            return { dot: 'is-info',   label: __( 'Receipt issued',       'fundkit-fundraising-campaigns' ) };
        case 'recurring.renewed':
            return { dot: 'is-ok',     label: __( 'Recurring payment',    'fundkit-fundraising-campaigns' ) };
        case 'recurring.paused':
            return { dot: 'is-muted',  label: __( 'Recurring paused',     'fundkit-fundraising-campaigns' ) };
        case 'recurring.resumed':
            return { dot: 'is-ok',     label: __( 'Recurring resumed',    'fundkit-fundraising-campaigns' ) };
        case 'recurring.skipped':
            return { dot: 'is-muted',  label: __( 'Next payment skipped', 'fundkit-fundraising-campaigns' ) };
        case 'recurring.amount_changed':
            return { dot: 'is-violet', label: __( 'Recurring amount changed', 'fundkit-fundraising-campaigns' ) };
        case 'recurring.cancelled_by_admin':
            return { dot: 'is-muted',  label: __( 'Recurring plan cancelled', 'fundkit-fundraising-campaigns' ) };
        case 'recurring.failed':
            return { dot: 'is-error',  label: __( 'Renewal failed',       'fundkit-fundraising-campaigns' ) };
        case 'recurring.cancelled':
            return { dot: 'is-muted',  label: __( 'Recurring plan cancelled', 'fundkit-fundraising-campaigns' ) };
        case 'recurring.subscription_creation_failed':
            return { dot: 'is-error',  label: __( 'Subscription not created',  'fundkit-fundraising-campaigns' ) };
        default:
            return { dot: 'is-muted',  label: readableEventType( type ) };
    }
}

/**
 * Last resort for a type with no label of its own, so an add-on's event reads
 * as words rather than as a machine key: "foo.bar_baz" becomes "Bar baz".
 */
function readableEventType( type ) {
    const tail = String( type || '' ).split( '.' ).pop().replace( /_/g, ' ' ).trim();
    if ( ! tail ) {
        return String( type || '' );
    }
    return tail.charAt( 0 ).toUpperCase() + tail.slice( 1 );
}

export const SEGMENT_LABELS = {
    champions:   __( 'Champion',     'fundkit-fundraising-campaigns' ),
    loyal:       __( 'Loyal',        'fundkit-fundraising-campaigns' ),
    new:         __( 'New',          'fundkit-fundraising-campaigns' ),
    at_risk:     __( 'At risk',      'fundkit-fundraising-campaigns' ),
    hibernating: __( 'Hibernating',  'fundkit-fundraising-campaigns' ),
    lost:        __( 'Lost',         'fundkit-fundraising-campaigns' ),
    other:       __( 'Other',        'fundkit-fundraising-campaigns' ),
};
