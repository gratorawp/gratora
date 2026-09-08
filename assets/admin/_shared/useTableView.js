/**
 * Persist per-user list preferences across browsers, excluding search and page. Keep full
 * column order separately from visibility so hidden columns retain their positions.
 */

import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

const PATH = '/fundkit/v1/admin/me/table-view';
const DEBOUNCE_MS = 600;

const isPlainObject = ( v ) => !! v && typeof v === 'object' && ! Array.isArray( v );

// The screens key their data fetch on the view OBJECT, so handing back a new
// one that says the same thing costs a second request on every page load.
const same = ( a, b ) => JSON.stringify( a ) === JSON.stringify( b );

const sameMembers = ( a, b ) => a.length === b.length && a.every( ( id ) => b.includes( id ) );

/**
 * Order default-visible columns first, then hidden definitions; definition order alone can
 * rearrange untouched columns.
 */
function canonicalOrder( defaultFields, known ) {
	const base = ( Array.isArray( defaultFields ) ? defaultFields : [] ).filter( ( id ) => known.includes( id ) );

	return [ ...base, ...known.filter( ( id ) => ! base.includes( id ) ) ];
}

/**
 * The reader's arrangement, brought up to date with the columns the screen has
 * now: ones it no longer defines drop out, and ones it has gained are seated
 * next to where the screen would put them rather than at the end.
 */
function mergeOrder( savedOrder, known, canon ) {
	const kept = ( Array.isArray( savedOrder ) ? savedOrder : [] ).filter( ( id ) => known.includes( id ) );

	const out = kept.slice();

	canon.forEach( ( id ) => {
		if ( out.includes( id ) ) {
			return;
		}

		// Seat it after the last column the screen would have put it after.
		// Scanning for the first HIGHER-ranked column instead would assume the
		// arrangement is in canonical order, which is the one thing it is not:
		// a reader who dragged the date column to the front would then get
		// every new column landing at position zero.
		const rank = canon.indexOf( id );
		let at = 0;
		out.forEach( ( other, i ) => {
			if ( canon.indexOf( other ) < rank ) {
				at = i + 1;
			}
		} );

		out.splice( at, 0, id );
	} );

	return out;
}

/**
 * A move rewrites the visible sequence. Hidden columns keep their slots in the
 * arrangement, and the visible ones are dealt back into the slots they occupy.
 */
function applyVisibleSequence( order, visible ) {
	const queue = visible.slice();

	return order.map( ( id ) => ( visible.includes( id ) ? queue.shift() : id ) );
}

function merge( saved, defaults, known, canon ) {
	if ( ! isPlainObject( saved ) ) {
		return { view: defaults, order: canon };
	}

	const next  = { ...defaults, page: 1 };
	const order = mergeOrder( saved.order, known, canon );

	if ( Array.isArray( saved.fields ) ) {
		// A column the screen has gained since this view was saved is one the
		// reader has never had an opinion about, so it shows; one they hid is
		// absent from `fields` but present in `order`, and stays hidden.
		const seen    = Array.isArray( saved.order ) ? saved.order : saved.fields;
		const visible = [
			...saved.fields.filter( ( id ) => known.includes( id ) ),
			...known.filter( ( id ) => ! seen.includes( id ) ),
		];

		next.fields = order.filter( ( id ) => visible.includes( id ) );
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
		// A filter on a column the screen no longer has cannot be seen or
		// cleared: dataviews draws chips only for fields it still knows, while
		// the list goes on counting itself as filtered.
		next.filters = saved.filters.filter( ( f ) => known.includes( f?.field ) );
	}

	return { view: next, order };
}

function toPayload( view, order ) {
	return {
		fields:  Array.isArray( view.fields ) ? view.fields : [],
		order,
		sort:    view.sort,
		perPage: view.perPage,
		type:    view.type,
		filters: Array.isArray( view.filters ) ? view.filters : [],
	};
}

