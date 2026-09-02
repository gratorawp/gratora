import { __ } from '@wordpress/i18n';

// Plan statuses reach the portal exactly as the gateways store them, so an
// unmapped one shows the donor a database token. past_due especially: it is
// what a declined renewal leaves behind, and the dunning email sends the donor
// straight here to fix it.
export function recurringStatusLabel( s ) {
    switch ( s ) {
        case 'active':    return __( 'Active', 'fundraising-toolkit' );
        case 'paused':    return __( 'Paused', 'fundraising-toolkit' );
        case 'past_due':  return __( 'Past due', 'fundraising-toolkit' );
        case 'cancelled': return __( 'Cancelled', 'fundraising-toolkit' );
        case 'expired':   return __( 'Expired', 'fundraising-toolkit' );
        // Words rather than a token, for a status added on the server before
        // this list learns about it.
        default:          return String( s || '' ).replace( /_/g, ' ' );
    }
}
