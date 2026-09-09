import { __ } from '@wordpress/i18n';

// Plan statuses reach the portal exactly as the gateways store them, so an
// unmapped one shows the donor a database token. past_due especially: it is
// what a declined renewal leaves behind, and the dunning email sends the donor
// straight here to fix it.
export function recurringStatusLabel( s ) {
    switch ( s ) {
        case 'active':    return __( 'Active', 'gratora' );
        case 'paused':    return __( 'Paused', 'gratora' );
        case 'past_due':  return __( 'Past due', 'gratora' );
        case 'cancelled': return __( 'Cancelled', 'gratora' );
        case 'expired':   return __( 'Expired', 'gratora' );
        // Words rather than a token, for a status added on the server before
        // this list learns about it.
        default:          return String( s || '' ).replace( /_/g, ' ' );
    }
}

// Nothing is left to change once a plan has stopped, so the manage modal shows
// facts and no actions.
export function isTerminalPlan( s ) {
    return s === 'cancelled' || s === 'expired';
}