/**
 * @param {string}            scope    Namespaces the saved view; one per list screen.
 * @param {Object}            defaults The view the screen uses before anything is saved.
 * @param {string[]|Function} known    Every column id the screen defines, in its own
 *                                     order. Takes a getter too, so a screen can pass
 *                                     its own field definitions even though they are
 *                                     declared after this hook runs.
 */
export function useTableView( scope, defaults, known ) {
	const [ view, setView ] = useState( defaults );
	const [ loaded, setLoaded ] = useState( false );
	const timer = useRef( null );
	// Held in a ref so the debounced save never closes over a stale column set.
	const knownRef = useRef( known );
	knownRef.current = known;

	// The reader's arrangement of every column, visible or not. A ref because it
	// is read while deciding an update rather than during a render.
	const order = useRef( null );

	// The view a change is measured against. Kept in a ref as well as state so
	// two updates in the same tick compare against the first one's result
	// instead of both reading the same render's value.
	const latest = useRef( defaults );
	latest.current = view;

	// Only ever read once the screen has rendered, which is what lets a caller
	// pass a getter over field definitions declared below this hook.
	const columns = () => {
		const k = typeof knownRef.current === 'function' ? knownRef.current() : knownRef.current;
		return Array.isArray( k ) ? k : [];
	};

	const arrangement = () => order.current ?? canonicalOrder( defaults.fields, columns() );

	useEffect( () => {
		if ( ! scope ) {
			setLoaded( true );
			return undefined;
		}

		let aborted = false;

		apiFetch( { path: addQueryArgs( PATH, { scope } ) } )
			.then( ( saved ) => {
				if ( aborted ) return;
				setView( ( current ) => {
					const cols = columns();
					const merged = merge( saved, current, cols, canonicalOrder( defaults.fields, cols ) );
					order.current = merged.order;
					const decided = same( merged.view, current ) ? current : merged.view;
					latest.current = decided;
					return decided;
				} );
			} )
			.catch( () => {} )
			.finally( () => {
				if ( ! aborted ) setLoaded( true );
			} );

		return () => { aborted = true; };
	}, [ scope ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const persist = useCallback( ( next ) => {
		if ( ! scope ) return;
		clearTimeout( timer.current );
		timer.current = setTimeout( () => {
			apiFetch( {
				path:   addQueryArgs( PATH, { scope } ),
				method: 'PUT',
				data:   toPayload( next, arrangement() ),
			} ).catch( () => {} );
		}, DEBOUNCE_MS );
	}, [ scope ] ); // eslint-disable-line react-hooks/exhaustive-deps

	/**
	 * dataviews writes the whole visible list on every change, so what the
	 * reader did has to be read back off the transition. It has three moves:
	 * hiding drops an id, showing appends one, and moving re-orders the same
	 * ids. Only a move is a statement about arrangement; the other two are
	 * answered from the arrangement already held.
	 */
	const reconcile = ( current, resolved ) => {
		const before = current?.fields;
		const after  = resolved?.fields;

		if ( ! Array.isArray( before ) || ! Array.isArray( after ) ) {
			return resolved;
		}

		if ( sameMembers( before, after ) ) {
			if ( ! before.every( ( id, i ) => after[ i ] === id ) ) {
				order.current = applyVisibleSequence( arrangement(), after );
			}
			return resolved;
		}

		return { ...resolved, fields: arrangement().filter( ( id ) => after.includes( id ) ) };
	};

	// Saving before the load lands would write the screen's defaults over what
	// the user has: the first change worth keeping is one they made themselves.
	const change = useCallback( ( next ) => {
		const current  = latest.current;
		const resolved = typeof next === 'function' ? next( current ) : next;
		const decided  = reconcile( current, resolved );

		latest.current = decided;
		setView( decided );

		if ( loaded ) {
			persist( decided );
		}
	}, [ loaded, persist ] ); // eslint-disable-line react-hooks/exhaustive-deps

	useEffect( () => () => clearTimeout( timer.current ), [] );

	return [ view, change, loaded ];
}
