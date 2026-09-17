// Shared formatters for the donations admin app.

import { __ } from '@wordpress/i18n';

export { formatAmount, formatAmountCompact, formatDateTime } from '../_shared/format';

export const STATUS_LABEL = {
    pending:        __( 'Pending', 'gratora-donation-platform' ),
    processing:     __( 'Processing', 'gratora-donation-platform' ),
    paid:           __( 'Paid', 'gratora-donation-platform' ),
    failed:         __( 'Failed', 'gratora-donation-platform' ),
    refunded:       __( 'Refunded', 'gratora-donation-platform' ),
    partial_refund: __( 'Partially refunded', 'gratora-donation-platform' ),
    disputed:       __( 'Disputed', 'gratora-donation-platform' ),
};
