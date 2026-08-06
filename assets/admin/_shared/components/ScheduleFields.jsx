import { __ } from '@wordpress/i18n';

import { Switch } from './Switch';
import DateField from './DateField';

/**
 * Optional start/end dates behind a toggle.
 *
 * Campaigns and funds both schedule the same way, and both default to "always
 * on" -- so the dates stay out of sight until someone asks for them, rather
 * than sitting empty in every form.
 */
export default function ScheduleFields( {
    enabled,
    onToggle,
    startsAt,
    onStartsAt,
    endsAt,
    onEndsAt,
    title       = __( 'Set a schedule', 'dono' ),
    sub         = __( 'Add an optional start and end date', 'dono' ),
    startLabel  = __( 'Start date', 'dono' ),
    endLabel    = __( 'End date', 'dono' ),
    startPlaceholder = __( 'Starts immediately', 'dono' ),
    endPlaceholder   = __( 'No end date', 'dono' ),
} ) {
    // Turning the schedule off clears the dates: otherwise a value picked and
    // then hidden is still submitted, and the form says "always on" while
    // saving a window.
    const handleToggle = ( on ) => {
        onToggle( on );
        if ( ! on ) {
            onStartsAt( '' );
            onEndsAt( '' );
        }
    };

    return (
        <>
            <div className="dono-sched__toggle-row">
                <div className="dono-sched__toggle-txt">
                    <div className="dono-sched__toggle-title">{ title }</div>
                    <div className="dono-sched__toggle-sub">{ sub }</div>
                </div>
                <Switch checked={ enabled } onChange={ handleToggle } label={ title } />
            </div>
            { enabled && (
                <div className="dono-sched__dates">
                    <div>
                        <span className="dono-sched__date-lbl">{ startLabel }</span>
                        <DateField
                            value={ startsAt }
                            onChange={ onStartsAt }
                            placeholder={ startPlaceholder }
                            ariaLabel={ startLabel }
                        />
                    </div>
                    <div>
                        <span className="dono-sched__date-lbl">{ endLabel }</span>
                        <DateField
                            value={ endsAt }
                            onChange={ onEndsAt }
                            placeholder={ endPlaceholder }
                            ariaLabel={ endLabel }
                        />
                    </div>
                </div>
            ) }
        </>
    );
}
