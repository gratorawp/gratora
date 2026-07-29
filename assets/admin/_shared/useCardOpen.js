import { useCallback, useEffect, useState, useSyncExternalStore } from '@wordpress/element';

const EVENT = 'dono:accordion:changed';

function registry() {
    return ( typeof window !== 'undefined' && window.dono && window.dono.accordion ) || null;
}

function subscribe( onChange ) {
    window.addEventListener( EVENT, onChange );
    return () => window.removeEventListener( EVENT, onChange );
}

/**
 * Open state for a collapsible Card that knows when it wants attention.
 *
 * Follows `needsAttention` until the operator clicks the head, after which
 * their choice sticks. Status arrives async, so a plain defaultOpen would be
 * read before the card knows whether anything is wrong.
 *
 * Pass `group` and `id` and the card joins an accordion: opening it closes
 * whichever card in that group was open, including cards an add-on renders in
 * a separate React root. Several cards can want attention at once, so the
 * first to ask claims the group and the rest stay shut until clicked.
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
