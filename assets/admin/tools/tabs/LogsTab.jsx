import { useCallback, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { DataViews } from '@wordpress/dataviews';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __, _n, sprintf } from '@wordpress/i18n';

import Btn from '../../_shared/components/Btn';
import { formatDate } from '../../donations/format';
import ConfirmDialog from '../../_shared/components/ConfirmDialog';
import Dialog from '../../_shared/components/Dialog';
import { userCan } from '../../_shared/caps';

const PER_PAGE = 25;

// Column id to the column the route orders by.
const ORDER_BY = {
    occurred_at: 'occurred_at',
    source:      'type',
};

// The source filter offers whole types, and a row carries its family stripped
// off, so the cell and the filter value are built back up to the same string.
function fullType( row ) {
    if ( row.kind === 'webhook' ) return `webhook.${ row.source }`;
    if ( row.kind === 'error' ) return `error.${ row.source }`;

    return row.source;
}

function hasContext( row ) {
    return !! row.context && Object.keys( row.context ).length > 0;
}

/**
 * A delivery has four readings, and only two of them are faults. An event type
 * Gratora has no handler for is ordinary traffic: gateways send everything they
 * have, and most of it is none of our business.
 */
function deliveryOutcome( row ) {
    if ( ! row.verified ) {
        return { tone: 'red', label: __( 'Not verified', 'gratora-donation-platform' ) };
    }
    if ( row.error ) {
        return { tone: 'red', label: __( 'Handling failed', 'gratora-donation-platform' ) };
    }
    if ( row.processed ) {
        return { tone: 'green', label: __( 'Processed', 'gratora-donation-platform' ) };
    }
    return { tone: 'gray', label: __( 'No action needed', 'gratora-donation-platform' ) };
}

function Pill( { tone, label } ) {
    return (
        <span className={ `gratora-pill gratora-pill--${ tone }` }>
            <span className="gratora-pill__dot" />
            { label }
        </span>
    );
}

