import { useCallback, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';

import Card from '../../_shared/components/Card';
import Btn from '../../_shared/components/Btn';
import ConfirmDialog from '../../_shared/components/ConfirmDialog';

const PER_PAGE = 25;

function formatWhen( iso ) {
    const d = new Date( ( iso || '' ).replace( ' ', 'T' ) + 'Z' );
    return isNaN( d ) ? '' : d.toLocaleString();
}

export default function LogsTab( { active, setNotice } ) {
    const [ log, setLog ]         = useState( null );
    const [ loading, setLoading ] = useState( false );
    const [ page, setPage ]       = useState( 1 );
    const [ source, setSource ]   = useState( '' );
    const [ open, setOpen ]       = useState( {} );
    const [ clearing, setClearing ] = useState( false );
    const [ confirm, setConfirm ]   = useState( null );

    const load = useCallback( () => {
        setLoading( true );
        apiFetch( {
            path: `/dono/v1/admin/tools/errors?page=${ page }&per_page=${ PER_PAGE }&source=${ encodeURIComponent( source ) }`,
        } )
            .then( setLog )
            .catch( () => setLog( { items: [], total: 0, sources: [] } ) )
            .finally( () => setLoading( false ) );
    }, [ page, source ] );

    // Tabs are hidden rather than unmounted, so refetch on every visit: an
    // error recorded while another tab was open belongs in this list.
    useEffect( () => { if ( active ) load(); }, [ active, load ] );

    const askClear = () => setConfirm( {
        title:        __( 'Clear the error log', 'dono' ),
        message:      __( 'Deletes every recorded error. Nothing else is touched, and the log fills again as new errors happen.', 'dono' ),
        confirmLabel: __( 'Clear log', 'dono' ),
        destructive:  true,
        onConfirm:    doClear,
    } );

    const doClear = async () => {
        setClearing( true );
        try {
            const res = await apiFetch( { path: '/dono/v1/admin/tools/errors', method: 'DELETE' } );
            setPage( 1 );
            setSource( '' );
            load();
            setNotice( {
                type: 'success',
                text: sprintf(
                    /* translators: %d: number of log entries deleted. */
                    _n( '%d entry cleared.', '%d entries cleared.', Number( res?.deleted ) || 0, 'dono' ),
                    Number( res?.deleted ) || 0
                ),
            } );
        } catch ( err ) {
            setNotice( { type: 'error', text: err?.message || __( 'Could not clear the log.', 'dono' ) } );
        } finally {
            setClearing( false );
        }
    };

    const total   = log?.total || 0;
    const items   = log?.items || [];
    const sources = log?.sources || [];
    const pages   = Math.max( 1, Math.ceil( total / PER_PAGE ) );

    return (
        <div className="dono-panel">
            <Card
                title={ __( 'Error log', 'dono' ) }
                sub={ __( 'What Dono could not finish, newest first. Entries age out with the activity log.', 'dono' ) }
            >
                <div className="dono-tools-logbar">
                    <label className="dono-tools-field">
                        { __( 'Source', 'dono' ) }
                        <select
                            className="dono-select"
                            value={ source }
                            onChange={ ( e ) => { setSource( e.target.value ); setPage( 1 ); } }
                        >
                            <option value="">{ __( 'All sources', 'dono' ) }</option>
                            { sources.map( ( sc ) => (
                                <option key={ sc } value={ sc }>{ sc }</option>
                            ) ) }
                        </select>
                    </label>
                    <div className="dono-tools-logbar__actions">
                        <Btn variant="secondary" onClick={ load } disabled={ loading }>
                            { __( 'Refresh', 'dono' ) }
                        </Btn>
                        <Btn
                            variant="secondary"
                            onClick={ askClear }
                            disabled={ clearing || total === 0 }
                            isBusy={ clearing }
                        >
                            { __( 'Clear log', 'dono' ) }
                        </Btn>
                    </div>
                </div>

                { loading && ! items.length ? (
                    <p className="dono-tools-empty">{ __( 'Loading…', 'dono' ) }</p>
                ) : ! items.length ? (
                    <p className="dono-tools-empty">
                        { source
                            ? __( 'Nothing recorded for that source.', 'dono' )
                            : __( 'No errors recorded. Nothing to see here is the good outcome.', 'dono' ) }
                    </p>
                ) : (
                    <ul className="dono-tools-log">
                        { items.map( ( e ) => {
                            const hasContext = e.context && Object.keys( e.context ).length > 0;
                            return (
                                <li key={ e.id }>
                                    <div className="dono-tools-log__head">
                                        <code className="dono-tools-log__source">{ e.source }</code>
                                        <span className="dono-tools-log__when">{ formatWhen( e.occurred_at ) }</span>
                                    </div>
                                    <div className="dono-tools-log__message">{ e.message }</div>
                                    { hasContext && (
                                        <>
                                            <button
                                                type="button"
                                                className="dono-tools-log__toggle"
                                                onClick={ () => setOpen( ( o ) => ( { ...o, [ e.id ]: ! o[ e.id ] } ) ) }
                                            >
                                                { open[ e.id ] ? __( 'Hide detail', 'dono' ) : __( 'Show detail', 'dono' ) }
                                            </button>
                                            { open[ e.id ] && (
                                                <pre className="dono-tools-log__context">
                                                    { JSON.stringify( e.context, null, 2 ) }
                                                </pre>
                                            ) }
                                        </>
                                    ) }
                                </li>
                            );
                        } ) }
                    </ul>
                ) }

                { pages > 1 && (
                    <div className="dono-tools-pager">
                        <Btn variant="secondary" disabled={ page <= 1 } onClick={ () => setPage( page - 1 ) }>
                            { __( 'Previous', 'dono' ) }
                        </Btn>
                        <span>
                            { sprintf(
                                /* translators: 1: current page, 2: total pages. */
                                __( 'Page %1$d of %2$d', 'dono' ),
                                page,
                                pages
                            ) }
                        </span>
                        <Btn variant="secondary" disabled={ page >= pages } onClick={ () => setPage( page + 1 ) }>
                            { __( 'Next', 'dono' ) }
                        </Btn>
                    </div>
                ) }
            </Card>

            <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
        </div>
    );
}
