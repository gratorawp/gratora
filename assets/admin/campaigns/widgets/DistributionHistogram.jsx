import { useMemo } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';
import {
    BarChart, Bar, CartesianGrid, ReferenceLine, ResponsiveContainer,
    Tooltip, XAxis, YAxis,
} from 'recharts';

import { formatAmount, formatAmountCompact } from '../../_shared/format';

// Donation-size distribution histogram with a median reference line.
export default function DistributionHistogram( { distribution, currency } ) {
    // Hooks must run on every render: this widget mounts with distribution=null
    // (loading) then re-renders with data, so the useMemo has to sit above the
    // empty-guard or the hook count changes and React throws.
    const data = useMemo( () => ( distribution?.buckets ?? [] ).map( ( b ) => ( {
        label: labelFor( b, currency ),
        count: b.count,
        amount_cents: b.amount_cents,
        min: b.min_cents,
        max: b.max_cents,
    } ) ), [ distribution, currency ] );

    if ( ! distribution || distribution.total_count === 0 ) {
        return (
            <p className="dono-panel__empty">
                { __( 'Not enough donations yet to plot a distribution.', 'dono' ) }
            </p>
        );
    }

    const { median_cents, total_count } = distribution;
    const buckets = distribution.buckets;

    // Find the bucket the median falls into so we can draw a reference line.
    const medianBucketIndex = buckets.findIndex( ( b ) => {
        return median_cents >= b.min_cents && (b.max_cents === null || median_cents <= b.max_cents);
    } );

    const longTailCount = buckets
        .filter( ( b ) => b.min_cents >= 10001 )
        .reduce( ( s, b ) => s + b.count, 0 );

    return (
        <div className="dono-histogram">
            <div className="dono-histogram__caption">
                <span>
                    <strong>
                        { sprintf(
                            /* translators: %s: median donation amount */
                            __( 'Median: %s', 'dono' ),
                            formatAmount( median_cents, currency )
                        ) }
                    </strong>
                </span>
                { longTailCount > 0 && (
                    <span className="dono-histogram__tail">
                        { sprintf(
                            /* translators: 1: donation count, 2: amount threshold (e.g. $100) */
                            _n( '%1$d donation over %2$s', '%1$d donations over %2$s', longTailCount, 'dono' ),
                            longTailCount,
                            formatAmountCompact( 10000, currency )
                        ) }
                    </span>
                ) }
            </div>

            <ResponsiveContainer width="100%" height={ 220 }>
                <BarChart data={ data } margin={ { top: 12, right: 12, bottom: 0, left: 0 } }>
                    <CartesianGrid stroke="#eef0f2" strokeDasharray="2 4" vertical={ false } />
                    <XAxis
                        dataKey="label"
                        stroke="#9ca3af"
                        tickLine={ false }
                        axisLine={ false }
                        fontSize={ 11 }
                        interval={ 0 }
                    />
                    <YAxis
                        stroke="#9ca3af"
                        tickLine={ false }
                        axisLine={ false }
                        fontSize={ 11 }
                        width={ 40 }
                        allowDecimals={ false }
                    />
                    <Tooltip
                        cursor={ { fill: 'rgba(30,138,78,.06)' } }
                        contentStyle={ {
                            background:   '#111827',
                            border:       0,
                            borderRadius: 6,
                            color:        '#fff',
                            fontSize:     12,
                            padding:      '8px 10px',
                        } }
                        labelStyle={ { color: '#d1d5db', fontSize: 11, marginBottom: 2 } }
                        itemStyle={ { color: '#fff' } }
                        formatter={ ( value, _name, props ) => [
                            sprintf(
                                /* translators: 1: donation count, 2: total amount in that bucket */
                                __( '%1$d × %2$s', 'dono' ),
                                value,
                                formatAmount( props.payload.amount_cents, currency )
                            ),
                            __( 'Donations', 'dono' ),
                        ] }
                    />
                    <Bar dataKey="count" fill="#1e8a4e" radius={ [ 4, 4, 0, 0 ] } isAnimationActive={ false } />
                    { medianBucketIndex >= 0 && (
                        <ReferenceLine
                            x={ data[ medianBucketIndex ].label }
                            stroke="#6b7280"
                            strokeDasharray="4 4"
                            label={ {
                                value:     __( 'median', 'dono' ),
                                position:  'top',
                                fill:      '#6b7280',
                                fontSize:  10,
                            } }
                        />
                    ) }
                </BarChart>
            </ResponsiveContainer>

            <p className="dono-histogram__total">
                { sprintf(
                    /* translators: %d: total donation count */
                    _n( '%d donation in this period', '%d donations in this period', total_count, 'dono' ),
                    total_count
                ) }
            </p>
        </div>
    );
}

function labelFor( b, currency ) {
    if ( b.max_cents === null ) return `${ formatAmountCompact( b.min_cents, currency ) }+`;
    return `${ formatAmountCompact( b.min_cents, currency ) }-${ formatAmountCompact( b.max_cents, currency ) }`;
}
