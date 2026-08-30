import { __ } from '@wordpress/i18n';
import { formatAmount, timeAgo } from '../../_shared/format';

export default function Stories( { rows = [] } ) {
    if ( rows.length === 0 ) {
        return (
            <p className="fundkit-panel__empty">
                { __( 'No donor notes yet. Donors can leave a message at checkout, and it will appear here.', 'fundkit-fundraising-campaigns' ) }
            </p>
        );
    }

    return (
        <div className="fundkit-stories">
            { rows.map( ( r ) => (
                <figure key={ r.id } className="fundkit-story">
                    <blockquote className="fundkit-story__quote">{ r.note }</blockquote>
                    <figcaption className="fundkit-story__meta">
                        <span className="fundkit-story__author">
                            { r.is_anonymous ? __( 'Anonymous donor', 'fundkit-fundraising-campaigns' ) : r.donor_name }
                        </span>
                        <span className="fundkit-story__sep" aria-hidden="true">·</span>
                        <span className="fundkit-story__amount">
                            { formatAmount( r.amount_cents, r.currency ) }
                        </span>
                        <span className="fundkit-story__sep" aria-hidden="true">·</span>
                        <span className="fundkit-story__when">{ timeAgo( r.paid_at ) }</span>
                    </figcaption>
                </figure>
            ) ) }
        </div>
    );
}
