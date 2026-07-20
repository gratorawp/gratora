import { __ } from '@wordpress/i18n';
import { Coins } from 'lucide-react';

import EmptyState from '../../_shared/components/EmptyState';
import { formatAmount, timeAgo, detailHref } from '../../_shared/format';

const freqDot = {
    weekly:    'is-recurring',
    biweekly:  'is-recurring',
    monthly:   'is-recurring',
    quarterly: 'is-recurring',
    yearly:    'is-recurring',
};

export default function RecentActivity( { rows = [] } ) {
    if ( rows.length === 0 ) {
        return (
            <EmptyState
                compact
                icon={ <Coins size={ 22 } strokeWidth={ 1.75 } /> }
                title={ __( 'No donations yet', 'dono' ) }
                body={ __( 'Donor activity rolls in here as soon as your first donation is received.', 'dono' ) }
            />
        );
    }

    return (
        <ul className="dono-activity">
            { rows.map( ( r ) => (
                <li key={ r.id } className="dono-activity__row">
                    <span className={ `dono-activity__dot ${ freqDot[ r.frequency ] || 'is-onetime' }` }
                          title={ r.frequency === 'one_time'
                              ? __( 'One-time', 'dono' )
                              : __( 'Recurring', 'dono' ) }
                          aria-hidden="true" />
                    <div className="dono-activity__body">
                        <div className="dono-activity__top">
                            <span className="dono-activity__donor">{ r.donor_name }</span>
                            <span className="dono-activity__amount">
                                { formatAmount( r.amount_cents, r.currency ) }
                            </span>
                        </div>
                        <div className="dono-activity__sub">
                            { r.campaign_id && r.campaign_title ? (
                                <a href={ detailHref( r.campaign_id ) } className="dono-activity__campaign">
                                    { r.campaign_title }
                                </a>
                            ) : (
                                <span className="dono-activity__campaign">{ __( 'No campaign', 'dono' ) }</span>
                            ) }
                            <span className="dono-activity__when">{ timeAgo( r.paid_at ) }</span>
                        </div>
                    </div>
                </li>
            ) ) }
        </ul>
    );
}
