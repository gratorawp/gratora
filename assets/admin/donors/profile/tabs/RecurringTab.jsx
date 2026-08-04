import { __, _n, sprintf } from '@wordpress/i18n';

import { formatAmount, formatDate, planStatusPill } from '../helpers';

/**
 * "month", "2 week" and so on came straight from the database, so the cell
 * never translated and never pluralised. Spelled out per unit because a
 * translator needs both forms and the singular is not the column value.
 */
function intervalLabel( unit, count ) {
    const n = Number( count ) || 1;
    switch ( unit ) {
        case 'day':
            /* translators: %d: number of days between charges. */
            return sprintf( _n( '%d day', '%d days', n, 'dono' ), n );
        case 'week':
            /* translators: %d: number of weeks between charges. */
            return sprintf( _n( '%d week', '%d weeks', n, 'dono' ), n );
        case 'month':
            /* translators: %d: number of months between charges. */
            return sprintf( _n( '%d month', '%d months', n, 'dono' ), n );
        case 'year':
            /* translators: %d: number of years between charges. */
            return sprintf( _n( '%d year', '%d years', n, 'dono' ), n );
        default:
            return n > 1 ? `${ n } ${ unit }` : String( unit );
    }
}

export default function RecurringTab( { recurring } ) {
    const plans = recurring?.plans || [];
    return (
        <div>
            <div className="dp-card">
                { plans.length === 0
                    ? <div className="dp-table-empty">{ __( 'No subscriptions on file.', 'dono' ) }</div>
                    : (
                        <div style={ { overflowX: 'auto' } }>
                            <table className="dp-table">
                                <thead>
                                    <tr>
                                        <th>{ __( 'Plan', 'dono' ) }</th>
                                        <th>{ __( 'Amount / interval', 'dono' ) }</th>
                                        <th>{ __( 'Status', 'dono' ) }</th>
                                        <th>{ __( 'Next charge', 'dono' ) }</th>
                                        <th className="num-cell">{ __( 'Failed', 'dono' ) }</th>
                                        <th className="num-cell">{ __( 'Lifetime', 'dono' ) }</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    { plans.map( ( p ) => {
                                        const pill = planStatusPill( p.status );
                                        return (
                                            <tr key={ p.id } className={ p.failed_renewals_count > 0 ? 'is-warn-halo' : '' }>
                                                <td>
                                                    <div style={ { fontWeight: 500 } }>{ p.gateway }</div>
                                                    <code style={ { fontSize: 11, color: 'var(--text-muted, #6b7280)' } }>
                                                        { p.gateway_subscription_id }
                                                    </code>
                                                </td>
                                                <td>{ formatAmount( p.amount_cents, p.currency ) } / { intervalLabel( p.interval_unit, p.interval_count ) }</td>
                                                <td><span className={ `dp-pill ${ pill.cls }` }>{ pill.label }</span></td>
                                                <td>{ p.status === 'cancelled' ? '-' : formatDate( p.next_payment_at ) }</td>
                                                <td className="num-cell">
                                                    { p.failed_renewals_count > 0
                                                        ? <strong style={ { color: 'var(--amber, #b97a05)' } }>{ p.failed_renewals_count }</strong>
                                                        : '-' }
                                                </td>
                                                <td className="num-cell">{ formatAmount( p.total_paid_cents, p.currency ) }</td>
                                            </tr>
                                        );
                                    } ) }
                                </tbody>
                            </table>
                        </div>
                    ) }
            </div>
        </div>
    );
}
