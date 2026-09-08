import { useCallback, useEffect, useState, useSyncExternalStore } from '@wordpress/element';

const EVENT = 'fundkit:accordion:changed';

function registry() {
    return ( typeof window !== 'undefined' && window.fundkit && window.fundkit.accordion ) || null;
}

function subscribe( onChange ) {
    window.addEventListener( EVENT, onChange );
    return () => window.removeEventListener( EVENT, onChange );
}

/**
 * Follow needsAttention until user interaction. Group/id enables an accordion across React
 * roots; the first attention request claims the group.
 */
export default function useCardOpen( needsAttention, group = '', id = '' ) {
    const [ pinned, setPinned ] = useState( null );
    const grouped = !! group && !! id && !! registry();

    // The open card lives outside React because two roots share it, and this
    // is the hook for reading exactly that without tearing.
    const openId = useSyncExternalStore(
        grouped ? subscribe : () => () => {},
        () => ( grouped ? registry().current( group ) : null )
    );

    // Claiming is a side effect of wanting attention, not of rendering, so it
    // waits for the status that decided it to actually arrive.
    useEffect( () => {
        if ( grouped && needsAttention && pinned === null ) {
            registry().claim( group, id );
        }
    }, [ grouped, needsAttention, pinned, group, id ] );

    const setGrouped = useCallback( ( next ) => {
        setPinned( !! next );
        registry().set( group, next ? id : null );
    }, [ group, id ] );

    if ( ! grouped ) {
        return [ pinned === null ? !! needsAttention : pinned, setPinned ];
    }

    return [ openId === id, setGrouped ];
}
