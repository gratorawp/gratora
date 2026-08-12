import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

import { formatAmount, formatDate, donationStatusPill } from '../helpers';

function donationHref( reference ) {
    return addQueryArgs( window.location.pathname, {
        page:      'dono-donations',
        view:      'detail',
        reference,
    } );
}

export default function RelatedDonationsCard( { donor, related } ) {
    if ( ! donor || ! related || related.length <= 1 ) return null;
    return (
        <div className="dd-card">
            <div className="dd-card__body" style={ { padding: '14px 0' } }>
                <div style={ { overflowX: 'auto' } }>
                    <table className="dd-table">
                        <thead>
                            <tr>
                                <th>{ __( 'Reference', 'dono-fundraising-platform' ) }</th>
                                <th>{ __( 'Date', 'dono-fundraising-platform' ) }</th>
                                <th style={ { textAlign: 'right' } }>{ __( 'Amount', 'dono-fundraising-platform' ) }</th>
                                <th>{ __( 'Status', 'dono-fundraising-platform' ) }</th>
                            </tr>
                        </thead>
                        <tbody>
                            { related.map( ( d ) => {
                                const pill = donationStatusPill( d.status );
                                return (
                                    <tr key={ d.id }>
                                        <td className="ref-cell">
                                            <a href={ donationHref( d.reference ) }>{ d.reference }</a>
                                            { d.is_self && <span className="muted" style={ { fontFamily: 'inherit', fontSize: 11, marginLeft: 6 } }>{ __( '(this one)', 'dono-fundraising-platform' ) }</span> }
                                        </td>
                                        <td>{ formatDate( d.paid_at || d.created_at ) }</td>
                                        <td className="num-cell">{ formatAmount( d.amount_cents, d.currency ) }</td>
                                        <td><span className={ `dd-pill ${ pill.cls }` }>{ pill.label }</span></td>
                                    </tr>
                                );
                            } ) }
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}
