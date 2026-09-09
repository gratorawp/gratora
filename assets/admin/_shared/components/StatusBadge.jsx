import { __ } from '@wordpress/i18n';

/**
 * Single status-pill renderer for the whole admin: status -> .gratora-pill--{variant}
 * + default label. Status strings don't collide across domains; pass `label` to
 * override (e.g. "Published" instead of "Active").
 */
const STATUS = {
    // Campaign / form lifecycle. Three states, three colours: draft and
    // archived were both gray, so the only thing telling a campaign that has
    // never opened from one that is finished with was the word inside the pill.
    // Amber reads as unfinished here as it does on a pending donation.
    draft:              { variant: 'amber', label: __( 'Draft', 'gratora' ) },
    published:          { variant: 'green', label: __( 'Active', 'gratora' ) },
    archived:           { variant: 'gray',  label: __( 'Archived', 'gratora' ) },
    // Derived, not stored: a published campaign outside its window or past its
    // goal is still "published" in the row. Showing that as Active is what the
    // status column is for avoiding.
    scheduled:          { variant: 'amber', label: __( 'Scheduled', 'gratora' ) },
    ended:              { variant: 'gray',  label: __( 'Ended', 'gratora' ) },
    goal_met:           { variant: 'blue',  label: __( 'Goal met', 'gratora' ) },
    // Donation lifecycle
    paid:               { variant: 'green', label: __( 'Paid', 'gratora' ) },
    pending:            { variant: 'amber', label: __( 'Pending', 'gratora' ) },
    processing:         { variant: 'amber', label: __( 'Processing', 'gratora' ) },
    failed:             { variant: 'red',   label: __( 'Failed', 'gratora' ) },
    refunded:           { variant: 'blue',  label: __( 'Refunded', 'gratora' ) },
    partial_refund:     { variant: 'blue',  label: __( 'Partially refunded', 'gratora' ) },
    partially_refunded: { variant: 'blue',  label: __( 'Partially refunded', 'gratora' ) },
    disputed:           { variant: 'red',   label: __( 'Disputed', 'gratora' ) },
    cancelled:          { variant: 'gray',  label: __( 'Cancelled', 'gratora' ) },
    // Recurring plan lifecycle. Here rather than hand-rolled on the
    // subscriptions screen, so a plan's status pill matches a donation's.
    active:             { variant: 'green', label: __( 'Active', 'gratora' ) },
    past_due:           { variant: 'amber', label: __( 'Past due', 'gratora' ) },
    paused:             { variant: 'gray',  label: __( 'Paused', 'gratora' ) },
    expired:            { variant: 'gray',  label: __( 'Expired', 'gratora' ) },
};

export default function StatusBadge( { status, label } ) {
    const s = STATUS[ status ] || { variant: 'gray', label: ( status || '' ).replace( /_/g, ' ' ) };
    return <span className={ `gratora-pill gratora-pill--${ s.variant }` }>{ label || s.label }</span>;
}
