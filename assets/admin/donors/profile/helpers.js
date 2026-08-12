// Shared formatters and event-mapping for the donor profile views.

import { __, sprintf } from '@wordpress/i18n';
// Timestamps arrive as MySQL strings in UTC with no zone marker, which a
// browser reads as local time. parseTimestamp marks them.
import { parseTimestamp } from '@dono/ui/utils/format';

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
    if ( diff < 60 )      return __( 'just now', 'dono-fundraising-platform' );
    if ( diff < 3600 )    return sprintf( /* translators: %d: number of minutes */ __( '%dm ago', 'dono-fundraising-platform' ),  Math.floor( diff / 60 ) );
    if ( diff < 86400 )   return sprintf( /* translators: %d: number of hours */ __( '%dh ago', 'dono-fundraising-platform' ),  Math.floor( diff / 3600 ) );
    if ( diff < 604800 )  return sprintf( /* translators: %d: number of days */ __( '%dd ago', 'dono-fundraising-platform' ),  Math.floor( diff / 86400 ) );
    if ( diff < 2628000 ) return sprintf( /* translators: %d: number of weeks */ __( '%dw ago', 'dono-fundraising-platform' ),  Math.floor( diff / 604800 ) );
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
        case 'paid':           return { cls: 'is-ok',    label: __( 'Paid',     'dono-fundraising-platform' ) };
        case 'pending':        return { cls: 'is-warn',  label: __( 'Pending',  'dono-fundraising-platform' ) };
        // Not a warning like pending: the donor has paid and nothing is
        // expected of them, the money is simply still moving.
        case 'processing':     return { cls: 'is-info',  label: __( 'Processing', 'dono-fundraising-platform' ) };
        case 'failed':         return { cls: 'is-error', label: __( 'Failed',   'dono-fundraising-platform' ) };
        case 'refunded':       return { cls: 'is-info',  label: __( 'Refunded', 'dono-fundraising-platform' ) };
        case 'partial_refund': return { cls: 'is-info',  label: __( 'Partial',  'dono-fundraising-platform' ) };
        case 'disputed':       return { cls: 'is-warn',  label: __( 'Disputed', 'dono-fundraising-platform' ) };
        default:               return { cls: 'is-muted', label: status };
    }
}

// Plan status → pill class + label.
export function planStatusPill( status ) {
    switch ( status ) {
        case 'active':    return { cls: 'is-ok',    label: __( 'Active',    'dono-fundraising-platform' ) };
        case 'past_due':  return { cls: 'is-warn',  label: __( 'Past due',  'dono-fundraising-platform' ) };
        case 'paused':    return { cls: 'is-muted', label: __( 'Paused',    'dono-fundraising-platform' ) };
        case 'cancelled': return { cls: 'is-muted', label: __( 'Cancelled', 'dono-fundraising-platform' ) };
        default:          return { cls: 'is-muted', label: status };
    }
}

// Event type → timeline dot variant + label builder. Keys are the types
// EventRecorder actually writes; anything else falls through to readable().
export function eventMeta( event ) {
    const { type } = event;
    switch ( type ) {
        case 'donation.intent_created':
            return { dot: 'is-muted',  label: __( 'Donation started',     'dono-fundraising-platform' ) };
        case 'donation.pending':
            return { dot: 'is-muted',  label: __( 'Awaiting payment',     'dono-fundraising-platform' ) };
        case 'donation.processing':
            return { dot: 'is-info',   label: __( 'Payment processing',   'dono-fundraising-platform' ) };
        case 'donation.completed':
            return { dot: 'is-ok',     label: __( 'Donation paid',        'dono-fundraising-platform' ) };
        case 'donation.failed':
            return { dot: 'is-error',  label: __( 'Payment failed',       'dono-fundraising-platform' ) };
        case 'donation.refunded':
            return { dot: 'is-error',  label: __( 'Refund issued',        'dono-fundraising-platform' ) };
        case 'donation.refund_reversed':
            return { dot: 'is-warn',   label: __( 'Refund reversed',      'dono-fundraising-platform' ) };
        case 'donation.disputed':
            return { dot: 'is-warn',   label: __( 'Dispute opened',       'dono-fundraising-platform' ) };
        case 'donation.reversal_reinstated':
            return { dot: 'is-warn',   label: __( 'Reversal reinstated',  'dono-fundraising-platform' ) };
        case 'receipt.issued':
            return { dot: 'is-info',   label: __( 'Receipt issued',       'dono-fundraising-platform' ) };
        case 'recurring.renewed':
            return { dot: 'is-ok',     label: __( 'Recurring payment',    'dono-fundraising-platform' ) };
        case 'recurring.paused':
            return { dot: 'is-muted',  label: __( 'Recurring paused',     'dono-fundraising-platform' ) };
        case 'recurring.resumed':
            return { dot: 'is-ok',     label: __( 'Recurring resumed',    'dono-fundraising-platform' ) };
        case 'recurring.skipped':
            return { dot: 'is-muted',  label: __( 'Next payment skipped', 'dono-fundraising-platform' ) };
        case 'recurring.amount_changed':
            return { dot: 'is-violet', label: __( 'Recurring amount changed', 'dono-fundraising-platform' ) };
        case 'recurring.cancelled_by_admin':
            return { dot: 'is-muted',  label: __( 'Recurring plan cancelled', 'dono-fundraising-platform' ) };
        case 'recurring.failed':
            return { dot: 'is-error',  label: __( 'Renewal failed',       'dono-fundraising-platform' ) };
        case 'recurring.cancelled':
            return { dot: 'is-muted',  label: __( 'Recurring plan cancelled', 'dono-fundraising-platform' ) };
        case 'recurring.subscription_creation_failed':
            return { dot: 'is-error',  label: __( 'Subscription not created',  'dono-fundraising-platform' ) };
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
    champions:   __( 'Champion',     'dono-fundraising-platform' ),
    loyal:       __( 'Loyal',        'dono-fundraising-platform' ),
    new:         __( 'New',          'dono-fundraising-platform' ),
    at_risk:     __( 'At risk',      'dono-fundraising-platform' ),
    hibernating: __( 'Hibernating',  'dono-fundraising-platform' ),
    lost:        __( 'Lost',         'dono-fundraising-platform' ),
    other:       __( 'Other',        'dono-fundraising-platform' ),
};
