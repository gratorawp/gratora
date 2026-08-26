import { __ } from '@wordpress/i18n';
import { formatAmount, timeAgo } from '../../_shared/format';

export default function Stories( { rows = [] } ) {
    if ( rows.length === 0 ) {
        return (
            <p className="giveflow-panel__empty">
                { __( 'No donor notes yet. Donors can leave a message at checkout, and it will appear here.', 'giveflow-fundraising-campaigns' ) }
            </p>
        );
    }

    return (
        <div className="giveflow-stories">
            { rows.map( ( r ) => (
                <figure key={ r.id } className="giveflow-story">
                    <blockquote className="giveflow-story__quote">{ r.note }</blockquote>
                    <figcaption className="giveflow-story__meta">
                        <span className="giveflow-story__author">
                            { r.is_anonymous ? __( 'Anonymous donor', 'giveflow-fundraising-campaigns' ) : r.donor_name }
                        </span>
                        <span className="giveflow-story__sep" aria-hidden="true">·</span>
                        <span className="giveflow-story__amount">
                            { formatAmount( r.amount_cents, r.currency ) }
                        </span>
                        <span className="giveflow-story__sep" aria-hidden="true">·</span>
                        <span className="giveflow-story__when">{ timeAgo( r.paid_at ) }</span>
                    </figcaption>
                </figure>
            ) ) }
        </div>
    );
}
