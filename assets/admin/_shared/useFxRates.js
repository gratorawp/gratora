/**
 * Exchange-rate state for the Currency panel, shaped like useDonoSettings so
 * Settings.jsx folds it into the single Save bar. Backed by
 * /dono/v1/admin/currency/fx (GET state, PUT auto+manual, POST /fetch).
 */

import { useState, useEffect, useCallback, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import { notify } from './notify';

const PATH = '/dono/v1/admin/currency/fx';

export function useFxRates() {
    const [ server, setServer ]           = useState( null );
    const [ autoEdit, setAutoEdit ]       = useState( null );   // null = unchanged
    const [ manualEdits, setManualEdits ] = useState( {} );     // code -> number | null(clear)
    const [ loading, setLoading ]         = useState( true );
    const [ isSaving, setSaving ]         = useState( false );
    const [ fetching, setFetching ]       = useState( false );

    const load = useCallback( async () => {
        try {
            setServer( await apiFetch( { path: PATH } ) );
        } catch ( e ) {
            // Leave server null; panel shows a quiet unavailable state.
        } finally {
            setLoading( false );
        }
    }, [] );

    useEffect( () => { load(); }, [ load ] );

    const auto = autoEdit !== null ? autoEdit : !! server?.auto;

    const rows = useMemo( () => ( server?.rows || [] ).map( ( row ) => {
        if ( Object.prototype.hasOwnProperty.call( manualEdits, row.code ) ) {
            const v = manualEdits[ row.code ];
            return v === null
                ? { ...row, rate: row.auto_rate, is_manual: false }
                : { ...row, rate: v, is_manual: true };
        }
        return row;
    } ), [ server, manualEdits ] );

    const isDirty = autoEdit !== null || Object.keys( manualEdits ).length > 0;

    const setAuto     = useCallback( ( v ) => setAutoEdit( v ), [] );
    const setManual   = useCallback( ( code, n ) => setManualEdits( ( m ) => ( { ...m, [ code ]: n } ) ), [] );
    const resetManual = useCallback( ( code ) => setManualEdits( ( m ) => ( { ...m, [ code ]: null } ) ), [] );
    const discard     = useCallback( () => { setAutoEdit( null ); setManualEdits( {} ); }, [] );

    const composeManual = useCallback( () => {
        const manual = {};
        ( server?.rows || [] ).forEach( ( r ) => { if ( r.is_manual ) manual[ r.code ] = r.rate; } );
        Object.entries( manualEdits ).forEach( ( [ code, v ] ) => {
            if ( v === null ) { delete manual[ code ]; } else { manual[ code ] = v; }
        } );
        return manual;
    }, [ server, manualEdits ] );

    const save = useCallback( async () => {
        setSaving( true );
        try {
            const updated = await apiFetch( {
                path: PATH, method: 'PUT',
                data: { auto, manual: composeManual() },
            } );
            setServer( updated );
            setAutoEdit( null );
            setManualEdits( {} );
            return updated;
        } finally {
            setSaving( false );
        }
    }, [ auto, composeManual ] );

    const fetchNow = useCallback( async () => {
        setFetching( true );
        try {
            const updated = await apiFetch( { path: `${ PATH }/fetch`, method: 'POST' } );
            // Refresh the underlying auto-rates but keep any unsaved manual
            // edits and auto-toggle change; a fetch must not discard them.
            setServer( updated );
            // A 200 can still report a failed provider fetch in the body; don't
            // claim success when the rates did not actually refresh.
            if ( updated?.fetch_ok === false ) {
                notify.error( __( 'Could not fetch exchange rates. Please try again.', 'dono' ) );
            } else {
                notify.success( __( 'Exchange rates updated.', 'dono' ) );
            }
            return updated;
        } catch ( err ) {
            notify.error( err?.message || __( 'Could not fetch exchange rates. Please try again.', 'dono' ) );
            return null;
        } finally {
            setFetching( false );
        }
    }, [] );

    return {
        base:      server?.base || '',
        source:    server?.source || '',
        stale:     !! server?.stale,
        fetchedAt: server?.fetched_at || null,
        date:      server?.date || null,
        // Supported currencies with no rate to the base. Donations in them are
        // accepted in full but count as zero in every base-currency total, so
        // the panel warns before the currency is offered.
        unconvertible: server?.unconvertible || [],
        rows,
        auto,
        loading,
        isSaving,
        fetching,
        isDirty,
        setAuto,
        setManual,
        resetManual,
        save,
        discard,
        fetchNow,
    };
}
