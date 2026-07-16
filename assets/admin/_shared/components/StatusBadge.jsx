import { __ } from '@wordpress/i18n';

/**
 * Single status-pill renderer for the whole admin: status -> .dono-pill--{variant}
 * + default label. Status strings don't collide across domains; pass `label` to
 * override (e.g. "Published" instead of "Active").
 */
const STATUS = {
    // Campaign / form lifecycle
    draft:              { variant: 'gray',  label: __( 'Draft', 'dono' ) },
    published:          { variant: 'green', label: __( 'Active', 'dono' ) },
    archived:           { variant: 'gray',  label: __( 'Archived', 'dono' ) },
    // Donation lifecycle
    paid:               { variant: 'green', label: __( 'Paid', 'dono' ) },
    pending:            { variant: 'amber', label: __( 'Pending', 'dono' ) },
    processing:         { variant: 'amber', label: __( 'Processing', 'dono' ) },
    failed:             { variant: 'red',   label: __( 'Failed', 'dono' ) },
    refunded:           { variant: 'blue',  label: __( 'Refunded', 'dono' ) },
    partial_refund:     { variant: 'blue',  label: __( 'Partially refunded', 'dono' ) },
    partially_refunded: { variant: 'blue',  label: __( 'Partially refunded', 'dono' ) },
    disputed:           { variant: 'red',   label: __( 'Disputed', 'dono' ) },
    abandoned:          { variant: 'gray',  label: __( 'Abandoned', 'dono' ) },
    cancelled:          { variant: 'gray',  label: __( 'Cancelled', 'dono' ) },
};

export default function StatusBadge( { status, label } ) {
    const s = STATUS[ status ] || { variant: 'gray', label: ( status || '' ).replace( /_/g, ' ' ) };
    return <span className={ `dono-pill dono-pill--${ s.variant }` }>{ label || s.label }</span>;
}
