/**
 * Per-user layout (order + hidden) for any widget grid in the admin.
 *
 * `scope` is an opaque key used to namespace the saved layout
 * (e.g. 'dashboard', 'campaign_5'). Anything in `allKeys` that the server
 * doesn't yet know about is appended to the end visible by default, so
 * adding new widgets later doesn't need a migration.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

const PATH = '/dono/v1/admin/me/layout';
const DEBOUNCE_MS = 500;

export function useDonoLayout( scope, allKeys ) {
    const [ order, setOrder ]   = useState( allKeys );
    const [ hidden, setHidden ] = useState( [] );
    const [ loaded, setLoaded ] = useState( false );
    const saveTimer             = useRef( null );

    useEffect( () => {
        if ( ! scope ) return;
        apiFetch( { path: addQueryArgs( PATH, { scope } ) } )
            .then( ( data ) => {
                const persistedOrder  = Array.isArray( data?.order )  ? data.order  : [];
                const persistedHidden = Array.isArray( data?.hidden ) ? data.hidden : [];
                const merged = [
                    ...persistedOrder.filter( ( k ) => allKeys.includes( k ) ),
                    ...allKeys.filter( ( k ) => ! persistedOrder.includes( k ) ),
                ];
                setOrder( merged );
                setHidden( persistedHidden.filter( ( k ) => allKeys.includes( k ) ) );
            } )
            .catch( () => {} )
            .finally( () => setLoaded( true ) );
    }, [ scope ] ); // eslint-disable-line react-hooks/exhaustive-deps

    const persist = useCallback( ( nextOrder, nextHidden ) => {
        if ( ! scope ) return;
        clearTimeout( saveTimer.current );
        saveTimer.current = setTimeout( () => {
            apiFetch( {
                path:   addQueryArgs( PATH, { scope } ),
                method: 'PUT',
                data:   { order: nextOrder, hidden: nextHidden },
            } ).catch( () => {} );
        }, DEBOUNCE_MS );
    }, [ scope ] );

    const moveTo = useCallback( ( from, to ) => {
        setOrder( ( prev ) => {
            const next = prev.slice();
            const [ removed ] = next.splice( from, 1 );
            next.splice( to, 0, removed );
            persist( next, hidden );
            return next;
        } );
    }, [ persist, hidden ] );

    const hide = useCallback( ( key ) => {
        setHidden( ( prev ) => {
            if ( prev.includes( key ) ) return prev;
            const next = [ ...prev, key ];
            persist( order, next );
            return next;
        } );
    }, [ persist, order ] );

    const unhide = useCallback( ( key ) => {
        setHidden( ( prev ) => {
            if ( ! prev.includes( key ) ) return prev;
            const next = prev.filter( ( k ) => k !== key );
            persist( order, next );
            return next;
        } );
    }, [ persist, order ] );

    const reset = useCallback( () => {
        setOrder( allKeys );
        setHidden( [] );
        persist( allKeys, [] );
    }, [ persist, allKeys ] );

    const visibleOrder = useMemo(
        () => order.filter( ( k ) => ! hidden.includes( k ) ),
        [ order, hidden ]
    );

    return { order, visibleOrder, hidden, loaded, moveTo, hide, unhide, reset };
}
