import { __ } from '@wordpress/i18n';

import { formatAmount, formatDate, timeAgo } from '../helpers';
import { detailHref as campaignHref } from '../../../_shared/format';

export default function QuickStatsCard( { donor, donation, related } ) {
    const previous = ( related || [] ).find( ( r ) => ! r.is_self );

    return (
        <div className="dd-rail-card">
            <div className="dd-rail-card__head">
                <span className="dd-rail-card__title">{ __( 'Quick stats', 'gratora-donation-platform' ) }</span>
            </div>
            <div className="dd-rail-card__body">
                <div className="dd-rail-stat">
                    <span className="dd-rail-stat__lbl">{ __( 'Donor lifetime', 'gratora-donation-platform' ) }</span>
                    <span className="dd-rail-stat__val num">
                        { donor?.lifetime
                            ? `${ formatAmount( donor.lifetime.total_cents, donor.lifetime.currency || donation.currency ) } · ${ donor.lifetime.count }`
                            : '-' }
                    </span>
                </div>
                <div className="dd-rail-stat">
                    <span className="dd-rail-stat__lbl">{ __( 'Previous donation', 'gratora-donation-platform' ) }</span>
                    <span className="dd-rail-stat__val num">
                        { previous
                            ? `${ formatAmount( previous.amount_cents, previous.currency ) } · ${ timeAgo( previous.paid_at || previous.created_at ) }`
                            : __( 'None', 'gratora-donation-platform' ) }
                    </span>
                </div>
                { donation.campaign && (
                    <div className="dd-rail-stat">
                        <span className="dd-rail-stat__lbl">{ __( 'Campaign', 'gratora-donation-platform' ) }</span>
                        <span className="dd-rail-stat__val" style={ { fontSize: 12.5 } }>
                            <a href={ campaignHref( donation.campaign.id ) }>{ donation.campaign.title }</a>
                        </span>
                    </div>
                ) }
                { donation.paid_at && (
                    <div className="dd-rail-stat">
                        <span className="dd-rail-stat__lbl">{ __( 'Captured', 'gratora-donation-platform' ) }</span>
                        <span className="dd-rail-stat__val">{ formatDate( donation.paid_at ) }</span>
                    </div>
                ) }
            </div>
        </div>
    );
}
