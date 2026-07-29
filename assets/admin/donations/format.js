// Shared formatters for the donations admin app.

import { __ } from '@wordpress/i18n';

export { formatAmount, formatAmountCompact } from '../_shared/format';

export function formatDate( iso, opts = {} ) {
    if ( ! iso ) return '-';
    const d = new Date( String( iso ).replace( ' ', 'T' ) );
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
