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
                title={ __( 'No donations yet', 'gratora' ) }
                body={ __( 'Donor activity rolls in here as soon as your first donation is received.', 'gratora' ) }
            />
        );
    }

    return (
        <ul className="gratora-activity">
            { rows.map( ( r ) => (
                <li key={ r.id } className="gratora-activity__row">
                    <span className={ `gratora-activity__dot ${ freqDot[ r.frequency ] || 'is-onetime' }` }
                          title={ r.frequency === 'one_time'
                              ? __( 'One-time', 'gratora' )
                              : __( 'Recurring', 'gratora' ) }
                          aria-hidden="true" />
                    <div className="gratora-activity__body">
                        <div className="gratora-activity__top">
                            <span className="gratora-activity__donor">
                                { r.donor_name }
                                { r.is_test && (
                                    <span className="gratora-pill gratora-pill--test">{ __( 'Test', 'gratora' ) }</span>
                                ) }
                            </span>
                            <span className="gratora-activity__amount">
                                { formatAmount( r.amount_cents, r.currency ) }
                            </span>
                        </div>
                        <div className="gratora-activity__sub">
                            { r.campaign_id && r.campaign_title ? (
                                <a href={ detailHref( r.campaign_id ) } className="gratora-activity__campaign">
                                    { r.campaign_title }
                                </a>
                            ) : (
                                <span className="gratora-activity__campaign">{ __( 'No campaign', 'gratora' ) }</span>
                            ) }
                            <span className="gratora-activity__when">{ timeAgo( r.paid_at ) }</span>
                        </div>
                    </div>
                </li>
            ) ) }
        </ul>
    );
}
