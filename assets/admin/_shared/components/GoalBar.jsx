import { __, _n, sprintf } from '@wordpress/i18n';
import { formatAmount } from '../format';

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
    const pct      = hasGoal ? Math.min( 100, Math.max( 0, Math.round( ( current / target ) * 100 ) ) ) : 0;

    const template = type === 'donors'
        ? /* translators: %s: number of donors */ _n( '%s donor', '%s donors', target, 'fundraising-toolkit' )
        : /* translators: %s: number of donations */ _n( '%s donation', '%s donations', target, 'fundraising-toolkit' );
    const label = ! hasGoal
        ? __( 'No goal', 'fundraising-toolkit' )
        : isAmount
            ? formatAmount( target )
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
        <div className="fundkit-goalbar">
            <div className={ `fundkit-goalbar__labels${ muted ? ' is-muted' : '' }` }>
                <span>{ left }</span>
                { right != null && <span className="fundkit-goalbar__pct">{ right }</span> }
            </div>
            <div className="fundkit-goalbar__track">
                <div className="fundkit-goalbar__fill" style={ { width: `${ width }%` } } />
            </div>
        </div>
    );
}
