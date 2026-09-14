import { __, _n, sprintf } from '@wordpress/i18n';
import { formatAmount } from '../format';

/**
 * Progress against a goal, uncapped.
 *
 * A bar cannot overflow its track, so GoalBar clamps the fill. The number
 * beside it is a different question: clamped, a campaign seventy times past
 * its target reads the same as one that just reached it.
 */
export function goalPercent( current, target ) {
    const to = Number( target ) || 0;

    return to > 0 ? Math.max( 0, Math.round( ( Number( current ) || 0 ) / to * 100 ) ) : 0;
}

/** Measure goals in org base currency, independently of the row’s donation currency. */
export function GoalCell( { item } ) {
    const type     = item.goal_type || 'amount';
    const isAmount = type === 'amount';
    const target   = Number( ( isAmount ? item.goal_cents : item.goal_count ) || 0 );
    const current  = Number( ( isAmount
        ? item.raised_cents
        : ( type === 'donors' ? item.donors_count : item.donations_count )
    ) || 0 );
    const hasGoal  = target > 0;
    const pct      = hasGoal ? goalPercent( current, target ) : 0;

    const template = type === 'donors'
        ? /* translators: %s: number of donors */ _n( '%s donor', '%s donors', target, 'gratora-donation-platform' )
        : /* translators: %s: number of donations */ _n( '%s donation', '%s donations', target, 'gratora-donation-platform' );
    const label = ! hasGoal
        ? __( 'No goal', 'gratora-donation-platform' )
        : isAmount
            ? formatAmount( target )
            : sprintf( template, target.toLocaleString() );

    return (
        <GoalBar
            left={ label }
            right={ hasGoal ? `${ pct.toLocaleString() }%` : '-' }
            pct={ pct }
            muted={ ! hasGoal }
        />
    );
}

/**
 * Goal-progress cell for list tables. Presentational only: callers supply the
 * left/right labels so bar chrome + typography stay identical across tables;
 * pct is the 0-100 fill width, muted dims the labels.
 */
export default function GoalBar( { left, right, pct = 0, muted = false } ) {
    const width = Math.min( 100, Math.max( 0, pct ) );
    return (
        <div className="gratora-goalbar">
            <div className={ `gratora-goalbar__labels${ muted ? ' is-muted' : '' }` }>
                <span>{ left }</span>
                { right != null && <span className="gratora-goalbar__pct">{ right }</span> }
            </div>
            <div className="gratora-goalbar__track">
                <div className="gratora-goalbar__fill" style={ { width: `${ width }%` } } />
            </div>
        </div>
    );
}
