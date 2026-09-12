import { __ } from '@wordpress/i18n';
import { formatAmount, timeAgo } from '../../_shared/format';

export default function Stories( { rows = [] } ) {
    if ( rows.length === 0 ) {
        return (
            <p className="gratora-panel__empty">
                { __( 'No donor notes yet. Donors can leave a message at checkout, and it will appear here.', 'gratora-donation-platform' ) }
            </p>
        );
    }

    return (
        <div className="gratora-stories">
            { rows.map( ( r ) => (
                <figure key={ r.id } className="gratora-story">
                    <blockquote className="gratora-story__quote">{ r.note }</blockquote>
                    <figcaption className="gratora-story__meta">
                        <span className="gratora-story__author">
                            { r.donor_name }
                        </span>
                        { !! r.is_anonymous && (
                            <span
                                className="gratora-story__anon"
                                title={ __( 'Their name is hidden from public donor lists. It still appears here.', 'gratora-donation-platform' ) }
                            >
                                { __( 'anonymous publicly', 'gratora-donation-platform' ) }
                            </span>
                        ) }
                        <span className="gratora-story__sep" aria-hidden="true">·</span>
                        <span className="gratora-story__amount">
                            { formatAmount( r.amount_cents, r.currency ) }
                        </span>
                        <span className="gratora-story__sep" aria-hidden="true">·</span>
                        <span className="gratora-story__when">{ timeAgo( r.paid_at ) }</span>
                    </figcaption>
                </figure>
            ) ) }
        </div>
    );
}
