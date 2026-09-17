import { __, _n, sprintf } from '@wordpress/i18n';

import { formatAmount, formatAmountCompact, formatDayMonth } from './helpers';
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

    // Disclose missing FX conversions and distinguish a plan that is not
    // collecting today from no subscription at all. Each status is named here
    // rather than read from the vocabulary, because the sentence wants a
    // lowercase noun phrase and a label is not one.
    const dormant = [];
    if ( plan_counts?.past_due > 0 ) {
        dormant.push( sprintf(
            /* translators: %d: number of plans the gateway could not collect. */
            _n( '%d past due', '%d past due', plan_counts.past_due, 'gratora-donation-platform' ),
            plan_counts.past_due
        ) );
    }
    if ( plan_counts?.pending > 0 ) {
        dormant.push( sprintf(
            /* translators: %d: number of plans the gateway has not started yet. */
            _n( '%d pending', '%d pending', plan_counts.pending, 'gratora-donation-platform' ),
            plan_counts.pending
        ) );
    }
    if ( plan_counts?.paused > 0 ) {
        dormant.push( sprintf(
            /* translators: %d: number of paused plans. */
            _n( '%d paused', '%d paused', plan_counts.paused, 'gratora-donation-platform' ),
            plan_counts.paused
        ) );
    }

    const activePart = active_plan_count > 0
        ? sprintf( /* translators: 1: active plan count, 2: next payment date */ __( '%1$d active · next %2$s', 'gratora-donation-platform' ), active_plan_count, formatDayMonth( next_payment_at ) )
        : __( 'No active plans', 'gratora-donation-platform' );

    const mrrSub = mrr_unconverted > 0
        ? sprintf(
            /* translators: %d: number of plans with no exchange rate */
            _n(
                '%d plan has no exchange rate and is not counted',
                '%d plans have no exchange rate and are not counted',
                mrr_unconverted,
                'gratora-donation-platform'
            ),
            mrr_unconverted
        )
        : [ activePart, ...dormant ].join( ' · ' );

    return (
        <div className="dp-metrics">
            <Card
                icon={ <IconCoin width="16" height="16" /> }
                label={ __( 'Lifetime given', 'gratora-donation-platform' ) }
                value={ <span className="num">{ formatAmount( total_cents ) }</span> }
                spark={ sparkline }
                sub={ count > 0 ? sprintf( /* translators: %s: amount */ __( 'Largest donation %s', 'gratora-donation-platform' ), formatAmountCompact( largest_cents ) ) : null }
            />
            <Card
                icon={ <IconHeart width="16" height="16" /> }
                // Lifetime metrics count paid donations; the Donations tab includes pending and
                // failed rows.
                label={ __( 'Donations received', 'gratora-donation-platform' ) }
                value={ <span className="num">{ count }</span> }
                sub={ count > 0
                    ? sprintf( /* translators: 1: one-time donation count, 2: recurring donation count */ __( '%1$d one-time, %2$d recurring', 'gratora-donation-platform' ), one_time_count, recurring_count )
                    : __( 'No donations yet', 'gratora-donation-platform' ) }
            />
            <Card
                icon={ <IconActivity width="16" height="16" /> }
                label={ __( 'Avg. donation', 'gratora-donation-platform' ) }
                value={ <span className="num">{ formatAmount( avg_cents ) }</span> }
                sub={ count > 0 ? __( 'Per donation', 'gratora-donation-platform' ) : null }
            />
            <Card
                icon={ <IconRotate width="16" height="16" /> }
                label={ __( 'Recurring MRR', 'gratora-donation-platform' ) }
                value={ <span className="num">{ formatAmount( mrr_cents ) }<small> /mo</small></span> }
                sub={ mrrSub }
            />
        </div>
    );
}
