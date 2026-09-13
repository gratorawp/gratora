import { __ } from '@wordpress/i18n';

/**
 * Every status the admin prints that is not a plan's, and the colour it wears.
 *
 * Status strings do not collide across domains, so one map serves campaigns,
 * donations and plans. A screen that keeps its own list of the same words
 * drifts from this one silently: nothing breaks, a status simply stops having
 * a word on that screen and arrives as the database slug.
 */
const STATUS = {
    // Campaign / form lifecycle. Three states, three colours: draft and
    // archived were both gray, so the only thing telling a campaign that has
    // never opened from one that is finished with was the word inside the pill.
    // Amber reads as unfinished here as it does on a pending donation.
    draft:              { variant: 'amber', label: __( 'Draft', 'gratora-donation-platform' ) },
    published:          { variant: 'green', label: __( 'Active', 'gratora-donation-platform' ) },
    archived:           { variant: 'gray',  label: __( 'Archived', 'gratora-donation-platform' ) },
    // Derived, not stored: a published campaign outside its window or past its
    // goal is still "published" in the row. Showing that as Active is what the
    // status column is for avoiding.
    scheduled:          { variant: 'amber', label: __( 'Scheduled', 'gratora-donation-platform' ) },
    ended:              { variant: 'gray',  label: __( 'Ended', 'gratora-donation-platform' ) },
    goal_met:           { variant: 'blue',  label: __( 'Goal met', 'gratora-donation-platform' ) },
    // Donation lifecycle
    paid:               { variant: 'green', label: __( 'Paid', 'gratora-donation-platform' ) },
    pending:            { variant: 'amber', label: __( 'Pending', 'gratora-donation-platform' ) },
    processing:         { variant: 'amber', label: __( 'Processing', 'gratora-donation-platform' ) },
    failed:             { variant: 'red',   label: __( 'Failed', 'gratora-donation-platform' ) },
    refunded:           { variant: 'blue',  label: __( 'Refunded', 'gratora-donation-platform' ) },
    partial_refund:     { variant: 'blue',  label: __( 'Partially refunded', 'gratora-donation-platform' ) },
    partially_refunded: { variant: 'blue',  label: __( 'Partially refunded', 'gratora-donation-platform' ) },
    disputed:           { variant: 'red',   label: __( 'Disputed', 'gratora-donation-platform' ) },
    cancelled:          { variant: 'gray',  label: __( 'Cancelled', 'gratora-donation-platform' ) },
    // A plan's own statuses are not here: they are written on the server and
    // arrive with their words, through planStatusMeta. These are the ones a
    // plan shares with a campaign or a donation.
    active:             { variant: 'green', label: __( 'Active', 'gratora-donation-platform' ) },
};

/** The word and the colour for a status, or the slug spaced out if it is new. */
export function statusMeta( status ) {
    return STATUS[ status ] || { variant: 'gray', label: String( status || '' ).replace( /_/g, ' ' ) };
}

/**
 * The plan lifecycle, as the server that writes those statuses describes it.
 *
 * Read at call time rather than at import, because the admin config is put on
 * the page for the bundle to find and a module evaluated first would cache an
 * empty list forever.
 */
function shipped() {
    const list = window.gratora?.plan_statuses;

    return Array.isArray( list ) ? list : [];
}

/** Every status a plan can hold, in the order it passes through them. */
export function planStatuses() {
    return shipped().map( ( s ) => s.value );
}

/** The word and the colour for one of them. */
export function planStatusMeta( status ) {
    const found = shipped().find( ( s ) => s.value === status );

    return found ? { variant: found.variant, label: found.label } : statusMeta( status );
}

/**
 * A plan that has ended, and one the gateway has not started. Both are the
 * server's rules: a screen deciding for itself is how a subscription waiting
 * on PayPal came to be offered a pause.
 */
export function isPlanTerminal( status ) {
    const found = shipped().find( ( s ) => s.value === status );

    return found ? !! found.terminal : ( status === 'cancelled' || status === 'expired' );
}

export function isPlanUnstarted( status ) {
    const found = shipped().find( ( s ) => s.value === status );

    return found ? !! found.unstarted : status === 'pending';
}

/** The plan lifecycle as a DataViews filter offers it. */
export function planStatusOptions() {
    return shipped().map( ( { value, label } ) => ( { value, label } ) );
}
