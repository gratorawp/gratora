import { __, _n, sprintf } from '@wordpress/i18n';

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
            <path d={ points } fill="none" stroke="#6f5ce6" strokeWidth="1.5" />
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
        mrr_cents, mrr_unconverted, active_plan_count, plan_counts, next_payment_at, sparkline,
    } = lifetime;

    // Disclose missing FX conversions and distinguish paused/past-due plans from no
    // subscriptions.
    const dormant = [];
    if ( plan_counts?.past_due > 0 ) {
        dormant.push( sprintf(
            /* translators: %d: number of plans the gateway could not collect. */
            _n( '%d past due', '%d past due', plan_counts.past_due, 'fundraising-toolkit' ),
            plan_counts.past_due
        ) );
    }
    if ( plan_counts?.paused > 0 ) {
        dormant.push( sprintf(
            /* translators: %d: number of paused plans. */
            _n( '%d paused', '%d paused', plan_counts.paused, 'fundraising-toolkit' ),
            plan_counts.paused
        ) );
    }

    const activePart = active_plan_count > 0
        ? sprintf( /* translators: 1: active plan count, 2: next payment date */ __( '%1$d active · next %2$s', 'fundraising-toolkit' ), active_plan_count, formatDate( next_payment_at ) )
        : __( 'No active plans', 'fundraising-toolkit' );

    const mrrSub = mrr_unconverted > 0
        ? sprintf(
            /* translators: %d: number of plans with no exchange rate */
            _n(
                '%d plan has no exchange rate and is not counted',
                '%d plans have no exchange rate and are not counted',
                mrr_unconverted,
                'fundraising-toolkit'
            ),
            mrr_unconverted
        )
        : [ activePart, ...dormant ].join( ' · ' );

    return (
        <div className="dp-metrics">
            <Card
                icon={ <IconCoin width="16" height="16" /> }
                label={ __( 'Lifetime given', 'fundraising-toolkit' ) }
                value={ <span className="num">{ formatAmount( total_cents ) }</span> }
                spark={ sparkline }
                sub={ count > 0 ? sprintf( /* translators: %s: amount */ __( 'Largest donation %s', 'fundraising-toolkit' ), formatAmountCompact( largest_cents ) ) : null }
            />
            <Card
                icon={ <IconHeart width="16" height="16" /> }
                // Lifetime metrics count paid donations; the Donations tab includes pending and
                // failed rows.
                label={ __( 'Donations received', 'fundraising-toolkit' ) }
                value={ <span className="num">{ count }</span> }
                sub={ count > 0
                    ? sprintf( /* translators: 1: one-time donation count, 2: recurring donation count */ __( '%1$d one-time, %2$d recurring', 'fundraising-toolkit' ), one_time_count, recurring_count )
                    : __( 'No donations yet', 'fundraising-toolkit' ) }
            />
            <Card
                icon={ <IconActivity width="16" height="16" /> }
                label={ __( 'Avg. donation', 'fundraising-toolkit' ) }
                value={ <span className="num">{ formatAmount( avg_cents ) }</span> }
                sub={ count > 0 ? __( 'Per donation', 'fundraising-toolkit' ) : null }
            />
            <Card
                icon={ <IconRotate width="16" height="16" /> }
                label={ __( 'Recurring MRR', 'fundraising-toolkit' ) }
                value={ <span className="num">{ formatAmount( mrr_cents ) }<small> /mo</small></span> }
                sub={ mrrSub }
            />
        </div>
    );
}
