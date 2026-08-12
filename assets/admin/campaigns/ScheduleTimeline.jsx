import { useMemo, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import DateField from '../_shared/components/DateField';

const MONTHS = [
    'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
    'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
];

const PAD = 22; // .dono-schedule__lane horizontal padding

// Jan-Dec lane with draggable start/today/end markers synced to date inputs below.
export default function ScheduleTimeline( { startsAt, endsAt, onChange, startEdited, endEdited } ) {
    const now      = useMemo( () => new Date(), [] );
    const laneRef  = useRef( null );

    const start = parseDate( startsAt );
    const end   = parseDate( endsAt );

    const displayYear = ( start && start.getFullYear() )
        || ( end && end.getFullYear() )
        || now.getFullYear();

    const yearStart = useMemo( () => new Date( displayYear, 0, 1 ).getTime(), [ displayYear ] );
    const yearEnd   = useMemo( () => new Date( displayYear, 11, 31, 23, 59, 59 ).getTime(), [ displayYear ] );

    const pct = ( d ) => {
        if ( ! d ) return null;
        const t = d.getTime();
        if ( t < yearStart || t > yearEnd ) return null;
        return ( ( t - yearStart ) / ( yearEnd - yearStart ) ) * 100;
    };

    const startPct = pct( start );
    const endPct   = pct( end );
    const todayPct = pct( now );

    const winLeft   = startPct ?? 0;
    const winRight  = endPct ?? 100;
    const hasWindow = startPct !== null || endPct !== null;

    const markerLeft = ( p ) => `calc(${ PAD }px + (100% - ${ PAD * 2 }px) * ${ p / 100 })`;

    // Drag handlers - date portion only; existing HH:MM:SS is preserved.
    const beginDrag = ( which ) => ( e ) => {
        e.preventDefault();
        const lane = laneRef.current;
        if ( ! lane ) return;

        const update = ( ev ) => {
            const rect = lane.getBoundingClientRect();
            const x    = ev.clientX - rect.left - PAD;
            const inner = rect.width - PAD * 2;
            const ratio = Math.max( 0, Math.min( 1, inner > 0 ? x / inner : 0 ) );

            const target = new Date( yearStart + ratio * ( yearEnd - yearStart ) );
            target.setHours( 0, 0, 0, 0 );

            const dateOnly = isoDate( target );
            const existing = which === 'start' ? startsAt : endsAt;
            const timeTail = existing && existing.length > 10 ? existing.slice( 10 ) : '';
            const next = dateOnly + timeTail;

            if ( which === 'start' ) {
                if ( endsAt && compareDateOnly( next, endsAt ) > 0 ) return;
                onChange?.( { starts_at: next } );
            } else {
                if ( startsAt && compareDateOnly( next, startsAt ) < 0 ) return;
                onChange?.( { ends_at: next } );
            }
        };

        const onMove = ( ev ) => update( ev );
        const onUp   = () => {
            window.removeEventListener( 'pointermove', onMove );
            window.removeEventListener( 'pointerup', onUp );
            window.removeEventListener( 'pointercancel', onUp );
            document.body.style.userSelect = '';
            document.body.style.cursor = '';
        };

        document.body.style.userSelect = 'none';
        document.body.style.cursor = 'ew-resize';
        window.addEventListener( 'pointermove', onMove );
        window.addEventListener( 'pointerup', onUp );
        window.addEventListener( 'pointercancel', onUp );
    };

    const setStart = ( next ) => onChange?.( { starts_at: next || null } );
    const setEnd   = ( next ) => onChange?.( { ends_at:   next || null } );

    return (
        <div className="dono-schedule">
            <div className="dono-schedule__lane" ref={ laneRef }>
                <div className="dono-schedule__track" />

                { hasWindow && (
                    <div
                        className="dono-schedule__window"
                        style={ {
                            left:  `calc(${ PAD }px + (100% - ${ PAD * 2 }px) * ${ winLeft  / 100 })`,
                            right: `calc(${ PAD }px + (100% - ${ PAD * 2 }px) * ${ ( 100 - winRight ) / 100 })`,
                        } }
                    />
                ) }

                { todayPct !== null && (
                    <div
                        className="dono-schedule__today"
                        style={ { left: markerLeft( todayPct ) } }
                    />
                ) }

                { startPct !== null && (
                    <>
                        <div
                            className="dono-schedule__marker"
                            style={ { left: markerLeft( startPct ), cursor: 'ew-resize' } }
                            onPointerDown={ beginDrag( 'start' ) }
                            role="slider"
                            aria-label={ __( 'Drag to change start date', 'dono-fundraising-platform' ) }
                            aria-valuenow={ Math.round( startPct ) }
                            aria-valuemin={ 0 }
                            aria-valuemax={ 100 }
                        />
                        <div className="dono-schedule__label" style={ { left: markerLeft( startPct ) } }>
                            { shortDate( start ) }
                            <small>{ __( 'Start', 'dono-fundraising-platform' ) }</small>
                        </div>
                    </>
                ) }

                { endPct !== null && (
                    <>
                        <div
                            className="dono-schedule__marker"
                            style={ { left: markerLeft( endPct ), cursor: 'ew-resize' } }
                            onPointerDown={ beginDrag( 'end' ) }
                            role="slider"
                            aria-label={ __( 'Drag to change end date', 'dono-fundraising-platform' ) }
                            aria-valuenow={ Math.round( endPct ) }
                            aria-valuemin={ 0 }
                            aria-valuemax={ 100 }
                        />
                        <div className="dono-schedule__label" style={ { left: markerLeft( endPct ) } }>
                            { shortDate( end ) }
                            <small>{ __( 'End', 'dono-fundraising-platform' ) }</small>
                        </div>
                    </>
                ) }
            </div>

            <div className="dono-schedule__axis">
                { MONTHS.map( ( m ) => <span key={ m }>{ m }</span> ) }
            </div>

            <div className="dono-schedule__dates">
                <label>
                    <span>{ __( 'Starts at', 'dono-fundraising-platform' ) }</span>
                    <DateField
                        withTime
                        value={ startsAt || '' }
                        onChange={ setStart }
                        edited={ startEdited }
                        placeholder={ __( 'No start scheduled', 'dono-fundraising-platform' ) }
                        ariaLabel={ __( 'Pick a start date and time', 'dono-fundraising-platform' ) }
                    />
                </label>
                <label>
                    <span>{ __( 'Ends at', 'dono-fundraising-platform' ) }</span>
                    <DateField
                        withTime
                        value={ endsAt || '' }
                        onChange={ setEnd }
                        edited={ endEdited }
                        placeholder={ __( 'No end scheduled', 'dono-fundraising-platform' ) }
                        ariaLabel={ __( 'Pick an end date and time', 'dono-fundraising-platform' ) }
                    />
                </label>
            </div>
        </div>
    );
}

function parseDate( v ) {
    if ( ! v ) return null;
    const d = new Date( v );
    return Number.isFinite( d.getTime() ) ? d : null;
}

function shortDate( d ) {
    return d.toLocaleDateString( undefined, { month: 'short', day: 'numeric' } );
}

function isoDate( d ) {
    const yyyy = d.getFullYear();
    const mm   = String( d.getMonth() + 1 ).padStart( 2, '0' );
    const dd   = String( d.getDate() ).padStart( 2, '0' );
    return `${ yyyy }-${ mm }-${ dd }`;
}

function compareDateOnly( a, b ) {
    const da = ( a || '' ).slice( 0, 10 );
    const db = ( b || '' ).slice( 0, 10 );
    return da < db ? -1 : da > db ? 1 : 0;
}
