import { __ } from '@wordpress/i18n';

import { Switch } from './Switch';
import DateField from './DateField';

/**
 * Show optional schedule fields behind a toggle. Use disabledNote to explain records that
 * cannot have schedules.
 */
export default function ScheduleFields( {
    enabled,
    onToggle,
    startsAt,
    onStartsAt,
    endsAt,
    onEndsAt,
    title       = __( 'Set a schedule', 'gratora' ),
    sub         = __( 'Add an optional start and end date', 'gratora' ),
    startLabel  = __( 'Start date', 'gratora' ),
    endLabel    = __( 'End date', 'gratora' ),
    startPlaceholder = __( 'Starts immediately', 'gratora' ),
    endPlaceholder   = __( 'No end date', 'gratora' ),
    disabled = false,
    disabledNote = '',
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
            <div className="gratora-sched__toggle-row">
                <div className="gratora-sched__toggle-txt">
                    <div className="gratora-sched__toggle-title">{ title }</div>
                    <div className="gratora-sched__toggle-sub">{ sub }</div>
                </div>
                <Switch checked={ enabled && ! disabled } onChange={ handleToggle } label={ title } disabled={ disabled } />
            </div>
            { disabled && disabledNote && (
                <p className="gratora-fld__help">{ disabledNote }</p>
            ) }
            { enabled && ! disabled && (
                <div className="gratora-sched__dates">
                    <div>
                        <span className="gratora-sched__date-lbl">{ startLabel }</span>
                        <DateField
                            value={ startsAt }
                            onChange={ onStartsAt }
                            placeholder={ startPlaceholder }
                            ariaLabel={ startLabel }
                        />
                    </div>
                    <div>
                        <span className="gratora-sched__date-lbl">{ endLabel }</span>
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
