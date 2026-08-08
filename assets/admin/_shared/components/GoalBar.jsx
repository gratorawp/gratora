import { __, _n, sprintf } from '@wordpress/i18n';
import { formatAmount } from '../format';

/**
 * Renders the goal cell from a row that carries goal_type/goal_cents/goal_count
 * plus its own totals. Campaigns and forms both keep their goal this shape, and
 * a row with no goal reads "No goal" rather than 0%.
 */
export function GoalCell( { item } ) {
    const type     = item.goal_type || 'amount';
    const isAmount = type === 'amount';
    const target   = Number( ( isAmount ? item.goal_cents : item.goal_count ) || 0 );
    const current  = Number( ( isAmount
        ? item.raised_cents
        : ( type === 'donors' ? item.donors_count : item.donations_count )
    ) || 0 );
    const hasGoal  = target > 0;
    const pct      = hasGoal ? Math.min( 100, Math.max( 0, Math.round( ( current / target ) * 100 ) ) ) : 0;

    const template = type === 'donors'
        ? /* translators: %s: donor count */ _n( '%s donor', '%s donors', target, 'dono' )
        : /* translators: %s: donation count */ _n( '%s donation', '%s donations', target, 'dono' );
    const label = ! hasGoal
        ? __( 'No goal', 'dono' )
        : isAmount
            ? formatAmount( target, item.currency )
            : sprintf( template, target.toLocaleString() );

    return (
        <GoalBar
            left={ label }
            right={ hasGoal ? `${ pct }%` : '-' }
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
        <div className="dono-goalbar">
            <div className={ `dono-goalbar__labels${ muted ? ' is-muted' : '' }` }>
                <span>{ left }</span>
                { right != null && <span className="dono-goalbar__pct">{ right }</span> }
            </div>
            <div className="dono-goalbar__track">
                <div className="dono-goalbar__fill" style={ { width: `${ width }%` } } />
            </div>
        </div>
    );
}
