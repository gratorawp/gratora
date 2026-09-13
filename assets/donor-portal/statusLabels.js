/**
 * A plan's status is written on the server, and so are the words for it: the
 * portal is handed the whole lifecycle with the page. Kept as its own list
 * here, a status added on that side reached the donor as a database token in
 * English however their site was translated. past_due especially, since it is
 * what a declined renewal leaves behind and the dunning email sends the donor
 * straight here to fix it.
 */

const shipped = () => {
    const list = window.gratoraPortal?.planStatuses;

    return Array.isArray( list ) ? list : [];
};

export function recurringStatusLabel( s ) {
    const found = shipped().find( ( status ) => status.value === s );

    // Words rather than a token, for a portal page served without the config.
    return found ? found.label : String( s || '' ).replace( /_/g, ' ' );
}

/** Nothing is left to change once a plan has stopped. */
export function isTerminalPlan( s ) {
    return s === 'cancelled' || s === 'expired';
}
