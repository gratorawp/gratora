/**
 * WAI-ARIA tablist keyboard support. Wire onKeyDown on the [role="tablist"]
 * container and give each [role="tab"] a roving tabIndex (0 for the active tab,
 * -1 for the rest). Left/Right (and Home/End) then move between tabs, activating
 * and focusing the target.
 */
export function tablistKeyDown( e, ids, activeId, onChange ) {
    const delta = { ArrowLeft: -1, ArrowRight: 1 };
    let idx = ids.indexOf( activeId );
    if ( idx < 0 ) return;
    if ( e.key === 'Home' ) idx = 0;
    else if ( e.key === 'End' ) idx = ids.length - 1;
    else if ( delta[ e.key ] !== undefined ) idx = ( idx + delta[ e.key ] + ids.length ) % ids.length;
    else return;
    e.preventDefault();
    onChange( ids[ idx ] );
    const tabs = e.currentTarget.querySelectorAll( '[role="tab"]' );
    tabs[ idx ]?.focus();
}
