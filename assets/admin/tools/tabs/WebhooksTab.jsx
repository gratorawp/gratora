import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';

import Card from '../../_shared/components/Card';
import Btn from '../../_shared/components/Btn';

const PER_PAGE = 25;

function formatWhen( iso ) {
    const d = new Date( ( iso || '' ).replace( ' ', 'T' ) + 'Z' );
    return isNaN( d ) ? '' : d.toLocaleString();
}

function outcomeOf( d ) {
    if ( ! d.signature_ok ) {
        return { tone: 'red', label: __( 'Signature not verified', 'dono' ) };
    }
    if ( d.processed ) {
        return { tone: 'green', label: __( 'Processed', 'dono' ) };
    }
    if ( d.error ) {
        return { tone: 'red', label: __( 'Verified, handling failed', 'dono' ) };
    }

    // Gray rather than amber: Dono acts on the events it needs and ignores the
    // rest, so this is the resting state of most deliveries and not a fault.
    return { tone: 'gray', label: __( 'Verified, no action needed', 'dono' ) };
}

export default function WebhooksTab( { active } ) {
    const [ log, setLog ]         = useState( null );
    const [ error, setError ]     = useState( null );
    const [ loading, setLoading ] = useState( false );
    const [ page, setPage ]       = useState( 1 );
    const [ gateway, setGateway ] = useState( '' );
    const [ status, setStatus ]   = useState( '' );

    // Changing a filter starts a request without cancelling the one before it,
    // and the two can land in either order. Only the newest may render, or the
    // rows and the controls above them describe different queries.
    const request = useRef( 0 );

    const load = useCallback( () => {
        const seq = ++request.current;

        setLoading( true );
        apiFetch( {
            path: `/dono/v1/admin/tools/webhooks?page=${ page }&per_page=${ PER_PAGE }&gateway=${ encodeURIComponent( gateway ) }&status=${ encodeURIComponent( status ) }`,
        } )
            .then( ( res ) => {
                if ( seq !== request.current ) {
                    return;
                }
                setLog( res );
                setError( null );
            } )
            // An unread log is not an empty log. Anything rendered from a
            // failed request would be an answer this screen does not have.
            .catch( ( err ) => {
                if ( seq !== request.current ) {
                    return;
                }
                setError( err?.message || __( 'The request failed.', 'dono' ) );
            } )
            .finally( () => {
                if ( seq === request.current ) {
                    setLoading( false );
                }
            } );
    }, [ page, gateway, status ] );

    // Tabs are hidden rather than unmounted, so refetch on every visit: a
    // delivery that arrived while another tab was open belongs in this list.
    useEffect( () => { if ( active ) load(); }, [ active, load ] );

    const total    = log?.total || 0;
    const items    = log?.items || [];
    const gateways = log?.gateways || [];
    const pages    = Math.max( 1, Math.ceil( total / PER_PAGE ) );

    // The gateway list is built from the whole table, not the filtered page, so
    // an empty one is the difference between nothing has ever arrived and
    // nothing matches what is selected.
    const anyDeliveries = gateways.length > 0;

    // Old deliveries are pruned, so how far back the list reaches is part of
    // reading it. Stated only once the route has said what the window is.
    const retention = log?.retention_days;
    let sub = __( 'What your gateways sent this site, newest first.', 'dono' );
    if ( retention > 0 ) {
        sub = sprintf(
            /* translators: %d: number of days deliveries are kept. */
            _n(
                'What your gateways sent this site, newest first. Deliveries older than %d day are removed, so a recent one that is not listed never arrived.',
                'What your gateways sent this site, newest first. Deliveries older than %d days are removed, so a recent one that is not listed never arrived.',
                retention,
                'dono'
            ),
            retention
        );
    } else if ( retention === 0 ) {
        sub = __( 'What your gateways sent this site, newest first. Nothing is removed, so a delivery that is not listed never arrived.', 'dono' );
    }

    return (
        <div className="dono-panel">
            <Card
                title={ __( 'Webhook deliveries', 'dono' ) }
                sub={ sub }
            >
                <div className="dono-tools-logbar">
                    <div className="dono-advanced-actions">
                        <label className="dono-tools-field">
                            { __( 'Gateway', 'dono' ) }
                            <select
                                className="dono-select"
                                value={ gateway }
                                onChange={ ( e ) => { setGateway( e.target.value ); setPage( 1 ); } }
                            >
                                <option value="">{ __( 'All gateways', 'dono' ) }</option>
                                { gateways.map( ( g ) => (
                                    <option key={ g } value={ g }>{ g }</option>
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
                                <option value="">{ __( 'All deliveries', 'dono' ) }</option>
                                <option value="failed">{ __( 'Failures only', 'dono' ) }</option>
                            </select>
                        </label>
                    </div>
                    <div className="dono-tools-logbar__actions">
                        <Btn variant="secondary" onClick={ load } disabled={ loading }>
                            { __( 'Refresh', 'dono' ) }
                        </Btn>
                    </div>
                </div>

                { loading && ! items.length ? (
                    <p className="dono-tools-empty">{ __( 'Loading…', 'dono' ) }</p>
                ) : error ? (
                    <p className="dono-tools-empty">
                        { __( 'The delivery log could not be read, so this screen cannot say what has arrived. Check that you are still signed in, then try Refresh.', 'dono' ) }
                        { ' ' }
                        { error }
                    </p>
                ) : ! items.length ? (
                    <p className="dono-tools-empty">
                        { anyDeliveries
                            ? __( 'No deliveries match those filters.', 'dono' )
                            : __( 'No deliveries recorded yet. Nothing has reached this site through a webhook.', 'dono' ) }
                    </p>
                ) : (
                    <ul className="dono-tools-log">
                        { items.map( ( d ) => {
                            const outcome = outcomeOf( d );
                            return (
                                <li key={ d.id }>
                                    <div className="dono-tools-log__head">
                                        <code className="dono-tools-log__source">{ d.gateway }</code>
                                        <span className="dono-tools-log__when">{ formatWhen( d.received_at ) }</span>
                                    </div>
                                    <div className="dono-tools-log__message">
                                        { d.event_type }
                                        { ' ' }
                                        <span className={ `dono-pill dono-pill--${ outcome.tone }` }>
                                            <span className="dono-pill__dot" />
                                            { outcome.label }
                                        </span>
                                    </div>
                                    { d.error && (
                                        <div className="dono-tools-log__message">{ d.error }</div>
                                    ) }
                                </li>
                            );
                        } ) }
                    </ul>
                ) }

                { ! error && items.length > 0 && (
                    <p className="dono-tools-note">
                        { __( 'Verified means the delivery is genuinely from your gateway. Dono only acts on the events it needs, so a verified delivery with no action needed is normal. Payloads are not shown here: they carry the donor details the gateway sent.', 'dono' ) }
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
        </div>
    );
}
