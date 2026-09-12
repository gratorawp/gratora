// Shared formatters and event-mapping for the donor profile views.

import { __ } from '@wordpress/i18n';
// Timestamps arrive as MySQL strings in UTC with no zone marker, which a
// browser reads as local time. parseTimestamp marks them.
import { parseTimestamp } from '@gratora/ui/utils/format';

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
        case 'paid':           return { cls: 'is-ok',    label: __( 'Paid',     'gratora-donation-platform' ) };
        case 'pending':        return { cls: 'is-warn',  label: __( 'Pending',  'gratora-donation-platform' ) };
        // Not a warning like pending: the donor has paid and nothing is
        // expected of them, the money is simply still moving.
        case 'processing':     return { cls: 'is-info',  label: __( 'Processing', 'gratora-donation-platform' ) };
        case 'failed':         return { cls: 'is-error', label: __( 'Failed',   'gratora-donation-platform' ) };
        case 'refunded':       return { cls: 'is-info',  label: __( 'Refunded', 'gratora-donation-platform' ) };
        case 'partial_refund': return { cls: 'is-info',  label: __( 'Partial',  'gratora-donation-platform' ) };
        case 'disputed':       return { cls: 'is-warn',  label: __( 'Disputed', 'gratora-donation-platform' ) };
        default:               return { cls: 'is-muted', label: status };
    }
}

// Plan status → pill class + label.
export function planStatusPill( status ) {
    switch ( status ) {
        case 'active':    return { cls: 'is-ok',    label: __( 'Active',    'gratora-donation-platform' ) };
        case 'past_due':  return { cls: 'is-warn',  label: __( 'Past due',  'gratora-donation-platform' ) };
        case 'paused':    return { cls: 'is-muted', label: __( 'Paused',    'gratora-donation-platform' ) };
        case 'cancelled': return { cls: 'is-muted', label: __( 'Cancelled', 'gratora-donation-platform' ) };
        case 'expired':   return { cls: 'is-muted', label: __( 'Expired',   'gratora-donation-platform' ) };
        default:          return { cls: 'is-muted', label: status };
    }
}

// Event type → timeline dot variant + label builder. Keys are the types
// EventRecorder actually writes; anything else falls through to readable().
export function eventMeta( event ) {
    const { type } = event;
    switch ( type ) {
        case 'donation.intent_created':
            return { dot: 'is-muted',  label: __( 'Donation started',     'gratora-donation-platform' ) };
        case 'donation.pending':
            return { dot: 'is-muted',  label: __( 'Awaiting payment',     'gratora-donation-platform' ) };
        case 'donation.processing':
            return { dot: 'is-info',   label: __( 'Payment processing',   'gratora-donation-platform' ) };
        case 'donation.completed':
            return { dot: 'is-ok',     label: __( 'Donation paid',        'gratora-donation-platform' ) };
        case 'donation.failed':
            return { dot: 'is-error',  label: __( 'Payment failed',       'gratora-donation-platform' ) };
        case 'donation.refunded':
            return { dot: 'is-error',  label: __( 'Refund issued',        'gratora-donation-platform' ) };
        case 'donation.refund_reversed':
            return { dot: 'is-warn',   label: __( 'Refund reversed',      'gratora-donation-platform' ) };
        case 'donation.disputed':
            return { dot: 'is-warn',   label: __( 'Dispute opened',       'gratora-donation-platform' ) };
        case 'donation.reversal_reinstated':
            return { dot: 'is-warn',   label: __( 'Reversal reinstated',  'gratora-donation-platform' ) };
        case 'receipt.issued':
            return { dot: 'is-info',   label: __( 'Receipt issued',       'gratora-donation-platform' ) };
        case 'recurring.renewed':
            return { dot: 'is-ok',     label: __( 'Recurring payment',    'gratora-donation-platform' ) };
        case 'recurring.paused':
            return { dot: 'is-muted',  label: __( 'Recurring paused',     'gratora-donation-platform' ) };
        case 'recurring.resumed':
            return { dot: 'is-ok',     label: __( 'Recurring resumed',    'gratora-donation-platform' ) };
        case 'recurring.skipped':
            return { dot: 'is-muted',  label: __( 'Next payment skipped', 'gratora-donation-platform' ) };
        case 'recurring.amount_changed':
            return { dot: 'is-violet', label: __( 'Recurring amount changed', 'gratora-donation-platform' ) };
        case 'recurring.cancelled_by_admin':
            return { dot: 'is-muted',  label: __( 'Recurring plan cancelled', 'gratora-donation-platform' ) };
        case 'recurring.failed':
            return { dot: 'is-error',  label: __( 'Renewal failed',       'gratora-donation-platform' ) };
        case 'recurring.cancelled':
            return { dot: 'is-muted',  label: __( 'Recurring plan cancelled', 'gratora-donation-platform' ) };
        case 'recurring.subscription_creation_failed':
            return { dot: 'is-error',  label: __( 'Subscription not created',  'gratora-donation-platform' ) };
        default:
            // An error carries its own words in the payload, and the fallback
            // would read "error.admin.recurring" as "Recurring", muted, which
            // is worse than absent.
            if ( String( type || '' ).startsWith( 'error.' ) ) {
                return { dot: 'is-error', label: __( 'Action failed', 'gratora-donation-platform' ) };
            }

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
    champions:   __( 'Champion',     'gratora-donation-platform' ),
    loyal:       __( 'Loyal',        'gratora-donation-platform' ),
    new:         __( 'New',          'gratora-donation-platform' ),
    at_risk:     __( 'At risk',      'gratora-donation-platform' ),
    hibernating: __( 'Hibernating',  'gratora-donation-platform' ),
    lost:        __( 'Lost',         'gratora-donation-platform' ),
    other:       __( 'Other',        'gratora-donation-platform' ),
};
