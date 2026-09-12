import { __, sprintf, _n } from '@wordpress/i18n';
import { TrendingUp } from 'lucide-react';

import EmptyState from '../../_shared/components/EmptyState';
import { formatAmount, detailHref } from '../../_shared/format';

function Sparkline( { points = [], color = '#6f5ce6' } ) {
    if ( ! points || points.length === 0 ) return null;
    const w = 80, h = 22;
    const max = Math.max( 1, ...points.map( ( p ) => p.amount_cents ) );
    const stepX = points.length > 1 ? w / ( points.length - 1 ) : w;
    const path = points.map( ( p, i ) => {
        const x = i * stepX;
        const y = h - ( p.amount_cents / max ) * ( h - 2 ) - 1;
        return ( i === 0 ? 'M' : 'L' ) + x.toFixed( 1 ) + ',' + y.toFixed( 1 );
    } ).join( ' ' );

    return (
        <svg viewBox={ `0 0 ${ w } ${ h }` } width={ w } height={ h } aria-hidden="true" className="gratora-spark">
            <path d={ path } fill="none" stroke={ color } strokeWidth="1.5" />
        </svg>
    );
}

export default function TopCampaigns( { rows = [] } ) {
    if ( rows.length === 0 ) {
        return (
            <EmptyState
                compact
                icon={ <TrendingUp size={ 22 } strokeWidth={ 1.75 } /> }
                title={ __( 'No donations in this range', 'gratora-donation-platform' ) }
                body={ __( 'Pick a wider date range or wait for new donations to come in.', 'gratora-donation-platform' ) }
            />
        );
    }

    const max = rows[ 0 ].amount_cents;

    return (
        <table className="gratora-table gratora-top-campaigns">
            <tbody>
                { rows.map( ( c ) => {
                    const pct = max > 0 ? Math.round( ( c.amount_cents / max ) * 100 ) : 0;
                    return (
                        <tr key={ c.id }>
                            <td>
                                <div className="gratora-table__primary">
                                    <a href={ detailHref( c.id ) }>{ c.title }</a>
                                </div>
                                <div className="gratora-table__bar">
                                    <div className="gratora-table__bar-fill" style={ { width: `${ pct }%` } } />
                                </div>
                            </td>
                            <td className="gratora-top-campaigns__spark">
                                <Sparkline points={ c.sparkline } />
                            </td>
                            <td className="gratora-table__right">
                                <div className="gratora-table__primary">
                                    { formatAmount( c.amount_cents, c.currency ) }
                                </div>
                                <div className="gratora-table__sub">
                                    { sprintf(
                                        /* translators: %d: number of donations */ _n( '%d donation', '%d donations', c.donations_count, 'gratora-donation-platform' ),
                                        c.donations_count
                                    ) }
                                </div>
                            </td>
                        </tr>
                    );
                } ) }
            </tbody>
        </table>
    );
}
