// Shared formatters for the donations admin app.

import { __ } from '@wordpress/i18n';
// MySQL strings arrive in UTC with no zone marker, which a browser reads as
// local time. parseTimestamp marks them.
import { parseTimestamp } from '@dono/ui/utils/format';

export { formatAmount, formatAmountCompact } from '../_shared/format';

export function formatDate( iso, opts = {} ) {
    if ( ! iso ) return '-';
    const d = parseTimestamp( iso );
    if ( Number.isNaN( d.getTime() ) ) return iso;
    return d.toLocaleString( undefined, {
        year:   'numeric',
        month:  'short',
        day:    '2-digit',
        hour:   '2-digit',
        minute: '2-digit',
        ...opts,
    } );
}

export const STATUS_LABEL = {
    pending:        __( 'Pending', 'dono-fundraising-platform' ),
    processing:     __( 'Processing', 'dono-fundraising-platform' ),
    paid:           __( 'Paid', 'dono-fundraising-platform' ),
    failed:         __( 'Failed', 'dono-fundraising-platform' ),
    refunded:       __( 'Refunded', 'dono-fundraising-platform' ),
    partial_refund: __( 'Partially refunded', 'dono-fundraising-platform' ),
    disputed:       __( 'Disputed', 'dono-fundraising-platform' ),
};
