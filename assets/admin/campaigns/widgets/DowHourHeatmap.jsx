import { useMemo, useState } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';

// 7x24 day-of-week / hour-of-day donation-count heatmap. CSS Grid, no chart lib.
// 2001-01-01 was a Monday, which is the order the grid arrives in. Built with
// the same formatter the rest of the admin dates use, so the row labels, the
// tooltip and every cell's accessible name read in one language.
function dayLabels() {
    return Array.from( { length: 7 }, ( _, i ) => {
        const d = new Date( Date.UTC( 2001, 0, 1 + i ) );

        return {
            short: d.toLocaleDateString( undefined, { weekday: 'short', timeZone: 'UTC' } ),
            long:  d.toLocaleDateString( undefined, { weekday: 'long',  timeZone: 'UTC' } ),
        };
    } );
}

const HOUR_LABELS = [ 0, 6, 12, 18 ];

// The violet the distribution histogram fills its bars with, so the two
// charts on the overview read as one scale rather than two palettes.
const RAMP = '138, 123, 255';

export default function DowHourHeatmap( { data } ) {
    const [ hovered, setHovered ] = useState( null ); // { day, hour, count } | null

    if ( ! data || ( data.total ?? 0 ) === 0 ) {
        return (
            <p className="fundkit-panel__empty">
                { __( 'No donation activity yet to plot timing.', 'fundraising-toolkit' ) }
            </p>
        );
    }

    const { grid, max } = data;
    const days = useMemo( dayLabels, [] );

    let peak = { day: -1, hour: -1, count: 0 };
    grid.forEach( ( row, day ) => {
        row.forEach( ( count, hour ) => {
            if ( count > peak.count ) peak = { day, hour, count };
        } );
    } );

    return (
        <div className="fundkit-heatmap">
            <div className="fundkit-heatmap__hours">
                <span className="fundkit-heatmap__row-label" />
                { Array.from( { length: 24 }, ( _, h ) => (
                    <span
                        key={ h }
                        className={ `fundkit-heatmap__hour-tick${ HOUR_LABELS.includes( h ) ? ' is-labelled' : '' }` }
                        aria-hidden="true"
                    >
                        { HOUR_LABELS.includes( h ) ? h : '' }
                    </span>
                ) ) }
            </div>

            { grid.map( ( row, day ) => (
                <div key={ day } className="fundkit-heatmap__row">
                    <span className="fundkit-heatmap__row-label">{ days[ day ].short }</span>
                    { row.map( ( count, hour ) => {
                        const intensity = max > 0 ? count / max : 0;
                        const isPeak = peak.day === day && peak.hour === hour && count > 0;
                        return (
                            <button
                                key={ hour }
                                type="button"
                                className={ `fundkit-heatmap__cell${ isPeak ? ' is-peak' : '' }` }
                                style={ {
                                    background: count > 0
                                        ? `rgba(${ RAMP }, ${ 0.15 + intensity * 0.75 })`
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
                                        'fundraising-toolkit'
                                    ),
                                    days[ day ].long,
                                    hour,
                                    count
                                ) }
                            />
                        );
                    } ) }
                </div>
            ) ) }

            <div className="fundkit-heatmap__legend">
                <span className="fundkit-heatmap__legend-label">{ __( 'Fewer', 'fundraising-toolkit' ) }</span>
                { [ 0.15, 0.35, 0.55, 0.75, 0.9 ].map( ( a ) => (
                    <span
                        key={ a }
                        className="fundkit-heatmap__legend-cell"
                        style={ { background: `rgba(${ RAMP }, ${ a })` } }
                    />
                ) ) }
                <span className="fundkit-heatmap__legend-label">{ __( 'More', 'fundraising-toolkit' ) }</span>
            </div>

            { hovered && (
                <div className="fundkit-heatmap__tip" aria-live="polite">
                    <strong>
                        { days[ hovered.day ].long } · { hovered.hour.toString().padStart( 2, '0' ) }:00
                    </strong>
                    { ' - ' }
                    { sprintf(
                        /* translators: %d: number of donations */
                        _n( '%d donation', '%d donations', hovered.count, 'fundraising-toolkit' ),
                        hovered.count
                    ) }
                </div>
            ) }
        </div>
    );
}
