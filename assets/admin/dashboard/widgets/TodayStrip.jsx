import { __, sprintf, _n } from '@wordpress/i18n';

import { formatAmount } from '../../_shared/format';

export default function TodayStrip( { today } ) {
    if ( ! today ) return null;

    const chips = [];

    if ( today.donations_count > 0 ) {
        chips.push( {
            key: 'donations',
            label: sprintf(
                /* translators: %d: number of donations */ _n( '%d donation', '%d donations', today.donations_count, 'dono-fundraising-platform' ),
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
                /* translators: %d: number of donations */ _n( '%d note', '%d notes', today.notes_count, 'dono-fundraising-platform' ),
                today.notes_count
            ),
        } );
    }

    if ( today.refunds_count > 0 ) {
        chips.push( {
            key: 'refunds',
            label: sprintf(
                /* translators: %d: number of donations */ _n( '%d refund', '%d refunds', today.refunds_count, 'dono-fundraising-platform' ),
                today.refunds_count
            ),
            tone: 'warn',
        } );
    }

    if ( chips.length === 0 ) {
        return (
            <p className="dono-today__empty">
                { __( 'Quiet so far today.', 'dono-fundraising-platform' ) }
            </p>
        );
    }

    return (
        <div className="dono-today">
            <span className="dono-today__pulse" aria-hidden="true" />
            <span className="dono-today__label">{ __( 'Last 24 hours', 'dono-fundraising-platform' ) }</span>
            <ul className="dono-today__chips">
                { chips.map( ( c ) => (
                    <li key={ c.key } className={ `dono-today__chip${ c.tone ? ' is-' + c.tone : '' }${ c.strong ? ' is-strong' : '' }` }>
                        { c.label }
                    </li>
                ) ) }
            </ul>
        </div>
    );
}
