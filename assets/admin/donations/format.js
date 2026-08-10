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
    pending:        __( 'Pending', 'dono' ),
    processing:     __( 'Processing', 'dono' ),
    paid:           __( 'Paid', 'dono' ),
    failed:         __( 'Failed', 'dono' ),
    refunded:       __( 'Refunded', 'dono' ),
    partial_refund: __( 'Partially refunded', 'dono' ),
    disputed:       __( 'Disputed', 'dono' ),
};
