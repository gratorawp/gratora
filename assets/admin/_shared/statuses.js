import { __ } from '@wordpress/i18n';

/**
 * Every status the admin can print, and the colour it wears.
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
    // Recurring plan lifecycle. Here rather than hand-rolled on the
    // subscriptions screen, so a plan's status pill matches a donation's.
    active:             { variant: 'green', label: __( 'Active', 'gratora-donation-platform' ) },
    past_due:           { variant: 'amber', label: __( 'Past due', 'gratora-donation-platform' ) },
    paused:             { variant: 'gray',  label: __( 'Paused', 'gratora-donation-platform' ) },
    expired:            { variant: 'gray',  label: __( 'Expired', 'gratora-donation-platform' ) },
};

/**
 * Every status the plans table holds, in the order a plan passes through them.
 *
 * `pending` is where a PayPal plan begins: the subscription is recorded when
 * the donor approves it and only becomes active when PayPal says so, which can
 * be never. The server writes these; adding one there means adding it here.
 */
export const PLAN_STATUSES = [ 'active', 'pending', 'past_due', 'paused', 'cancelled', 'expired' ];

/** The word and the colour for a status, or the slug spaced out if it is new. */
export function statusMeta( status ) {
    return STATUS[ status ] || { variant: 'gray', label: String( status || '' ).replace( /_/g, ' ' ) };
}

/** The plan lifecycle as a DataViews filter offers it. */
export function planStatusOptions() {
    return PLAN_STATUSES.map( ( value ) => ( { value, label: statusMeta( value ).label } ) );
}
