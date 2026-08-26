import { useState } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';

// 7x24 day-of-week / hour-of-day donation-count heatmap. CSS Grid, no chart lib.
const DAYS = [
    { short: 'Mon', long: 'Monday' },
    { short: 'Tue', long: 'Tuesday' },
    { short: 'Wed', long: 'Wednesday' },
    { short: 'Thu', long: 'Thursday' },
    { short: 'Fri', long: 'Friday' },
    { short: 'Sat', long: 'Saturday' },
    { short: 'Sun', long: 'Sunday' },
];

const HOUR_LABELS = [ 0, 6, 12, 18 ];

export default function DowHourHeatmap( { data } ) {
    const [ hovered, setHovered ] = useState( null ); // { day, hour, count } | null

    if ( ! data || ( data.total ?? 0 ) === 0 ) {
        return (
            <p className="giveflow-panel__empty">
                { __( 'No donation activity yet to plot timing.', 'giveflow-fundraising-campaigns' ) }
            </p>
        );
    }

    const { grid, max } = data;

    let peak = { day: -1, hour: -1, count: 0 };
    grid.forEach( ( row, day ) => {
        row.forEach( ( count, hour ) => {
            if ( count > peak.count ) peak = { day, hour, count };
        } );
    } );

    return (
        <div className="giveflow-heatmap">
            <div className="giveflow-heatmap__hours">
                <span className="giveflow-heatmap__row-label" />
                { Array.from( { length: 24 }, ( _, h ) => (
                    <span
                        key={ h }
                        className={ `giveflow-heatmap__hour-tick${ HOUR_LABELS.includes( h ) ? ' is-labelled' : '' }` }
                        aria-hidden="true"
                    >
                        { HOUR_LABELS.includes( h ) ? h : '' }
                    </span>
                ) ) }
            </div>

            { grid.map( ( row, day ) => (
                <div key={ day } className="giveflow-heatmap__row">
                    <span className="giveflow-heatmap__row-label">{ DAYS[ day ].short }</span>
                    { row.map( ( count, hour ) => {
                        const intensity = max > 0 ? count / max : 0;
                        const isPeak = peak.day === day && peak.hour === hour && count > 0;
                        return (
                            <button
                                key={ hour }
                                type="button"
                                className={ `giveflow-heatmap__cell${ isPeak ? ' is-peak' : '' }` }
                                style={ {
                                    background: count > 0
                                        ? `rgba(30, 138, 78, ${ 0.15 + intensity * 0.75 })`
                                        : '#f8fafb',
                                } }
                                onMouseEnter={ () => setHovered( { day, hour, count } ) }
                                onMouseLeave={ () => setHovered( null ) }
                                onFocus={ () => setHovered( { day, hour, count } ) }
                                onBlur={ () => setHovered( null ) }
                                aria-label={ sprintf(
                                    /* translators: 1: day name, 2: hour, 3: donation count */
                                    _n(
                                        '%1$s at %2$d:00, %3$d donation',
                                        '%1$s at %2$d:00, %3$d donations',
                                        count,
                                        'giveflow-fundraising-campaigns'
                                    ),
                                    DAYS[ day ].long,
                                    hour,
                                    count
                                ) }
                            />
                        );
                    } ) }
                </div>
            ) ) }

            <div className="giveflow-heatmap__legend">
                <span className="giveflow-heatmap__legend-label">{ __( 'Fewer', 'giveflow-fundraising-campaigns' ) }</span>
                { [ 0.15, 0.35, 0.55, 0.75, 0.9 ].map( ( a ) => (
                    <span
                        key={ a }
                        className="giveflow-heatmap__legend-cell"
                        style={ { background: `rgba(30, 138, 78, ${ a })` } }
                    />
                ) ) }
                <span className="giveflow-heatmap__legend-label">{ __( 'More', 'giveflow-fundraising-campaigns' ) }</span>
            </div>

            { hovered && (
                <div className="giveflow-heatmap__tip" aria-live="polite">
                    <strong>
                        { DAYS[ hovered.day ].long } · { hovered.hour.toString().padStart( 2, '0' ) }:00
                    </strong>
                    { ' - ' }
                    { sprintf(
                        /* translators: %d: number of donations */
                        _n( '%d donation', '%d donations', hovered.count, 'giveflow-fundraising-campaigns' ),
                        hovered.count
                    ) }
                </div>
            ) }
        </div>
    );
}
