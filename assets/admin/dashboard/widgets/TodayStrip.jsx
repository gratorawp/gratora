import { __, sprintf, _n } from '@wordpress/i18n';

import { formatAmount } from '../../_shared/format';

export default function TodayStrip( { today } ) {
    if ( ! today ) return null;

    const chips = [];

    if ( today.donations_count > 0 ) {
        chips.push( {
            key: 'donations',
            label: sprintf(
                /* translators: %d: number of donations */ _n( '%d donation', '%d donations', today.donations_count, 'giveflow-fundraising-campaigns' ),
                today.donations_count
            ),
        } );
        chips.push( {
            key: 'amount',
            label: formatAmount( today.amount_raised_cents, today.currency ),
            strong: true,
        } );
    }

    if ( today.notes_count > 0 ) {
        chips.push( {
            key: 'notes',
            label: sprintf(
                /* translators: %d: number of donations */ _n( '%d note', '%d notes', today.notes_count, 'giveflow-fundraising-campaigns' ),
                today.notes_count
            ),
        } );
    }

    if ( today.refunds_count > 0 ) {
        chips.push( {
            key: 'refunds',
            label: sprintf(
                /* translators: %d: number of donations */ _n( '%d refund', '%d refunds', today.refunds_count, 'giveflow-fundraising-campaigns' ),
                today.refunds_count
            ),
            tone: 'warn',
        } );
    }

    if ( chips.length === 0 ) {
        return (
            <p className="giveflow-today__empty">
                { __( 'Quiet so far today.', 'giveflow-fundraising-campaigns' ) }
            </p>
        );
    }

    return (
        <div className="giveflow-today">
            <span className="giveflow-today__pulse" aria-hidden="true" />
            <span className="giveflow-today__label">{ __( 'Last 24 hours', 'giveflow-fundraising-campaigns' ) }</span>
            <ul className="giveflow-today__chips">
                { chips.map( ( c ) => (
                    <li key={ c.key } className={ `giveflow-today__chip${ c.tone ? ' is-' + c.tone : '' }${ c.strong ? ' is-strong' : '' }` }>
                        { c.label }
                    </li>
                ) ) }
            </ul>
        </div>
    );
}
