import { __, sprintf } from '@wordpress/i18n';

import { formatAmount, formatAmountCompact, formatDate } from './helpers';
import { IconCoin, IconHeart, IconActivity, IconRotate } from './icons';

function Sparkline( { values } ) {
    if ( ! values?.length ) return null;
    const max = values.reduce( ( m, v ) => Math.max( m, v ), 0 ) || 1;
    const w = 200;
    const h = 22;
    const step = w / Math.max( 1, values.length - 1 );
    const points = values.map( ( v, i ) => {
        const x = i * step;
        const y = h - 2 - ( v / max ) * ( h - 4 );
        return `${ i === 0 ? 'M' : 'L' }${ x.toFixed( 1 ) },${ y.toFixed( 1 ) }`;
    } ).join( ' ' );
    return (
        <svg className="dp-metric__spark" viewBox={ `0 0 ${ w } ${ h }` } preserveAspectRatio="none">
            <path d={ points } fill="none" stroke="#1e8a4e" strokeWidth="1.5" />
        </svg>
    );
}

function Card( { icon, label, value, sub, spark } ) {
    return (
        <div className="dp-metric">
            <div className="dp-metric__head">
                <span className="dp-metric__label">{ label }</span>
                <span className="dp-metric__icon">{ icon }</span>
            </div>
            <div className="dp-metric__value">{ value }</div>
            { spark && <Sparkline values={ spark } /> }
            { sub && <div className="dp-metric__sub">{ sub }</div> }
        </div>
    );
}

export default function LifetimeMetrics( { lifetime } ) {
    const {
        total_cents, count, avg_cents, largest_cents,
        one_time_count, recurring_count,
        mrr_cents, active_plan_count, next_payment_at, sparkline,
    } = lifetime;

    return (
        <div className="dp-metrics">
            <Card
                icon={ <IconCoin width="16" height="16" /> }
                label={ __( 'Lifetime given', 'dono' ) }
                value={ <span className="num">{ formatAmount( total_cents ) }</span> }
                spark={ sparkline }
                sub={ count > 0 ? sprintf( /* translators: %s: amount */ __( 'Largest donation %s', 'dono' ), formatAmountCompact( largest_cents ) ) : null }
            />
            <Card
                icon={ <IconHeart width="16" height="16" /> }
                label={ __( 'Donations', 'dono' ) }
                value={ <span className="num">{ count }</span> }
                sub={ count > 0
                    ? sprintf( /* translators: 1: one-time donation count, 2: recurring donation count */ __( '%1$d one-time, %2$d recurring', 'dono' ), one_time_count, recurring_count )
                    : __( 'No donations yet', 'dono' ) }
            />
            <Card
                icon={ <IconActivity width="16" height="16" /> }
                label={ __( 'Avg. donation', 'dono' ) }
                value={ <span className="num">{ formatAmount( avg_cents ) }</span> }
                sub={ count > 0 ? __( 'Per donation', 'dono' ) : null }
            />
            <Card
                icon={ <IconRotate width="16" height="16" /> }
                label={ __( 'Recurring MRR', 'dono' ) }
                value={ <span className="num">{ formatAmount( mrr_cents ) }<small> /mo</small></span> }
                sub={ active_plan_count > 0
                    ? sprintf( /* translators: 1: active plan count, 2: next payment date */ __( '%1$d active · next %2$s', 'dono' ), active_plan_count, formatDate( next_payment_at ) )
                    : __( 'No active plans', 'dono' ) }
            />
        </div>
    );
}
