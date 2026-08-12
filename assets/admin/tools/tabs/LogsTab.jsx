import { useCallback, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { DataViews } from '@wordpress/dataviews';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __, _n, sprintf } from '@wordpress/i18n';

import Btn from '../../_shared/components/Btn';
import { formatDate } from '../../donations/format';
import ConfirmDialog from '../../_shared/components/ConfirmDialog';
import Dialog from '../../_shared/components/Dialog';

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
 * Dono has no handler for is ordinary traffic: gateways send everything they
 * have, and most of it is none of our business.
 */
function deliveryOutcome( row ) {
    if ( ! row.verified ) {
        return { tone: 'red', label: __( 'Not verified', 'dono-fundraising-platform' ) };
    }
    if ( row.error ) {
        return { tone: 'red', label: __( 'Handling failed', 'dono-fundraising-platform' ) };
    }
    if ( row.processed ) {
        return { tone: 'green', label: __( 'Processed', 'dono-fundraising-platform' ) };
    }
    return { tone: 'gray', label: __( 'No action needed', 'dono-fundraising-platform' ) };
}

function Pill( { tone, label } ) {
    return (
        <span className={ `dono-pill dono-pill--${ tone }` }>
            <span className="dono-pill__dot" />
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
        fields:  [ 'occurred_at', 'source', 'message', 'outcome' ],
        // Keyed by field id rather than by position, so hiding or reordering a
        // column cannot hand the width to a different one. Every cell is sized
        // to its content by the shared table styles, which leaves no column to
        // absorb the row when the view carries neither a selection column nor a
        // primary one. Message is asked for explicitly: it is the column
        // somebody came to read.
        layout:  {
            styles: {
                occurred_at: { width: '160px' },
                source:      { width: '170px' },
                message:     { width: 'auto', minWidth: '280px' },
                outcome:     { width: '140px' },
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
        apiFetch( { path: addQueryArgs( '/dono/v1/admin/tools/log', apiParams ) } )
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
                setError( err?.message || __( 'The log could not be read.', 'dono-fundraising-platform' ) );
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
                path:   addQueryArgs( '/dono/v1/admin/tools/log', { source } ),
                method: 'DELETE',
            } );
            setView( ( v ) => ( { ...v, page: 1 } ) );
            load();
            setNotice( {
                type: 'success',
                text: sprintf(
                    /* translators: %d: number of log entries deleted. */
                    _n( '%d entry cleared.', '%d entries cleared.', Number( res?.deleted ) || 0, 'dono-fundraising-platform' ),
                    Number( res?.deleted ) || 0
                ),
            } );
        } catch ( err ) {
            setNotice( { type: 'error', text: err?.message || __( 'Could not clear the log.', 'dono-fundraising-platform' ) } );
        } finally {
            setClearing( false );
        }
    };

    // Named for what it removes: filtered to a source it clears that source
    // only, and the delivery history goes with the failures either way.
    // Named for what it removes: filtered to a source it clears that source
    // alone, and the delivery history goes with the failures otherwise.
    const askClear = () => setConfirm( {
        title: source
            ? __( 'Clear this source', 'dono-fundraising-platform' )
            : __( 'Clear the log', 'dono-fundraising-platform' ),
        message: source
            ? sprintf(
                /* translators: %s: the log source being cleared, e.g. webhook.stripe */
                __( 'Deletes every entry recorded under %s. Nothing else is touched.', 'dono-fundraising-platform' ),
                source
            )
            : __( 'Deletes every entry: the failures Dono recorded and the history of what your gateways sent. The log fills again as things happen.', 'dono-fundraising-platform' ),
        confirmLabel: __( 'Clear log', 'dono-fundraising-platform' ),
        destructive:  true,
        onConfirm:    doClear,
    } );

    const total     = log?.total || 0;
    const items     = log?.items || [];
    const filtered  = !! source || !! status;

    const sources = useMemo( () => log?.sources || [], [ log ] );

    const fields = useMemo( () => [
        {
            id:            'occurred_at',
            label:         __( 'When', 'dono-fundraising-platform' ),
            enableSorting: true,
            enableHiding:  false,
            getValue:      ( { item } ) => item.occurred_at || '',
            render:        ( { item } ) => formatDate( item.occurred_at ),
        },
        {
            id:            'source',
            label:         __( 'Source', 'dono-fundraising-platform' ),
            enableSorting: true,
            elements:      sources.map( ( s ) => ( { value: s, label: s } ) ),
            filterBy:      { operators: [ 'is' ] },
            getValue:      ( { item } ) => fullType( item ),
            render:        ( { item } ) => <code className="dono-log__source">{ fullType( item ) }</code>,
        },
        {
            id:            'message',
            label:         __( 'What it says', 'dono-fundraising-platform' ),
            enableSorting: false,
            getValue:      ( { item } ) => item.message || '',
            render: ( { item } ) => (
                <div className="dono-log__message">
                    <div>{ item.message }</div>
                    { item.kind === 'webhook' && item.error && (
                        <div className="dono-row__sub dono-log__message-sub">{ item.error }</div>
                    ) }
                </div>
            ),
        },
        {
            id:            'outcome',
            label:         __( 'Outcome', 'dono-fundraising-platform' ),
            enableSorting: false,
            // The one narrowing worth offering: everything else on this screen
            // is ordinary traffic an org reads by scanning, not by filtering.
            elements:      [ { value: 'failed', label: __( 'Problems only', 'dono-fundraising-platform' ) } ],
            filterBy:      { operators: [ 'is' ] },
            render: ( { item } ) => {
                if ( item.kind === 'webhook' ) {
                    return <Pill { ...deliveryOutcome( item ) } />;
                }
                // An error is a failure by definition: a pill on every one of
                // them says nothing the source has not already said.
                return null;
            },
        },
    ], [ sources ] );

    const actions = useMemo( () => [
        {
            id:         'detail',
            label:      __( 'View detail', 'dono-fundraising-platform' ),
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
        <div className="dono-panel">
            <div className="dono-tools-logbar">
                <Btn variant="secondary" onClick={ load } disabled={ loading }>
                    { __( 'Refresh', 'dono-fundraising-platform' ) }
                </Btn>
                <Btn
                    variant="secondary"
                    onClick={ askClear }
                    disabled={ clearing || total === 0 }
                    isBusy={ clearing }
                >
                    { __( 'Clear log', 'dono-fundraising-platform' ) }
                </Btn>
            </div>

            { error ? (
                <p className="dono-tools-empty">
                    { __( 'The log could not be read, so this screen cannot say what has happened. Check that you are still signed in, then try Refresh.', 'dono-fundraising-platform' ) }
                    { ' ' }
                    <code>{ error }</code>
                </p>
            ) : emptyAndUnfiltered ? (
                <p className="dono-tools-empty">{ __( 'Nothing recorded yet.', 'dono-fundraising-platform' ) }</p>
            ) : (
                // Carries the shared table styling every other list screen uses.
                <div className="dono-dataviews">
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
                    title={ __( 'Entry detail', 'dono-fundraising-platform' ) }
                    size="wide"
                    onClose={ () => setDetail( null ) }
                    foot={ (
                        <Btn variant="secondary" onClick={ () => setDetail( null ) }>
                            { __( 'Close', 'dono-fundraising-platform' ) }
                        </Btn>
                    ) }
                >
                    <div className="dono-log__detail-head">
                        <code className="dono-log__source">{ fullType( detail ) }</code>
                        <span className="dono-row__sub">{ formatDate( detail.occurred_at ) }</span>
                    </div>
                    <div className="dono-log__message">{ detail.message }</div>
                    <pre className="dono-log__context">{ JSON.stringify( detail.context, null, 2 ) }</pre>
                </Dialog>
            ) }

            <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
        </div>
    );
}
