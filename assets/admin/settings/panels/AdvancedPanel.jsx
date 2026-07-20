import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

import Card from '../../_shared/components/Card';
import Btn from '../../_shared/components/Btn';
import { ToggleRow } from '../../_shared/components/Switch';

const RECALC_SCOPES = [
    { value: 'all',       label: __( 'Everything', 'dono' ) },
    { value: 'donors',    label: __( 'Donors', 'dono' ) },
    { value: 'funds',     label: __( 'Funds', 'dono' ) },
    { value: 'campaigns', label: __( 'Campaigns', 'dono' ) },
    { value: 'forms',     label: __( 'Forms', 'dono' ) },
];

export default function AdvancedPanel( { s } ) {
    const [ info, setInfo ]           = useState( null );
    const [ infoError, setInfoError ] = useState( false );
    const [ notice,    setNotice ]    = useState( null );
    const [ exporting, setExporting ] = useState( false );
    const [ importing, setImporting ] = useState( false );
    const [ recalcScope, setRecalcScope ]   = useState( 'all' );
    const [ recalcRunning, setRecalcRunning ] = useState( false );
    const [ recalcResult, setRecalcResult ]   = useState( null );

    const loadInfo = () => {
        setInfoError( false );
        apiFetch( { path: '/dono/v1/admin/advanced/info' } )
            .then( setInfo )
            .catch( () => setInfoError( true ) );
    };

    useEffect( () => { loadInfo(); }, [] );

    const doExport = async () => {
        setExporting( true );
        setNotice( null );
        try {
            const data = await apiFetch( { path: '/dono/v1/admin/advanced/export' } );
            const blob = new Blob(
                [ JSON.stringify( data, null, 2 ) ],
                { type: 'application/json' }
            );
            const url  = URL.createObjectURL( blob );
            const a    = document.createElement( 'a' );
            const ts   = new Date().toISOString().replace( /[:.]/g, '-' ).slice( 0, 19 );
            a.href     = url;
            a.download = `dono-settings-${ ts }.json`;
            a.click();
            URL.revokeObjectURL( url );
            setNotice( { type: 'success', text: __( 'Settings exported.', 'dono' ) } );
        } catch ( err ) {
            setNotice( { type: 'error', text: err?.message || __( 'Export failed', 'dono' ) } );
        } finally {
            setExporting( false );
        }
    };

    const doImport = async ( file ) => {
        if ( ! file ) return;
        setImporting( true );
        setNotice( null );
        try {
            const text = await file.text();
            const parsed = JSON.parse( text );
            await apiFetch( {
                path:   '/dono/v1/admin/advanced/import',
                method: 'POST',
                data:   parsed,
            } );
            setNotice( { type: 'success', text: __( 'Settings imported. Reload the page to see them.', 'dono' ) } );
        } catch ( err ) {
            setNotice( { type: 'error', text: err?.message || __( 'Import failed. Make sure the file is a Dono settings export.', 'dono' ) } );
        } finally {
            setImporting( false );
        }
    };

    const doRecalculate = async () => {
        setRecalcRunning( true );
        setRecalcResult( null );
        setNotice( null );
        try {
            const res = await apiFetch( {
                path:   '/dono/v1/admin/advanced/recalculate',
                method: 'POST',
                data:   { scope: recalcScope },
            } );
            setRecalcResult( res?.counts || {} );
            setNotice( { type: 'success', text: __( 'Aggregates recomputed.', 'dono' ) } );
        } catch ( err ) {
            setNotice( { type: 'error', text: err?.message || __( 'Recalculation failed.', 'dono' ) } );
        } finally {
            setRecalcRunning( false );
        }
    };

    return (
        <div className="dono-panel">
            { notice && (
                <div className={ `dono-advanced-notice dono-advanced-notice--${ notice.type }` }>{ notice.text }</div>
            ) }

            <Card
                title={ __( 'System info', 'dono' ) }
                sub={ __( 'Useful when reporting an issue.', 'dono' ) }
            >
                { infoError ? (
                    <div style={ { display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' } }>
                        <p style={ { color: '#b42318', margin: 0 } }>{ __( 'Could not load system info.', 'dono' ) }</p>
                        <Btn variant="secondary" onClick={ loadInfo }>{ __( 'Retry', 'dono' ) }</Btn>
                    </div>
                ) : ! info ? <p>{ __( 'Loading…', 'dono' ) }</p> : (
                    <div className="dono-advanced-info">
                        <div><dt>{ __( 'Dono version', 'dono' ) }</dt><dd>{ info.version }</dd></div>
                        <div><dt>{ __( 'PHP version', 'dono' ) }</dt><dd>{ info.php }</dd></div>
                        <div><dt>{ __( 'WordPress', 'dono' ) }</dt><dd>{ info.wp }</dd></div>
                        <div><dt>{ __( 'Site URL', 'dono' ) }</dt><dd><code>{ info.site_url }</code></dd></div>
                        <div><dt>{ __( 'REST namespace', 'dono' ) }</dt><dd><code>{ info.rest_root }</code></dd></div>
                    </div>
                ) }
            </Card>

            <Card
                title={ __( 'Onboarding', 'dono' ) }
                sub={ __( 'Re-open the first-run wizard. Already-saved settings stay; the wizard reads from the live values.', 'dono' ) }
            >
                <div className="dono-advanced-actions">
                    <Btn variant="secondary" href={ 'admin.php?page=dono-onboarding' }>
                        { __( 'Open onboarding wizard', 'dono' ) }
                    </Btn>
                </div>
            </Card>

            <Card
                title={ __( 'Database tables', 'dono' ) }
                sub={ __( 'Tables Dono owns and current row counts.', 'dono' ) }
            >
                { info?.tables?.length ? (
                    <table className="dono-advanced-table">
                        <thead><tr><th>{ __( 'Table', 'dono' ) }</th><th>{ __( 'Rows', 'dono' ) }</th></tr></thead>
                        <tbody>
                            { info.tables.map( ( t ) => (
                                <tr key={ t.name }><td><code>{ t.name }</code></td><td>{ t.rows.toLocaleString() }</td></tr>
                            ) ) }
                        </tbody>
                    </table>
                ) : (
                    <p style={ { color: '#6b7280' } }>{ __( 'No Dono tables found. Deactivate and reactivate the plugin to create them.', 'dono' ) }</p>
                ) }
            </Card>

            <Card
                title={ __( 'Scheduled tasks', 'dono' ) }
                sub={ __( 'Dono cron events queued via WP-Cron.', 'dono' ) }
            >
                { info?.cron?.length ? (
                    <ul className="dono-advanced-cron">
                        { info.cron.map( ( c, i ) => (
                            <li key={ i }><code>{ c.hook }</code> <em>{ c.next }</em></li>
                        ) ) }
                    </ul>
                ) : (
                    <p style={ { color: '#6b7280' } }>{ __( 'No Dono cron events scheduled.', 'dono' ) }</p>
                ) }
            </Card>

            <Card
                title={ __( 'Recalculate aggregates', 'dono' ) }
                sub={ __( 'Re-derive donor / fund / campaign / form counters from the donation rows. Safe to run any time; donations are read-only.', 'dono' ) }
            >
                <div className="dono-advanced-actions" style={ { alignItems: 'flex-end' } }>
                    <label style={ { display: 'flex', flexDirection: 'column', gap: 4, fontSize: 13, color: '#6b7280' } }>
                        { __( 'Scope', 'dono' ) }
                        <select
                            className="dono-select"
                            value={ recalcScope }
                            onChange={ ( e ) => setRecalcScope( e.target.value ) }
                            disabled={ recalcRunning }
                            style={ { minWidth: 180 } }
                        >
                            { RECALC_SCOPES.map( ( sc ) => (
                                <option key={ sc.value } value={ sc.value }>{ sc.label }</option>
                            ) ) }
                        </select>
                    </label>
                    <Btn
                        variant="primary"
                        onClick={ doRecalculate }
                        disabled={ recalcRunning }
                        isBusy={ recalcRunning }
                    >
                        { recalcRunning ? __( 'Recalculating…', 'dono' ) : __( 'Recalculate', 'dono' ) }
                    </Btn>
                </div>
                { recalcResult && (
                    <ul className="dono-advanced-cron" style={ { marginTop: 12 } }>
                        { Object.entries( recalcResult )
                            .filter( ( [ , n ] ) => n > 0 )
                            .map( ( [ k, n ] ) => (
                                <li key={ k }>
                                    { sprintf(
                                        /* translators: 1: scope label (Donors, Funds, ...), 2: count */
                                        __( '%1$s: %2$d synced', 'dono' ),
                                        k.charAt( 0 ).toUpperCase() + k.slice( 1 ),
                                        n
                                    ) }
                                </li>
                            ) ) }
                    </ul>
                ) }
            </Card>

            <Card
                title={ __( 'Settings backup', 'dono' ) }
                sub={ __( 'Export every Dono settings group as a JSON file, or restore from a previous export. Donations and donor records are not part of this backup.', 'dono' ) }
            >
                <div className="dono-advanced-actions">
                    <Btn
                        variant="primary"
                        onClick={ doExport }
                        disabled={ exporting }
                        isBusy={ exporting }
                    >
                        { exporting ? __( 'Exporting…', 'dono' ) : __( 'Export settings', 'dono' ) }
                    </Btn>
                    <Btn
                        variant="secondary"
                        disabled={ importing }
                        isBusy={ importing }
                        onClick={ () => document.getElementById( 'dono-import-file' )?.click() }
                    >
                        { importing ? __( 'Importing…', 'dono' ) : __( 'Import settings', 'dono' ) }
                    </Btn>
                    <input
                        id="dono-import-file"
                        type="file"
                        accept="application/json,.json"
                        style={ { display: 'none' } }
                        disabled={ importing }
                        onChange={ ( e ) => doImport( e.target.files?.[ 0 ] ) }
                    />
                </div>
            </Card>

            <Card
                title={ __( 'Debug logging', 'dono' ) }
                sub={ __( 'Write extra detail to wp-content/debug.log when WP_DEBUG_LOG is on. Use during integration debugging; turn off in production.', 'dono' ) }
                edited={ s.isDirty }
            >
                <ToggleRow
                    title={ __( 'Verbose logging', 'dono' ) }
                    sub={ __( 'Logs gateway calls, webhook payloads, and form submissions.', 'dono' ) }
                    checked={ !! s.value( 'debug_logging', false ) }
                    onChange={ s.setValue( 'debug_logging' ) }
                />
            </Card>
        </div>
    );
}
