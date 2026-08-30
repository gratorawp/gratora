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
    draft:              { variant: 'amber', label: __( 'Draft', 'fundkit-fundraising-campaigns' ) },
    published:          { variant: 'green', label: __( 'Active', 'fundkit-fundraising-campaigns' ) },
    archived:           { variant: 'gray',  label: __( 'Archived', 'fundkit-fundraising-campaigns' ) },
    // Donation lifecycle
    paid:               { variant: 'green', label: __( 'Paid', 'fundkit-fundraising-campaigns' ) },
    pending:            { variant: 'amber', label: __( 'Pending', 'fundkit-fundraising-campaigns' ) },
    processing:         { variant: 'amber', label: __( 'Processing', 'fundkit-fundraising-campaigns' ) },
    failed:             { variant: 'red',   label: __( 'Failed', 'fundkit-fundraising-campaigns' ) },
    refunded:           { variant: 'blue',  label: __( 'Refunded', 'fundkit-fundraising-campaigns' ) },
    partial_refund:     { variant: 'blue',  label: __( 'Partially refunded', 'fundkit-fundraising-campaigns' ) },
    partially_refunded: { variant: 'blue',  label: __( 'Partially refunded', 'fundkit-fundraising-campaigns' ) },
    disputed:           { variant: 'red',   label: __( 'Disputed', 'fundkit-fundraising-campaigns' ) },
    abandoned:          { variant: 'gray',  label: __( 'Abandoned', 'fundkit-fundraising-campaigns' ) },
    cancelled:          { variant: 'gray',  label: __( 'Cancelled', 'fundkit-fundraising-campaigns' ) },
    // Recurring plan lifecycle. Here rather than hand-rolled on the
    // subscriptions screen, so a plan's status pill matches a donation's.
    active:             { variant: 'green', label: __( 'Active', 'fundkit-fundraising-campaigns' ) },
    past_due:           { variant: 'amber', label: __( 'Past due', 'fundkit-fundraising-campaigns' ) },
    paused:             { variant: 'gray',  label: __( 'Paused', 'fundkit-fundraising-campaigns' ) },
    expired:            { variant: 'gray',  label: __( 'Expired', 'fundkit-fundraising-campaigns' ) },
};

export default function StatusBadge( { status, label } ) {
    const s = STATUS[ status ] || { variant: 'gray', label: ( status || '' ).replace( /_/g, ' ' ) };
    return <span className={ `fundkit-pill fundkit-pill--${ s.variant }` }>{ label || s.label }</span>;
}
