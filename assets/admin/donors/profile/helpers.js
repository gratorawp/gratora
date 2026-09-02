// Shared formatters and event-mapping for the donor profile views.

import { __ } from '@wordpress/i18n';
// Timestamps arrive as MySQL strings in UTC with no zone marker, which a
// browser reads as local time. parseTimestamp marks them.
import { parseTimestamp } from '@fundkit/ui/utils/format';

export { formatAmount, formatAmountCompact, timeAgo } from '../../_shared/format';

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


export function initials( name ) {
    if ( ! name ) return '?';
    const parts = String( name ).trim().split( /\s+/ ).slice( 0, 2 );
    return parts.map( ( p ) => p[ 0 ] || '' ).join( '' ).toUpperCase() || '?';
}

// Donation status → pill class + label.
export function donationStatusPill( status ) {
    switch ( status ) {
        case 'paid':           return { cls: 'is-ok',    label: __( 'Paid',     'fundraising-toolkit' ) };
        case 'pending':        return { cls: 'is-warn',  label: __( 'Pending',  'fundraising-toolkit' ) };
        // Not a warning like pending: the donor has paid and nothing is
        // expected of them, the money is simply still moving.
        case 'processing':     return { cls: 'is-info',  label: __( 'Processing', 'fundraising-toolkit' ) };
        case 'failed':         return { cls: 'is-error', label: __( 'Failed',   'fundraising-toolkit' ) };
        case 'refunded':       return { cls: 'is-info',  label: __( 'Refunded', 'fundraising-toolkit' ) };
        case 'partial_refund': return { cls: 'is-info',  label: __( 'Partial',  'fundraising-toolkit' ) };
        case 'disputed':       return { cls: 'is-warn',  label: __( 'Disputed', 'fundraising-toolkit' ) };
        default:               return { cls: 'is-muted', label: status };
    }
}

// Plan status → pill class + label.
export function planStatusPill( status ) {
    switch ( status ) {
        case 'active':    return { cls: 'is-ok',    label: __( 'Active',    'fundraising-toolkit' ) };
        case 'past_due':  return { cls: 'is-warn',  label: __( 'Past due',  'fundraising-toolkit' ) };
        case 'paused':    return { cls: 'is-muted', label: __( 'Paused',    'fundraising-toolkit' ) };
        case 'cancelled': return { cls: 'is-muted', label: __( 'Cancelled', 'fundraising-toolkit' ) };
        default:          return { cls: 'is-muted', label: status };
    }
}

// Event type → timeline dot variant + label builder. Keys are the types
// EventRecorder actually writes; anything else falls through to readable().
export function eventMeta( event ) {
    const { type } = event;
    switch ( type ) {
        case 'donation.intent_created':
            return { dot: 'is-muted',  label: __( 'Donation started',     'fundraising-toolkit' ) };
        case 'donation.pending':
            return { dot: 'is-muted',  label: __( 'Awaiting payment',     'fundraising-toolkit' ) };
        case 'donation.processing':
            return { dot: 'is-info',   label: __( 'Payment processing',   'fundraising-toolkit' ) };
        case 'donation.completed':
            return { dot: 'is-ok',     label: __( 'Donation paid',        'fundraising-toolkit' ) };
        case 'donation.failed':
            return { dot: 'is-error',  label: __( 'Payment failed',       'fundraising-toolkit' ) };
        case 'donation.refunded':
            return { dot: 'is-error',  label: __( 'Refund issued',        'fundraising-toolkit' ) };
        case 'donation.refund_reversed':
            return { dot: 'is-warn',   label: __( 'Refund reversed',      'fundraising-toolkit' ) };
        case 'donation.disputed':
            return { dot: 'is-warn',   label: __( 'Dispute opened',       'fundraising-toolkit' ) };
        case 'donation.reversal_reinstated':
            return { dot: 'is-warn',   label: __( 'Reversal reinstated',  'fundraising-toolkit' ) };
        case 'receipt.issued':
            return { dot: 'is-info',   label: __( 'Receipt issued',       'fundraising-toolkit' ) };
        case 'recurring.renewed':
            return { dot: 'is-ok',     label: __( 'Recurring payment',    'fundraising-toolkit' ) };
        case 'recurring.paused':
            return { dot: 'is-muted',  label: __( 'Recurring paused',     'fundraising-toolkit' ) };
        case 'recurring.resumed':
            return { dot: 'is-ok',     label: __( 'Recurring resumed',    'fundraising-toolkit' ) };
        case 'recurring.skipped':
            return { dot: 'is-muted',  label: __( 'Next payment skipped', 'fundraising-toolkit' ) };
        case 'recurring.amount_changed':
            return { dot: 'is-violet', label: __( 'Recurring amount changed', 'fundraising-toolkit' ) };
        case 'recurring.cancelled_by_admin':
            return { dot: 'is-muted',  label: __( 'Recurring plan cancelled', 'fundraising-toolkit' ) };
        case 'recurring.failed':
            return { dot: 'is-error',  label: __( 'Renewal failed',       'fundraising-toolkit' ) };
        case 'recurring.cancelled':
            return { dot: 'is-muted',  label: __( 'Recurring plan cancelled', 'fundraising-toolkit' ) };
        case 'recurring.subscription_creation_failed':
            return { dot: 'is-error',  label: __( 'Subscription not created',  'fundraising-toolkit' ) };
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
    champions:   __( 'Champion',     'fundraising-toolkit' ),
    loyal:       __( 'Loyal',        'fundraising-toolkit' ),
    new:         __( 'New',          'fundraising-toolkit' ),
    at_risk:     __( 'At risk',      'fundraising-toolkit' ),
    hibernating: __( 'Hibernating',  'fundraising-toolkit' ),
    lost:        __( 'Lost',         'fundraising-toolkit' ),
    other:       __( 'Other',        'fundraising-toolkit' ),
};
