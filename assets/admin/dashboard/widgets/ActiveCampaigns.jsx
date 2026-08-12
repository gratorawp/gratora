import { __, sprintf } from '@wordpress/i18n';
import { Target } from 'lucide-react';

import EmptyState from '../../_shared/components/EmptyState';
import { formatAmount, timeAgo, detailHref, StatusBadge } from '../../_shared/format';

export default function ActiveCampaigns( { rows = [] } ) {
    if ( rows.length === 0 ) {
        return (
            <EmptyState
                compact
                icon={ <Target size={ 22 } strokeWidth={ 1.75 } /> }
                title={ __( 'No active campaigns', 'dono-fundraising-platform' ) }
                body={ __( 'Publish a campaign to see it appear here with goal progress.', 'dono-fundraising-platform' ) }
            />
        );
    }

    return (
        <div className="dono-active-campaigns">
            { rows.map( ( c ) => {
                const target  = c.goal_type === 'amount'
                    ? ( c.goal_cents ?? 0 )
                    : ( c.goal_count ?? 0 );
                const current = c.goal_type === 'amount'
                    ? c.raised_cents
                    : ( c.goal_type === 'donations' ? c.donations_count : c.donors_count );
                const pct = target > 0 ? Math.min( 100, Math.round( ( current / target ) * 100 ) ) : 0;

                const fmt = ( v ) => c.goal_type === 'amount'
                    ? formatAmount( v, c.currency )
                    : String( v );

                return (
                    <a key={ c.id } href={ detailHref( c.id ) } className="dono-active-campaigns__row">
                        <div className="dono-active-campaigns__head">
                            <span className="dono-active-campaigns__title">{ c.title }</span>
                            <StatusBadge status={ c.status } />
                        </div>
                        <div className="dono-active-campaigns__bar">
                            <div className="dono-active-campaigns__bar-fill" style={ { width: `${ pct }%` } } />
                        </div>
                        <div className="dono-active-campaigns__meta">
                            <span>
                                { target > 0 ? (
                                    sprintf(
                                        /* translators: 1: raised value, 2: target value, 3: percent */
                                        __( '%1$s of %2$s · %3$d%%', 'dono-fundraising-platform' ),
                                        fmt( current ), fmt( target ), pct
                                    )
                                ) : fmt( current ) }
                            </span>
                            { c.last_donation_at && (
                                <span className="dono-active-campaigns__when">
                                    { sprintf( /* translators: %s: relative time */ __( 'Last: %s', 'dono-fundraising-platform' ), timeAgo( c.last_donation_at ) ) }
                                </span>
                            ) }
                        </div>
                    </a>
                );
            } ) }
        </div>
    );
}
