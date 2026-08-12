import { __ } from '@wordpress/i18n';
import { formatAmount, timeAgo } from '../../_shared/format';

export default function Stories( { rows = [] } ) {
    if ( rows.length === 0 ) {
        return (
            <p className="dono-panel__empty">
                { __( 'No donor notes yet. Donors can leave a message at checkout, and it will appear here.', 'dono-fundraising-platform' ) }
            </p>
        );
    }

    return (
        <div className="dono-stories">
            { rows.map( ( r ) => (
                <figure key={ r.id } className="dono-story">
                    <blockquote className="dono-story__quote">{ r.note }</blockquote>
                    <figcaption className="dono-story__meta">
                        <span className="dono-story__author">
                            { r.is_anonymous ? __( 'Anonymous donor', 'dono-fundraising-platform' ) : r.donor_name }
                        </span>
                        <span className="dono-story__sep" aria-hidden="true">·</span>
                        <span className="dono-story__amount">
                            { formatAmount( r.amount_cents, r.currency ) }
                        </span>
                        <span className="dono-story__sep" aria-hidden="true">·</span>
                        <span className="dono-story__when">{ timeAgo( r.paid_at ) }</span>
                    </figcaption>
                </figure>
            ) ) }
        </div>
    );
}
