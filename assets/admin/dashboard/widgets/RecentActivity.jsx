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
                title={ __( 'No donations yet', 'giveflow-fundraising-campaigns' ) }
                body={ __( 'Donor activity rolls in here as soon as your first donation is received.', 'giveflow-fundraising-campaigns' ) }
            />
        );
    }

    return (
        <ul className="giveflow-activity">
            { rows.map( ( r ) => (
                <li key={ r.id } className="giveflow-activity__row">
                    <span className={ `giveflow-activity__dot ${ freqDot[ r.frequency ] || 'is-onetime' }` }
                          title={ r.frequency === 'one_time'
                              ? __( 'One-time', 'giveflow-fundraising-campaigns' )
                              : __( 'Recurring', 'giveflow-fundraising-campaigns' ) }
                          aria-hidden="true" />
                    <div className="giveflow-activity__body">
                        <div className="giveflow-activity__top">
                            <span className="giveflow-activity__donor">
                                { r.donor_name }
                                { r.is_test && (
                                    <span className="giveflow-pill giveflow-pill--test">{ __( 'Test', 'giveflow-fundraising-campaigns' ) }</span>
                                ) }
                            </span>
                            <span className="giveflow-activity__amount">
                                { formatAmount( r.amount_cents, r.currency ) }
                            </span>
                        </div>
                        <div className="giveflow-activity__sub">
                            { r.campaign_id && r.campaign_title ? (
                                <a href={ detailHref( r.campaign_id ) } className="giveflow-activity__campaign">
                                    { r.campaign_title }
                                </a>
                            ) : (
                                <span className="giveflow-activity__campaign">{ __( 'No campaign', 'giveflow-fundraising-campaigns' ) }</span>
                            ) }
                            <span className="giveflow-activity__when">{ timeAgo( r.paid_at ) }</span>
                        </div>
                    </div>
                </li>
            ) ) }
        </ul>
    );
}
