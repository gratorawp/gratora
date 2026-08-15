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

    // Every untouched override is re-sent on every save, so what they are
    // denominated in matters even when nobody has been near the rate column.
    const composeManual = useCallback( ( rows_ ) => {
        const manual = {};
        ( rows_ || [] ).forEach( ( r ) => { if ( r.is_manual ) manual[ r.code ] = r.rate; } );
        Object.entries( manualEdits ).forEach( ( [ code, v ] ) => {
            if ( v === null ) { delete manual[ code ]; } else { manual[ code ] = v; }
        } );
        return manual;
    }, [ manualEdits ] );

    const save = useCallback( async () => {
        setSaving( true );
        try {
            // A rate is units per 1 base, and the shared Save bar can carry a
            // base change in the same click. That restates the whole table, so
            // re-read rather than posting the pre-change numbers back over it.
            const current = await apiFetch( { path: PATH } );
            const moved   = !! server?.frame && !! current?.frame && server.frame !== current.frame;

            // A restated row can be re-sent as read. A number the admin typed
            // is in the base they typed it under, and there is no honest way to
            // carry that across: they meant one of the two currencies and the
            // form does not know which.
            if ( moved && Object.values( manualEdits ).some( ( v ) => v !== null ) ) {
                throw new Error( __( 'The base currency changed in this save, so the exchange rates you entered are in the currency you left. Reload the page and set them again.', 'dono-fundraising-platform' ) );
            }

            const updated = await apiFetch( {
                path: PATH, method: 'PUT',
                data: {
                    auto,
                    manual: composeManual( current?.rows ),
                    frame: current?.frame || '',
                },
            } );
            setServer( updated );
            setAutoEdit( null );
            setManualEdits( {} );
            return updated;
        } finally {
            setSaving( false );
        }
    }, [ auto, composeManual, manualEdits, server ] );

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
                notify.error( __( 'Could not fetch exchange rates. Please try again.', 'dono-fundraising-platform' ) );
            } else {
                notify.success( __( 'Exchange rates updated.', 'dono-fundraising-platform' ) );
            }
            return updated;
        } catch ( err ) {
            notify.error( err?.message || __( 'Could not fetch exchange rates. Please try again.', 'dono-fundraising-platform' ) );
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
        // Supported currencies no enabled gateway can charge. The form says so
        // at the payment step, which is late: by then the donor has picked one.
        no_gateway: server?.no_gateway || [],
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
