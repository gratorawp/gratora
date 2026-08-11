import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
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

/**
 * A delivery has four readings, and only two of them are faults. An event type
 * Dono has no handler for is ordinary traffic: gateways send everything they
 * have, and most of it is none of our business.
 */
function deliveryOutcome( row ) {
    if ( ! row.verified ) {
        return { tone: 'red', label: __( 'Not verified', 'dono' ) };
    }
    if ( row.error ) {
        return { tone: 'red', label: __( 'Handling failed', 'dono' ) };
    }
    if ( row.processed ) {
        return { tone: 'green', label: __( 'Processed', 'dono' ) };
    }
    return { tone: 'gray', label: __( 'No action needed', 'dono' ) };
}

export default function LogsTab( { active, setNotice } ) {
    const [ log, setLog ]           = useState( null );
    const [ loading, setLoading ]   = useState( false );
    const [ error, setError ]       = useState( null );
    const [ page, setPage ]         = useState( 1 );
    const [ source, setSource ]     = useState( '' );
    const [ status, setStatus ]     = useState( '' );
    const [ open, setOpen ]         = useState( {} );
    const [ clearing, setClearing ] = useState( false );
    const [ confirm, setConfirm ]   = useState( null );

    // A slow earlier response must not land under a newer filter and describe
    // rows the controls no longer ask for.
    const generation = useRef( 0 );

    const load = useCallback( () => {
        const mine = ++generation.current;
        setLoading( true );
        apiFetch( {
            path: `/dono/v1/admin/tools/log?page=${ page }&per_page=${ PER_PAGE }`
                + `&source=${ encodeURIComponent( source ) }&status=${ encodeURIComponent( status ) }`,
        } )
            .then( ( res ) => {
                if ( mine !== generation.current ) return;
                setLog( res );
                setError( null );
            } )
            .catch( ( err ) => {
                if ( mine !== generation.current ) return;
                // Deliberately not an empty result: "nothing has happened" and
                // "we could not find out" are opposite answers, and this screen
                // is read precisely when someone suspects the second.
                setError( err?.message || __( 'The log could not be read.', 'dono' ) );
            } )
            .finally( () => {
                if ( mine === generation.current ) setLoading( false );
            } );
    }, [ page, source, status ] );

    // Tabs are hidden rather than unmounted, so refetch on every visit: an entry
    // recorded while another tab was open belongs in this list.
    useEffect( () => { if ( active ) load(); }, [ active, load ] );

    const doClear = async () => {
        setClearing( true );
        try {
            const res = await apiFetch( {
                path:   `/dono/v1/admin/tools/log?source=${ encodeURIComponent( source ) }`,
                method: 'DELETE',
            } );
            setPage( 1 );
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

    // Named for what it removes: filtered to a source it clears that source
    // only, and the delivery history goes with the failures either way.
    const askClear = () => setConfirm( {
        title: source
            ? __( 'Clear this source', 'dono' )
            : __( 'Clear the log', 'dono' ),
        message: source
            ? sprintf(
                /* translators: %s: the log source being cleared, e.g. webhook.stripe */
                __( 'Deletes every entry recorded under %s. Nothing else is touched.', 'dono' ),
                source
            )
            : __( 'Deletes the failures Dono recorded and the history of what your gateways sent. What happened to donations, donors and subscriptions is kept: those entries are the record the donor timelines and the dashboard read from.', 'dono' ),
        confirmLabel: __( 'Clear log', 'dono' ),
        destructive:  true,
        onConfirm:    doClear,
    } );

    const total     = log?.total || 0;
    const items     = log?.items || [];
    const sources   = log?.sources || [];
    const retention = Number( log?.retention_days ) || 0;
    const pages     = Math.max( 1, Math.ceil( total / PER_PAGE ) );
    const filtered  = !! source || !! status;

    const sub = retention > 0
        ? sprintf(
            /* translators: %d: number of days entries are kept. */
            _n(
                'Everything Dono recorded, newest first: what happened, what your gateways sent, and what it could not finish. Entries are removed after %d day.',
                'Everything Dono recorded, newest first: what happened, what your gateways sent, and what it could not finish. Entries are removed after %d days.',
                retention,
                'dono'
            ),
            retention
        )
        : __( 'Everything Dono recorded, newest first: what happened, what your gateways sent, and what it could not finish.', 'dono' );

    return (
        <div className="dono-panel">
            <Card title={ __( 'Log', 'dono' ) } sub={ sub }>
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
                    <label className="dono-tools-field">
                        { __( 'Show', 'dono' ) }
                        <select
                            className="dono-select"
                            value={ status }
                            onChange={ ( e ) => { setStatus( e.target.value ); setPage( 1 ); } }
                        >
                            <option value="">{ __( 'Everything', 'dono' ) }</option>
                            <option value="failed">{ __( 'Problems only', 'dono' ) }</option>
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

                { error ? (
                    <p className="dono-tools-empty">
                        { __( 'The log could not be read, so this screen cannot say what has happened. Check that you are still signed in, then try Refresh.', 'dono' ) }
                        { ' ' }
                        <code>{ error }</code>
                    </p>
                ) : loading && ! items.length ? (
                    <p className="dono-tools-empty">{ __( 'Loading…', 'dono' ) }</p>
                ) : ! items.length ? (
                    <p className="dono-tools-empty">
                        { filtered
                            ? __( 'Nothing matches those filters.', 'dono' )
                            : __( 'Nothing recorded yet.', 'dono' ) }
                    </p>
                ) : (
                    <ul className="dono-tools-log">
                        { items.map( ( e ) => {
                            const isDelivery = e.kind === 'webhook';
                            const outcome    = isDelivery ? deliveryOutcome( e ) : null;
                            const hasContext = e.context && Object.keys( e.context ).length > 0;

                            return (
                                <li key={ e.id }>
                                    <div className="dono-tools-log__head">
                                        <code className="dono-tools-log__source">
                                            { isDelivery ? `webhook.${ e.source }` : e.source }
                                        </code>
                                        { outcome && (
                                            <span className={ `dono-pill dono-pill--${ outcome.tone }` }>
                                                <span className="dono-pill__dot" />
                                                { outcome.label }
                                            </span>
                                        ) }
                                        <span className="dono-tools-log__when">{ formatWhen( e.occurred_at ) }</span>
                                    </div>
                                    <div className="dono-tools-log__message">{ e.message }</div>
                                    { isDelivery && e.error && (
                                        <div className="dono-tools-log__message">{ e.error }</div>
                                    ) }
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

                { ! error && !! items.length && (
                    <p className="dono-tools-note">
                        { __( 'A delivery marked as needing no action is normal: gateways send every event they have, and Dono acts only on the ones it needs. Request bodies are not kept, because they carry the donor details the gateway sent. Clearing the log removes failures and deliveries, not what happened to a donation.', 'dono' ) }
                    </p>
                ) }

                { ! error && pages > 1 && (
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
