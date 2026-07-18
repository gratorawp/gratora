import { __, sprintf, _n } from '@wordpress/i18n';
import { TrendingUp } from 'lucide-react';

import EmptyState from '../../_shared/components/EmptyState';
import { formatAmount, detailHref } from '../../_shared/format';

function Sparkline( { points = [], color = '#1e8a4e' } ) {
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
        <svg viewBox={ `0 0 ${ w } ${ h }` } width={ w } height={ h } aria-hidden="true" className="dono-spark">
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
                title={ __( 'No donations in this range', 'dono' ) }
                body={ __( 'Pick a wider date range or wait for new donations to come in.', 'dono' ) }
            />
        );
    }

    const max = rows[ 0 ].amount_cents;

    return (
        <table className="dono-table dono-top-campaigns">
            <tbody>
                { rows.map( ( c ) => {
                    const pct = max > 0 ? Math.round( ( c.amount_cents / max ) * 100 ) : 0;
                    return (
                        <tr key={ c.id }>
                            <td>
                                <div className="dono-table__primary">
                                    <a href={ detailHref( c.id ) }>{ c.title }</a>
                                </div>
                                <div className="dono-table__bar">
                                    <div className="dono-table__bar-fill" style={ { width: `${ pct }%` } } />
                                </div>
                            </td>
                            <td className="dono-top-campaigns__spark">
                                <Sparkline points={ c.sparkline } />
                            </td>
                            <td className="dono-table__right">
                                <div className="dono-table__primary">
                                    { formatAmount( c.amount_cents, c.currency ) }
                                </div>
                                <div className="dono-table__sub">
                                    { sprintf(
                                        /* translators: %d: donation count */ _n( '%d donation', '%d donations', c.donations_count, 'dono' ),
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
