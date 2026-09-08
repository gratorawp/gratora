import { __ } from '@wordpress/i18n';

/**
 * Single status-pill renderer for the whole admin: status -> .fundkit-pill--{variant}
 * + default label. Status strings don't collide across domains; pass `label` to
 * override (e.g. "Published" instead of "Active").
 */
const STATUS = {
    // Campaign / form lifecycle. Three states, three colours: draft and
    // archived were both gray, so the only thing telling a campaign that has
    // never opened from one that is finished with was the word inside the pill.
    // Amber reads as unfinished here as it does on a pending donation.
    draft:              { variant: 'amber', label: __( 'Draft', 'fundraising-toolkit' ) },
    published:          { variant: 'green', label: __( 'Active', 'fundraising-toolkit' ) },
    archived:           { variant: 'gray',  label: __( 'Archived', 'fundraising-toolkit' ) },
    // Derived, not stored: a published campaign outside its window or past its
    // goal is still "published" in the row. Showing that as Active is what the
    // status column is for avoiding.
    scheduled:          { variant: 'amber', label: __( 'Scheduled', 'fundraising-toolkit' ) },
    ended:              { variant: 'gray',  label: __( 'Ended', 'fundraising-toolkit' ) },
    goal_met:           { variant: 'blue',  label: __( 'Goal met', 'fundraising-toolkit' ) },
    // Donation lifecycle
    paid:               { variant: 'green', label: __( 'Paid', 'fundraising-toolkit' ) },
    pending:            { variant: 'amber', label: __( 'Pending', 'fundraising-toolkit' ) },
    processing:         { variant: 'amber', label: __( 'Processing', 'fundraising-toolkit' ) },
    failed:             { variant: 'red',   label: __( 'Failed', 'fundraising-toolkit' ) },
    refunded:           { variant: 'blue',  label: __( 'Refunded', 'fundraising-toolkit' ) },
    partial_refund:     { variant: 'blue',  label: __( 'Partially refunded', 'fundraising-toolkit' ) },
    partially_refunded: { variant: 'blue',  label: __( 'Partially refunded', 'fundraising-toolkit' ) },
    disputed:           { variant: 'red',   label: __( 'Disputed', 'fundraising-toolkit' ) },
    cancelled:          { variant: 'gray',  label: __( 'Cancelled', 'fundraising-toolkit' ) },
    // Recurring plan lifecycle. Here rather than hand-rolled on the
    // subscriptions screen, so a plan's status pill matches a donation's.
    active:             { variant: 'green', label: __( 'Active', 'fundraising-toolkit' ) },
    past_due:           { variant: 'amber', label: __( 'Past due', 'fundraising-toolkit' ) },
    paused:             { variant: 'gray',  label: __( 'Paused', 'fundraising-toolkit' ) },
    expired:            { variant: 'gray',  label: __( 'Expired', 'fundraising-toolkit' ) },
};

export default function StatusBadge( { status, label } ) {
    const s = STATUS[ status ] || { variant: 'gray', label: ( status || '' ).replace( /_/g, ' ' ) };
    return <span className={ `fundkit-pill fundkit-pill--${ s.variant }` }>{ label || s.label }</span>;
}
