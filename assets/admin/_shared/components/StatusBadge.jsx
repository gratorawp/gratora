import { __ } from '@wordpress/i18n';

/**
 * Single status-pill renderer for the whole admin: status -> .giveflow-pill--{variant}
 * + default label. Status strings don't collide across domains; pass `label` to
 * override (e.g. "Published" instead of "Active").
 */
const STATUS = {
    // Campaign / form lifecycle. Three states, three colours: draft and
    // archived were both gray, so the only thing telling a campaign that has
    // never opened from one that is finished with was the word inside the pill.
    // Amber reads as unfinished here as it does on a pending donation.
    draft:              { variant: 'amber', label: __( 'Draft', 'giveflow-fundraising-campaigns' ) },
    published:          { variant: 'green', label: __( 'Active', 'giveflow-fundraising-campaigns' ) },
    archived:           { variant: 'gray',  label: __( 'Archived', 'giveflow-fundraising-campaigns' ) },
    // Donation lifecycle
    paid:               { variant: 'green', label: __( 'Paid', 'giveflow-fundraising-campaigns' ) },
    pending:            { variant: 'amber', label: __( 'Pending', 'giveflow-fundraising-campaigns' ) },
    processing:         { variant: 'amber', label: __( 'Processing', 'giveflow-fundraising-campaigns' ) },
    failed:             { variant: 'red',   label: __( 'Failed', 'giveflow-fundraising-campaigns' ) },
    refunded:           { variant: 'blue',  label: __( 'Refunded', 'giveflow-fundraising-campaigns' ) },
    partial_refund:     { variant: 'blue',  label: __( 'Partially refunded', 'giveflow-fundraising-campaigns' ) },
    partially_refunded: { variant: 'blue',  label: __( 'Partially refunded', 'giveflow-fundraising-campaigns' ) },
    disputed:           { variant: 'red',   label: __( 'Disputed', 'giveflow-fundraising-campaigns' ) },
    abandoned:          { variant: 'gray',  label: __( 'Abandoned', 'giveflow-fundraising-campaigns' ) },
    cancelled:          { variant: 'gray',  label: __( 'Cancelled', 'giveflow-fundraising-campaigns' ) },
    // Recurring plan lifecycle. Here rather than hand-rolled on the
    // subscriptions screen, so a plan's status pill matches a donation's.
    active:             { variant: 'green', label: __( 'Active', 'giveflow-fundraising-campaigns' ) },
    past_due:           { variant: 'amber', label: __( 'Past due', 'giveflow-fundraising-campaigns' ) },
    paused:             { variant: 'gray',  label: __( 'Paused', 'giveflow-fundraising-campaigns' ) },
    expired:            { variant: 'gray',  label: __( 'Expired', 'giveflow-fundraising-campaigns' ) },
};

export default function StatusBadge( { status, label } ) {
    const s = STATUS[ status ] || { variant: 'gray', label: ( status || '' ).replace( /_/g, ' ' ) };
    return <span className={ `giveflow-pill giveflow-pill--${ s.variant }` }>{ label || s.label }</span>;
}
