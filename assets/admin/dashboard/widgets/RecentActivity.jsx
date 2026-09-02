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
                title={ __( 'No donations yet', 'fundraising-toolkit' ) }
                body={ __( 'Donor activity rolls in here as soon as your first donation is received.', 'fundraising-toolkit' ) }
            />
        );
    }

    return (
        <ul className="fundkit-activity">
            { rows.map( ( r ) => (
                <li key={ r.id } className="fundkit-activity__row">
                    <span className={ `fundkit-activity__dot ${ freqDot[ r.frequency ] || 'is-onetime' }` }
                          title={ r.frequency === 'one_time'
                              ? __( 'One-time', 'fundraising-toolkit' )
                              : __( 'Recurring', 'fundraising-toolkit' ) }
                          aria-hidden="true" />
                    <div className="fundkit-activity__body">
                        <div className="fundkit-activity__top">
                            <span className="fundkit-activity__donor">
                                { r.donor_name }
                                { r.is_test && (
                                    <span className="fundkit-pill fundkit-pill--test">{ __( 'Test', 'fundraising-toolkit' ) }</span>
                                ) }
                            </span>
                            <span className="fundkit-activity__amount">
                                { formatAmount( r.amount_cents, r.currency ) }
                            </span>
                        </div>
                        <div className="fundkit-activity__sub">
                            { r.campaign_id && r.campaign_title ? (
                                <a href={ detailHref( r.campaign_id ) } className="fundkit-activity__campaign">
                                    { r.campaign_title }
                                </a>
                            ) : (
                                <span className="fundkit-activity__campaign">{ __( 'No campaign', 'fundraising-toolkit' ) }</span>
                            ) }
                            <span className="fundkit-activity__when">{ timeAgo( r.paid_at ) }</span>
                        </div>
                    </div>
                </li>
            ) ) }
        </ul>
    );
}
