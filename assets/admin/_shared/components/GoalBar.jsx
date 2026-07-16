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
