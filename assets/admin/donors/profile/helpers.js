// Shared formatters and event-mapping for the donor profile views.

import { __, sprintf } from '@wordpress/i18n';
// Timestamps arrive as MySQL strings in UTC with no zone marker, which a
// browser reads as local time. parseTimestamp marks them.
import { parseTimestamp } from '@giveflow/ui/utils/format';

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
    if ( diff < 60 )      return __( 'just now', 'giveflow-fundraising-campaigns' );
    if ( diff < 3600 )    return sprintf( /* translators: %d: number of minutes */ __( '%dm ago', 'giveflow-fundraising-campaigns' ),  Math.floor( diff / 60 ) );
    if ( diff < 86400 )   return sprintf( /* translators: %d: number of hours */ __( '%dh ago', 'giveflow-fundraising-campaigns' ),  Math.floor( diff / 3600 ) );
    if ( diff < 604800 )  return sprintf( /* translators: %d: number of days */ __( '%dd ago', 'giveflow-fundraising-campaigns' ),  Math.floor( diff / 86400 ) );
    if ( diff < 2628000 ) return sprintf( /* translators: %d: number of weeks */ __( '%dw ago', 'giveflow-fundraising-campaigns' ),  Math.floor( diff / 604800 ) );
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
        case 'paid':           return { cls: 'is-ok',    label: __( 'Paid',     'giveflow-fundraising-campaigns' ) };
        case 'pending':        return { cls: 'is-warn',  label: __( 'Pending',  'giveflow-fundraising-campaigns' ) };
        // Not a warning like pending: the donor has paid and nothing is
        // expected of them, the money is simply still moving.
        case 'processing':     return { cls: 'is-info',  label: __( 'Processing', 'giveflow-fundraising-campaigns' ) };
        case 'failed':         return { cls: 'is-error', label: __( 'Failed',   'giveflow-fundraising-campaigns' ) };
        case 'refunded':       return { cls: 'is-info',  label: __( 'Refunded', 'giveflow-fundraising-campaigns' ) };
        case 'partial_refund': return { cls: 'is-info',  label: __( 'Partial',  'giveflow-fundraising-campaigns' ) };
        case 'disputed':       return { cls: 'is-warn',  label: __( 'Disputed', 'giveflow-fundraising-campaigns' ) };
        default:               return { cls: 'is-muted', label: status };
    }
}

// Plan status → pill class + label.
export function planStatusPill( status ) {
    switch ( status ) {
        case 'active':    return { cls: 'is-ok',    label: __( 'Active',    'giveflow-fundraising-campaigns' ) };
        case 'past_due':  return { cls: 'is-warn',  label: __( 'Past due',  'giveflow-fundraising-campaigns' ) };
        case 'paused':    return { cls: 'is-muted', label: __( 'Paused',    'giveflow-fundraising-campaigns' ) };
        case 'cancelled': return { cls: 'is-muted', label: __( 'Cancelled', 'giveflow-fundraising-campaigns' ) };
        default:          return { cls: 'is-muted', label: status };
    }
}

// Event type → timeline dot variant + label builder. Keys are the types
// EventRecorder actually writes; anything else falls through to readable().
export function eventMeta( event ) {
    const { type } = event;
    switch ( type ) {
        case 'donation.intent_created':
            return { dot: 'is-muted',  label: __( 'Donation started',     'giveflow-fundraising-campaigns' ) };
        case 'donation.pending':
            return { dot: 'is-muted',  label: __( 'Awaiting payment',     'giveflow-fundraising-campaigns' ) };
        case 'donation.processing':
            return { dot: 'is-info',   label: __( 'Payment processing',   'giveflow-fundraising-campaigns' ) };
        case 'donation.completed':
            return { dot: 'is-ok',     label: __( 'Donation paid',        'giveflow-fundraising-campaigns' ) };
        case 'donation.failed':
            return { dot: 'is-error',  label: __( 'Payment failed',       'giveflow-fundraising-campaigns' ) };
        case 'donation.refunded':
            return { dot: 'is-error',  label: __( 'Refund issued',        'giveflow-fundraising-campaigns' ) };
        case 'donation.refund_reversed':
            return { dot: 'is-warn',   label: __( 'Refund reversed',      'giveflow-fundraising-campaigns' ) };
        case 'donation.disputed':
            return { dot: 'is-warn',   label: __( 'Dispute opened',       'giveflow-fundraising-campaigns' ) };
        case 'donation.reversal_reinstated':
            return { dot: 'is-warn',   label: __( 'Reversal reinstated',  'giveflow-fundraising-campaigns' ) };
        case 'receipt.issued':
            return { dot: 'is-info',   label: __( 'Receipt issued',       'giveflow-fundraising-campaigns' ) };
        case 'recurring.renewed':
            return { dot: 'is-ok',     label: __( 'Recurring payment',    'giveflow-fundraising-campaigns' ) };
        case 'recurring.paused':
            return { dot: 'is-muted',  label: __( 'Recurring paused',     'giveflow-fundraising-campaigns' ) };
        case 'recurring.resumed':
            return { dot: 'is-ok',     label: __( 'Recurring resumed',    'giveflow-fundraising-campaigns' ) };
        case 'recurring.skipped':
            return { dot: 'is-muted',  label: __( 'Next payment skipped', 'giveflow-fundraising-campaigns' ) };
        case 'recurring.amount_changed':
            return { dot: 'is-violet', label: __( 'Recurring amount changed', 'giveflow-fundraising-campaigns' ) };
        case 'recurring.cancelled_by_admin':
            return { dot: 'is-muted',  label: __( 'Recurring plan cancelled', 'giveflow-fundraising-campaigns' ) };
        case 'recurring.failed':
            return { dot: 'is-error',  label: __( 'Renewal failed',       'giveflow-fundraising-campaigns' ) };
        case 'recurring.cancelled':
            return { dot: 'is-muted',  label: __( 'Recurring plan cancelled', 'giveflow-fundraising-campaigns' ) };
        case 'recurring.subscription_creation_failed':
            return { dot: 'is-error',  label: __( 'Subscription not created',  'giveflow-fundraising-campaigns' ) };
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
    champions:   __( 'Champion',     'giveflow-fundraising-campaigns' ),
    loyal:       __( 'Loyal',        'giveflow-fundraising-campaigns' ),
    new:         __( 'New',          'giveflow-fundraising-campaigns' ),
    at_risk:     __( 'At risk',      'giveflow-fundraising-campaigns' ),
    hibernating: __( 'Hibernating',  'giveflow-fundraising-campaigns' ),
    lost:        __( 'Lost',         'giveflow-fundraising-campaigns' ),
    other:       __( 'Other',        'giveflow-fundraising-campaigns' ),
};
