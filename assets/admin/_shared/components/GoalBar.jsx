/**
 * Goal-progress cell for list tables. Presentational only: the caller supplies
 * the left/right labels (campaigns show "target / pct", funds show "raised /
 * of goal") so the bar chrome + label typography stay identical across tables.
 *
 *   left:  node    // left-aligned label
 *   right: node    // right-aligned label (muted), optional
 *   pct:   number  // 0-100 fill width
 *   muted: boolean // dim the labels (e.g. when no goal is set)
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