export default function LogsTab( { active, setNotice } ) {
    const [ view, setView ] = useState( {
        type:    'table',
        perPage: PER_PAGE,
        page:    1,
        sort:    { field: 'occurred_at', direction: 'desc' },
        filters: [],
        // Outcome is not among them: it reads beside the delivery it belongs
        // to. It stays a field because that is what a filter hangs on.
        fields:  [ 'occurred_at', 'source', 'message' ],
        // Key widths by field ID and let Message absorb spare table width.
        layout:  {
            styles: {
                occurred_at: { width: '160px' },
                source:      { width: '170px' },
                message:     { width: 'auto', minWidth: '280px' },
            },
        },
    } );

    const [ log, setLog ]           = useState( null );
    const [ loading, setLoading ]   = useState( false );
    const [ error, setError ]       = useState( null );
    const [ detail, setDetail ]     = useState( null );
    const [ clearing, setClearing ] = useState( false );
    const [ confirm, setConfirm ]   = useState( null );

    // A slow earlier response must not land under a newer filter and describe
    // rows the controls no longer ask for.
    const generation = useRef( 0 );

    const filterValue = ( field ) => view.filters?.find( ( f ) => f.field === field )?.value;
    const source = filterValue( 'source' ) || '';
    const status = filterValue( 'outcome' ) || '';

    const apiParams = useMemo( () => ( {
        page:     view.page,
        per_page: view.perPage,
        source,
        status,
        orderby:  ORDER_BY[ view.sort?.field ] || 'occurred_at',
        order:    view.sort?.direction || 'desc',
    } ), [ view.page, view.perPage, view.sort, source, status ] );

    const load = useCallback( () => {
        const mine = ++generation.current;
        setLoading( true );
        apiFetch( { path: addQueryArgs( '/gratora/v1/admin/tools/log', apiParams ) } )
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
                setError( err?.message || __( 'The log could not be read.', 'gratora-donation-platform' ) );
            } )
            .finally( () => {
                if ( mine === generation.current ) setLoading( false );
            } );
    }, [ apiParams ] );

    // Tabs are hidden rather than unmounted, so refetch on every visit: an entry
    // recorded while another tab was open belongs in this list.
    useEffect( () => { if ( active ) load(); }, [ active, load ] );

    const doClear = async () => {
        setClearing( true );
        try {
            const res = await apiFetch( {
                path:   addQueryArgs( '/gratora/v1/admin/tools/log', { source } ),
                method: 'DELETE',
            } );
            setView( ( v ) => ( { ...v, page: 1 } ) );
            load();
            const deleted = Number( res?.deleted ) || 0;
            setNotice( {
                type: deleted > 0 ? 'success' : 'info',
                text: sprintf(
                    /* translators: %d: number of log entries deleted. */
                    _n( '%d entry cleared.', '%d entries cleared.', deleted, 'gratora-donation-platform' ),
                    deleted
                ),
            } );
        } catch ( err ) {
            setNotice( { type: 'error', text: err?.message || __( 'Could not clear the log.', 'gratora-donation-platform' ) } );
        } finally {
            setClearing( false );
        }
    };

    // Named for what it removes: filtered to a source it clears that source
    // alone, and the delivery history goes with the failures otherwise.
    const askClear = () => setConfirm( {
        title: source
            ? __( 'Clear this source', 'gratora-donation-platform' )
            : __( 'Clear the log', 'gratora-donation-platform' ),
        message: source
            ? sprintf(
                /* translators: %s: the log source being cleared, e.g. webhook.stripe */
                __( 'Deletes every entry recorded under %s. Nothing else is touched.', 'gratora-donation-platform' ),
                source
            )
            : __( 'Deletes every entry: the failures Gratora recorded and the history of what your gateways sent. The log fills again as things happen.', 'gratora-donation-platform' ),
        confirmLabel: __( 'Clear log', 'gratora-donation-platform' ),
        destructive:  true,
        onConfirm:    doClear,
    } );

    const total     = log?.total || 0;
    const items     = log?.items || [];
    const filtered  = !! source || !! status;

    const sources = useMemo( () => log?.sources || [], [ log ] );

    // DataViews offers every field as a column, so Outcome can be switched
    // back on. The pill moves there when it is, rather than appearing twice.
    const outcomeColumn = ( view.fields || [] ).includes( 'outcome' );

    // Whether Clear log will touch what is on screen, decided by the route that
    // performs the delete rather than by a second copy of its rule here. Absent
    // reads as not blocked, which is what every source but the audit ones is.
    const clearBlocked = log?.clear_blocked || null;

    const fields = useMemo( () => [
        {
            id:            'occurred_at',
            label:         __( 'When', 'gratora-donation-platform' ),
            enableSorting: true,
            enableHiding:  false,
            getValue:      ( { item } ) => item.occurred_at || '',
            render:        ( { item } ) => formatDate( item.occurred_at ),
        },
        {
            id:            'source',
            label:         __( 'Source', 'gratora-donation-platform' ),
            enableSorting: true,
            elements:      sources.map( ( s ) => ( { value: s, label: s } ) ),
            filterBy:      { operators: [ 'is' ] },
            getValue:      ( { item } ) => fullType( item ),
            render:        ( { item } ) => <code className="gratora-log__source">{ fullType( item ) }</code>,
        },
        {
            id:            'message',
            label:         __( 'What it says', 'gratora-donation-platform' ),
            enableSorting: false,
            getValue:      ( { item } ) => item.message || '',
            render: ( { item } ) => (
                <div className="gratora-log__message">
                    <div className="gratora-log__message-line">
                        { item.kind === 'webhook' && ! outcomeColumn && (
                            <Pill { ...deliveryOutcome( item ) } />
                        ) }
                        <span>{ item.message }</span>
                    </div>
                    { item.kind === 'webhook' && item.error && (
                        <div className="gratora-row__sub gratora-log__message-sub">{ item.error }</div>
                    ) }
                </div>
            ),
        },
        {
            id:            'outcome',
            label:         __( 'Outcome', 'gratora-donation-platform' ),
            enableSorting: false,
            // The one narrowing worth offering: everything else on this screen
            // is ordinary traffic an org reads by scanning, not by filtering.
            elements:      [ { value: 'failed', label: __( 'Problems only', 'gratora-donation-platform' ) } ],
            filterBy:      { operators: [ 'is' ] },
            // Off by default, and empty here for anything but a delivery: an
            // error is a failure by definition, and what was done to a donor
            // is not an outcome at all.
            render: ( { item } ) => (
                item.kind === 'webhook' ? <Pill { ...deliveryOutcome( item ) } /> : null
            ),
        },
    ], [ sources, outcomeColumn ] );

    const actions = useMemo( () => [
        {
            id:         'detail',
            label:      __( 'View detail', 'gratora-donation-platform' ),
            isEligible: hasContext,
            callback:   ( [ item ] ) => setDetail( item ),
        },
    ], [] );

    const paginationInfo = useMemo(
        () => ( { totalItems: total, totalPages: Math.max( 1, Math.ceil( total / view.perPage ) ) } ),
        [ total, view.perPage ]
    );

    // Nothing at all has no filter to widen and no source to pick, so the table
    // has nothing to offer. A filtered miss keeps it: the chips are the only way
    // back to the rest of the log.
    const emptyAndUnfiltered = ! loading && ! error && total === 0 && ! filtered;

    return (
        <div className="gratora-panel">
            <div className="gratora-tools-logbar">
                <Btn variant="secondary" onClick={ load } disabled={ loading }>
                    { __( 'Refresh', 'gratora-donation-platform' ) }
                </Btn>
                { userCan( 'manage_options' ) && clearBlocked && (
                    <p className="gratora-tools-logbar__note">{ clearBlocked }</p>
                ) }
                { userCan( 'manage_options' ) && (
                    <Btn
                        variant="secondary"
                        onClick={ askClear }
                        // Also while a load is in flight: the verdict describes
                        // the response on screen, and a filter just changed
                        // still has the previous one's answer.
                        disabled={ clearing || loading || total === 0 || !! clearBlocked }
                        isBusy={ clearing }
                    >
                        { __( 'Clear log', 'gratora-donation-platform' ) }
                    </Btn>
                ) }
            </div>

            { error ? (
                <p className="gratora-tools-empty">
                    { __( 'The log could not be read, so this screen cannot say what has happened. Check that you are still signed in, then try Refresh.', 'gratora-donation-platform' ) }
                    { ' ' }
                    <code>{ error }</code>
                </p>
            ) : emptyAndUnfiltered ? (
                <p className="gratora-tools-empty">{ __( 'Nothing recorded yet.', 'gratora-donation-platform' ) }</p>
            ) : (
                // Carries the shared table styling every other list screen uses.
                <div className="gratora-dataviews">
                    <DataViews
                        data={ items }
                        fields={ fields }
                        view={ view }
                        onChangeView={ setView }
                        actions={ actions }
                        isLoading={ loading }
                        paginationInfo={ paginationInfo }
                        defaultLayouts={ { table: {} } }
                        search={ false }
                        getItemId={ ( item ) => String( item.id ) }
                    />
                </div>
            ) }

            { detail && (
                <Dialog
                    title={ __( 'Entry detail', 'gratora-donation-platform' ) }
                    size="wide"
                    onClose={ () => setDetail( null ) }
                    foot={ (
                        <Btn variant="secondary" onClick={ () => setDetail( null ) }>
                            { __( 'Close', 'gratora-donation-platform' ) }
                        </Btn>
                    ) }
                >
                    <div className="gratora-log__detail-head">
                        <code className="gratora-log__source">{ fullType( detail ) }</code>
                        <span className="gratora-row__sub">{ formatDate( detail.occurred_at ) }</span>
                    </div>
                    <div className="gratora-log__message">{ detail.message }</div>
                    <pre className="gratora-log__context">{ JSON.stringify( detail.context, null, 2 ) }</pre>
                </Dialog>
            ) }

            <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
        </div>
    );
}
