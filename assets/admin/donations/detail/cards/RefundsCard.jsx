import { __ } from '@wordpress/i18n';

import { formatAmount, formatDateTime, timeAgo } from '../helpers';

export default function RefundsCard( { donation, refunds, onIssue } ) {
    if ( ! refunds || refunds.length === 0 ) {
        return null;
    }

    return (
        <div className="dd-card">
            <div className="dd-card__body" style={ { padding: '14px 0' } }>
                <div style={ { padding: '0 18px 12px', display: 'flex', justifyContent: 'space-between', alignItems: 'center' } }>
                    <span className="dd-pill is-info">
                        { formatAmount( donation.refunded_cents, donation.currency ) } { __( 'refunded', 'dono-fundraising-platform' ) }
                    </span>
                    { donation.refundable_cents > 0 && (
                        <button type="button" className="btn--link" onClick={ onIssue }>
                            { __( 'Issue another refund →', 'dono-fundraising-platform' ) }
                        </button>
                    ) }
                </div>
                <div style={ { overflowX: 'auto' } }>
                    <table className="dd-table">
                        <thead>
                            <tr>
                                <th>{ __( 'When', 'dono-fundraising-platform' ) }</th>
                                <th style={ { textAlign: 'right' } }>{ __( 'Amount', 'dono-fundraising-platform' ) }</th>
                                <th>{ __( 'Reason', 'dono-fundraising-platform' ) }</th>
                                <th>{ __( 'Gateway ID', 'dono-fundraising-platform' ) }</th>
                                <th>{ __( 'Status', 'dono-fundraising-platform' ) }</th>
                            </tr>
                        </thead>
                        <tbody>
                            { refunds.map( ( r ) => (
                                <tr key={ r.id }>
                                    <td>
                                        { timeAgo( r.occurred_at ) }
                                        <span className="dd-table__sub">{ formatDateTime( r.occurred_at ) }</span>
                                    </td>
                                    <td className="num-cell">{ formatAmount( r.amount_cents, r.currency ) }</td>
                                    <td>{ r.reason || <span className="muted">-</span> }</td>
                                    <td className="mono">{ r.gateway_refund_id || <span className="muted">-</span> }</td>
                                    <td>
                                        <span className={ `dd-pill ${ r.status === 'succeeded' ? 'is-ok' : 'is-warn' }` }>
                                            { r.status }
                                        </span>
                                    </td>
                                </tr>
                            ) ) }
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}
