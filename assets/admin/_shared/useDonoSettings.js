/**
 * Per-group settings hook over /dono/v1/admin/settings/{group}.
 *
 * edit()    deep-merges into pending (good for nested keys).
 * replace() atomically overwrites a top-level key, discarding prior merge edits
 *           on that key (needed when picking a preset must discard sparse overrides).
 */

import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

export function useDonoSettings( group ) {
    const [ saved, setSaved ]               = useState( null );
    const [ edits, setEdits ]               = useState( {} );
    const [ replacements, setReplacements ] = useState( {} );
    const [ saving, setSaving ]             = useState( false );

    useEffect( () => {
        let aborted = false;
        apiFetch( { path: `/dono/v1/admin/settings/${ group }` } )
            .then( ( data ) => { if ( ! aborted ) setSaved( data || {} ); } )
            .catch( () => {} );
        return () => { aborted = true; };
    }, [ group ] );

    const record = useMemo(
        () => ( { ...deepMerge( saved || {}, edits ), ...replacements } ),
        [ saved, edits, replacements ]
    );

    const isDirty = useMemo(
        () => Object.keys( edits ).length > 0 || Object.keys( replacements ).length > 0,
        [ edits, replacements ]
    );

    const edit = useCallback( ( patch ) => {
        setEdits( ( prev ) => deepMerge( prev, patch ) );
        // merge edit wins; drop any atomic replacement on the same keys.
        setReplacements( ( prev ) => omitKeys( prev, Object.keys( patch ) ) );
    }, [] );

    const replace = useCallback( ( patch ) => {
        setReplacements( ( prev ) => ( { ...prev, ...patch } ) );
        // atomic replace wins; drop merge-style edits on the same keys.
        setEdits( ( prev ) => omitKeys( prev, Object.keys( patch ) ) );
    }, [] );

    const discard = useCallback( () => {
        setEdits( {} );
        setReplacements( {} );
    }, [] );

    const save = useCallback( async () => {
        setSaving( true );
        try {
            const updated = await apiFetch( {
                path:   `/dono/v1/admin/settings/${ group }`,
                method: 'PUT',
                data:   record,
            } );
            setSaved( updated );
            setEdits( {} );
            setReplacements( {} );
            return updated;
        } finally {
            setSaving( false );
        }
    }, [ group, record ] );

    const value = useCallback(
        ( key, fallback = '' ) => {
            const v = pathGet( record, key );
            return v === undefined || v === null ? fallback : v;
        },
        [ record ]
    );

    const setValue = useCallback(
        ( key ) => ( v ) => edit( pathSet( {}, key, v ) ),
        [ edit ]
    );

    return {
        record,
        savedRecord: saved || {},
        edit,
        replace,
        discard,
        save,
        value,
        setValue,
        isDirty,
        isSaving:  saving,
        isLoading: saved === null,
        bind: ( key, fallback = '' ) => ( {
            value:    value( key, fallback ),
            onChange: ( e ) => edit( pathSet( {}, key, e.target.value ) ),
        } ),
        bindNumber: ( key ) => ( {
            value: value( key, '' ),
            onChange: ( e ) =>
                edit( pathSet( {}, key, e.target.value === '' ? null : Number( e.target.value ) ) ),
        } ),
        bindCheckbox: ( key ) => ( {
            checked:  !! value( key, false ),
            onChange: ( e ) => edit( pathSet( {}, key, e.target.checked ) ),
        } ),
    };
}

// helpers

function deepMerge( a, b ) {
    if ( ! isPlainObject( a ) ) a = {};
    if ( ! isPlainObject( b ) ) return b ?? a;
    const out = { ...a };
    for ( const k of Object.keys( b ) ) {
        out[ k ] = isPlainObject( b[ k ] ) && isPlainObject( a[ k ] )
            ? deepMerge( a[ k ], b[ k ] )
            : b[ k ];
    }
    return out;
}

function pathGet( obj, path ) {
    const parts = path.split( '.' );
    let cur = obj;
    for ( const p of parts ) {
        if ( cur === null || cur === undefined ) return undefined;
        cur = cur[ p ];
    }
    return cur;
}

function pathSet( obj, path, val ) {
    const parts = path.split( '.' );
    if ( parts.length === 1 ) return { ...obj, [ parts[ 0 ] ]: val };
    const [ head, ...rest ] = parts;
    return { ...obj, [ head ]: pathSet( obj[ head ] || {}, rest.join( '.' ), val ) };
}

function isPlainObject( v ) {
    return v !== null && typeof v === 'object' && ! Array.isArray( v );
}

function omitKeys( obj, keys ) {
    if ( ! keys.length ) return obj;
    const out = { ...obj };
    for ( const k of keys ) delete out[ k ];
    return out;
}
