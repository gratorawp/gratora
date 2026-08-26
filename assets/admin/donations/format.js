// Shared formatters for the donations admin app.

import { __ } from '@wordpress/i18n';
// MySQL strings arrive in UTC with no zone marker, which a browser reads as
// local time. parseTimestamp marks them.
import { parseTimestamp } from '@giveflow/ui/utils/format';

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
    pending:        __( 'Pending', 'giveflow-fundraising-campaigns' ),
    processing:     __( 'Processing', 'giveflow-fundraising-campaigns' ),
    paid:           __( 'Paid', 'giveflow-fundraising-campaigns' ),
    failed:         __( 'Failed', 'giveflow-fundraising-campaigns' ),
    refunded:       __( 'Refunded', 'giveflow-fundraising-campaigns' ),
    partial_refund: __( 'Partially refunded', 'giveflow-fundraising-campaigns' ),
    disputed:       __( 'Disputed', 'giveflow-fundraising-campaigns' ),
};
