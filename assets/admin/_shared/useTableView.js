/**
 * Remembers how someone likes to look at a list: which columns, in what order,
 * sorted how, how many rows, and which filters they are working under. Saved
 * per user on the server rather than per browser, so it follows them between
 * machines the way the widget layout does.
 *
 * Not remembered: the search box and the page number. A search is how someone
 * finds one record, not how they like to look at the list.
 */

import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

const PATH = '/fundkit/v1/admin/me/table-view';
const DEBOUNCE_MS = 600;

const isPlainObject = ( v ) => !! v && typeof v === 'object' && ! Array.isArray( v );

/**
 * Which columns to show, given what the user last chose and what the screen
 * offers now.
 *
 * A saved view names the columns that existed when it was saved, so a column
 * added in a later release is one the user has never had an opinion about: it
 * appears. A column missing from `fields` but present in `known` is one they
 * hid on purpose, and it stays hidden.
 */
function mergeFields( saved, known ) {
    if ( ! Array.isArray( saved?.fields ) ) {
        return null;
    }

    const kept  = saved.fields.filter( ( id ) => known.includes( id ) );
    const seen  = Array.isArray( saved.known ) ? saved.known : saved.fields;
    const added = known.filter( ( id ) => ! seen.includes( id ) );

    return [ ...kept, ...added ];
}

function merge( saved, defaults, known ) {
    if ( ! isPlainObject( saved ) ) {
        return defaults;
    }

    const next = { ...defaults, page: 1 };

    const fields = mergeFields( saved, known );
    if ( fields ) {
        next.fields = fields;
    }

    if ( isPlainObject( saved.sort ) && saved.sort.field ) {
        next.sort = { field: saved.sort.field, direction: saved.sort.direction === 'asc' ? 'asc' : 'desc' };
    }

    if ( Number.isInteger( saved.perPage ) && saved.perPage > 0 ) {
        next.perPage = saved.perPage;
    }

    if ( typeof saved.type === 'string' && saved.type ) {
        next.type = saved.type;
    }

    // A filter the screen was opened with came from the link that got the user
    // here, so it describes what they asked to see right now and outranks what
    // they were looking at last time.
    if ( Array.isArray( saved.filters ) && ! defaults.filters?.length ) {
        next.filters = saved.filters;
    }

    return next;
}

// The screens key their data fetch on the view OBJECT, so handing back a new
// one that says the same thing costs a second request on every page load.
const same = ( a, b ) => JSON.stringify( a ) === JSON.stringify( b );

function toPayload( view, known ) {
    return {
        fields:  Array.isArray( view.fields ) ? view.fields : [],
        known,
        sort:    view.sort,
        perPage: view.perPage,
        type:    view.type,
        filters: Array.isArray( view.filters ) ? view.filters : [],
    };
}

/**
 * @param {string}   scope    Namespaces the saved view; one per list screen.
 * @param {Object}   defaults The view the screen uses before anything is saved.
 * @param {string[]} known    Every column id the screen defines, in its own order.
 */
export function useTableView( scope, defaults, known ) {
    const [ view, setView ] = useState( defaults );
    const [ loaded, setLoaded ] = useState( false );
    const timer = useRef( null );
    // Held in a ref so the debounced save never closes over a stale column set.
    const knownRef = useRef( known );
    knownRef.current = known;

    // Only ever read once the screen has rendered, which is what lets a caller
    // pass a getter over field definitions declared below this hook.
    const columns = () => {
        const k = typeof knownRef.current === 'function' ? knownRef.current() : knownRef.current;
        return Array.isArray( k ) ? k : [];
    };

    useEffect( () => {
        if ( ! scope ) {
            setLoaded( true );
            return undefined;
        }

        let aborted = false;

        apiFetch( { path: addQueryArgs( PATH, { scope } ) } )
            .then( ( saved ) => {
                if ( ! aborted ) {
                    setView( ( current ) => {
                        const next = merge( saved, current, columns() );
                        return same( next, current ) ? current : next;
                    } );
                }
            } )
            .catch( () => {} )
            .finally( () => {
                if ( ! aborted ) setLoaded( true );
            } );

        return () => { aborted = true; };
    }, [ scope ] );

    const persist = useCallback( ( next ) => {
        if ( ! scope ) return;
        clearTimeout( timer.current );
        timer.current = setTimeout( () => {
            apiFetch( {
                path:   addQueryArgs( PATH, { scope } ),
                method: 'PUT',
                data:   toPayload( next, columns() ),
            } ).catch( () => {} );
        }, DEBOUNCE_MS );
    }, [ scope ] );

    // Saving before the load lands would write the screen's defaults over what
    // the user has: the first change worth keeping is one they made themselves.
    const change = useCallback( ( next ) => {
        const resolved = typeof next === 'function' ? next( view ) : next;
        setView( resolved );
        if ( loaded ) {
            persist( resolved );
        }
    }, [ view, loaded, persist ] );

    useEffect( () => () => clearTimeout( timer.current ), [] );

    return [ view, change, loaded ];
}
