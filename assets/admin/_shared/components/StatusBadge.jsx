import { __ } from '@wordpress/i18n';

/**
 * Single status-pill renderer for the whole admin: status -> .dono-pill--{variant}
 * + default label. Status strings don't collide across domains; pass `label` to
 * override (e.g. "Published" instead of "Active").
 */
const STATUS = {
    // Campaign / form lifecycle
    draft:              { variant: 'gray',  label: __( 'Draft', 'dono-fundraising-platform' ) },
    published:          { variant: 'green', label: __( 'Active', 'dono-fundraising-platform' ) },
    archived:           { variant: 'gray',  label: __( 'Archived', 'dono-fundraising-platform' ) },
    // Donation lifecycle
    paid:               { variant: 'green', label: __( 'Paid', 'dono-fundraising-platform' ) },
    pending:            { variant: 'amber', label: __( 'Pending', 'dono-fundraising-platform' ) },
    processing:         { variant: 'amber', label: __( 'Processing', 'dono-fundraising-platform' ) },
    failed:             { variant: 'red',   label: __( 'Failed', 'dono-fundraising-platform' ) },
    refunded:           { variant: 'blue',  label: __( 'Refunded', 'dono-fundraising-platform' ) },
    partial_refund:     { variant: 'blue',  label: __( 'Partially refunded', 'dono-fundraising-platform' ) },
    partially_refunded: { variant: 'blue',  label: __( 'Partially refunded', 'dono-fundraising-platform' ) },
    disputed:           { variant: 'red',   label: __( 'Disputed', 'dono-fundraising-platform' ) },
    abandoned:          { variant: 'gray',  label: __( 'Abandoned', 'dono-fundraising-platform' ) },
    cancelled:          { variant: 'gray',  label: __( 'Cancelled', 'dono-fundraising-platform' ) },
    // Recurring plan lifecycle. Here rather than hand-rolled on the
    // subscriptions screen, so a plan's status pill matches a donation's.
    active:             { variant: 'green', label: __( 'Active', 'dono-fundraising-platform' ) },
    past_due:           { variant: 'amber', label: __( 'Past due', 'dono-fundraising-platform' ) },
    paused:             { variant: 'gray',  label: __( 'Paused', 'dono-fundraising-platform' ) },
    expired:            { variant: 'gray',  label: __( 'Expired', 'dono-fundraising-platform' ) },
};

export default function StatusBadge( { status, label } ) {
    const s = STATUS[ status ] || { variant: 'gray', label: ( status || '' ).replace( /_/g, ' ' ) };
    return <span className={ `dono-pill dono-pill--${ s.variant }` }>{ label || s.label }</span>;
}
