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
        mrr_cents, mrr_unconverted, active_plan_count, plan_counts, next_payment_at, sparkline,
    } = lifetime;

    // A plan in a currency the site has no rate for counts as zero, so the
    // figure is short rather than wrong. Say which, instead of showing a total
    // that quietly leaves a plan out.
    // A paused or past-due plan bills nothing, so the card is right to read
    // $0.00 -- but "No active plans" on its own says the donor has no
    // subscription at all, which is a different thing. Name the state instead.
    const dormant = [];
    if ( plan_counts?.past_due > 0 ) {
        dormant.push( sprintf(
            /* translators: %d: number of plans the gateway could not collect. */
            _n( '%d past due', '%d past due', plan_counts.past_due, 'dono-fundraising-platform' ),
            plan_counts.past_due
        ) );
    }
    if ( plan_counts?.paused > 0 ) {
        dormant.push( sprintf(
            /* translators: %d: number of paused plans. */
            _n( '%d paused', '%d paused', plan_counts.paused, 'dono-fundraising-platform' ),
            plan_counts.paused
        ) );
    }

    const activePart = active_plan_count > 0
        ? sprintf( /* translators: 1: active plan count, 2: next payment date */ __( '%1$d active · next %2$s', 'dono-fundraising-platform' ), active_plan_count, formatDate( next_payment_at ) )
        : __( 'No active plans', 'dono-fundraising-platform' );

    const mrrSub = mrr_unconverted > 0
        ? sprintf(
            /* translators: %d: number of plans with no exchange rate */
            _n(
                '%d plan has no exchange rate and is not counted',
                '%d plans have no exchange rate and are not counted',
                mrr_unconverted,
                'dono-fundraising-platform'
            ),
            mrr_unconverted
        )
        : [ activePart, ...dormant ].join( ' · ' );

    return (
        <div className="dp-metrics">
            <Card
                icon={ <IconCoin width="16" height="16" /> }
                label={ __( 'Lifetime given', 'dono-fundraising-platform' ) }
                value={ <span className="num">{ formatAmount( total_cents ) }</span> }
                spark={ sparkline }
                sub={ count > 0 ? sprintf( /* translators: %s: amount */ __( 'Largest donation %s', 'dono-fundraising-platform' ), formatAmountCompact( largest_cents ) ) : null }
            />
            <Card
                icon={ <IconHeart width="16" height="16" /> }
                label={ __( 'Donations', 'dono-fundraising-platform' ) }
                value={ <span className="num">{ count }</span> }
                // Says "paid" because it is the money count: it divides into
                // Lifetime given to make the average beside it. The tab badge
                // counts every row a donor has, so without this the two numbers
                // differ by a pending donation and read as a bug.
                sub={ count > 0
                    ? sprintf( /* translators: 1: one-time donation count, 2: recurring donation count */ __( '%1$d one-time, %2$d recurring · paid', 'dono-fundraising-platform' ), one_time_count, recurring_count )
                    : __( 'No donations yet', 'dono-fundraising-platform' ) }
            />
            <Card
                icon={ <IconActivity width="16" height="16" /> }
                label={ __( 'Avg. donation', 'dono-fundraising-platform' ) }
                value={ <span className="num">{ formatAmount( avg_cents ) }</span> }
                sub={ count > 0 ? __( 'Per donation', 'dono-fundraising-platform' ) : null }
            />
            <Card
                icon={ <IconRotate width="16" height="16" /> }
                label={ __( 'Recurring MRR', 'dono-fundraising-platform' ) }
                value={ <span className="num">{ formatAmount( mrr_cents ) }<small> /mo</small></span> }
                sub={ mrrSub }
            />
        </div>
    );
}
