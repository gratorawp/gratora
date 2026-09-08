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
 * FundKit has no handler for is ordinary traffic: gateways send everything they
 * have, and most of it is none of our business.
 */
function deliveryOutcome( row ) {
    if ( ! row.verified ) {
        return { tone: 'red', label: __( 'Not verified', 'fundraising-toolkit' ) };
    }
    if ( row.error ) {
        return { tone: 'red', label: __( 'Handling failed', 'fundraising-toolkit' ) };
    }
    if ( row.processed ) {
        return { tone: 'green', label: __( 'Processed', 'fundraising-toolkit' ) };
    }
    return { tone: 'gray', label: __( 'No action needed', 'fundraising-toolkit' ) };
}

function Pill( { tone, label } ) {
    return (
        <span className={ `fundkit-pill fundkit-pill--${ tone }` }>
            <span className="fundkit-pill__dot" />
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
        // Key widths by field ID and let Message absorb spare table width.
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
        apiFetch( { path: addQueryArgs( '/fundkit/v1/admin/tools/log', apiParams ) } )
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
                setError( err?.message || __( 'The log could not be read.', 'fundraising-toolkit' ) );
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
                path:   addQueryArgs( '/fundkit/v1/admin/tools/log', { source } ),
                method: 'DELETE',
            } );
            setView( ( v ) => ( { ...v, page: 1 } ) );
            load();
            setNotice( {
                type: 'success',
                text: sprintf(
                    /* translators: %d: number of log entries deleted. */
                    _n( '%d entry cleared.', '%d entries cleared.', Number( res?.deleted ) || 0, 'fundraising-toolkit' ),
                    Number( res?.deleted ) || 0
                ),
            } );
        } catch ( err ) {
            setNotice( { type: 'error', text: err?.message || __( 'Could not clear the log.', 'fundraising-toolkit' ) } );
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
            ? __( 'Clear this source', 'fundraising-toolkit' )
            : __( 'Clear the log', 'fundraising-toolkit' ),
        message: source
            ? sprintf(
                /* translators: %s: the log source being cleared, e.g. webhook.stripe */
                __( 'Deletes every entry recorded under %s. Nothing else is touched.', 'fundraising-toolkit' ),
                source
            )
            : __( 'Deletes every entry: the failures Fundraising Toolkit recorded and the history of what your gateways sent. The log fills again as things happen.', 'fundraising-toolkit' ),
        confirmLabel: __( 'Clear log', 'fundraising-toolkit' ),
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
            label:         __( 'When', 'fundraising-toolkit' ),
            enableSorting: true,
            enableHiding:  false,
            getValue:      ( { item } ) => item.occurred_at || '',
            render:        ( { item } ) => formatDate( item.occurred_at ),
        },
        {
            id:            'source',
            label:         __( 'Source', 'fundraising-toolkit' ),
            enableSorting: true,
            elements:      sources.map( ( s ) => ( { value: s, label: s } ) ),
            filterBy:      { operators: [ 'is' ] },
            getValue:      ( { item } ) => fullType( item ),
            render:        ( { item } ) => <code className="fundkit-log__source">{ fullType( item ) }</code>,
        },
        {
            id:            'message',
            label:         __( 'What it says', 'fundraising-toolkit' ),
            enableSorting: false,
            getValue:      ( { item } ) => item.message || '',
            render: ( { item } ) => (
                <div className="fundkit-log__message">
                    <div>{ item.message }</div>
                    { item.kind === 'webhook' && item.error && (
                        <div className="fundkit-row__sub fundkit-log__message-sub">{ item.error }</div>
                    ) }
                </div>
            ),
        },
        {
            id:            'outcome',
            label:         __( 'Outcome', 'fundraising-toolkit' ),
            enableSorting: false,
            // The one narrowing worth offering: everything else on this screen
            // is ordinary traffic an org reads by scanning, not by filtering.
            elements:      [ { value: 'failed', label: __( 'Problems only', 'fundraising-toolkit' ) } ],
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
            label:      __( 'View detail', 'fundraising-toolkit' ),
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
        <div className="fundkit-panel">
            <div className="fundkit-tools-logbar">
                <Btn variant="secondary" onClick={ load } disabled={ loading }>
                    { __( 'Refresh', 'fundraising-toolkit' ) }
                </Btn>
                { userCan( 'manage_options' ) && (
                    <Btn
                        variant="secondary"
                        onClick={ askClear }
                        disabled={ clearing || total === 0 }
                        isBusy={ clearing }
                    >
                        { __( 'Clear log', 'fundraising-toolkit' ) }
                    </Btn>
                ) }
            </div>

            { error ? (
                <p className="fundkit-tools-empty">
                    { __( 'The log could not be read, so this screen cannot say what has happened. Check that you are still signed in, then try Refresh.', 'fundraising-toolkit' ) }
                    { ' ' }
                    <code>{ error }</code>
                </p>
            ) : emptyAndUnfiltered ? (
                <p className="fundkit-tools-empty">{ __( 'Nothing recorded yet.', 'fundraising-toolkit' ) }</p>
            ) : (
                // Carries the shared table styling every other list screen uses.
                <div className="fundkit-dataviews">
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
                    title={ __( 'Entry detail', 'fundraising-toolkit' ) }
                    size="wide"
                    onClose={ () => setDetail( null ) }
                    foot={ (
                        <Btn variant="secondary" onClick={ () => setDetail( null ) }>
                            { __( 'Close', 'fundraising-toolkit' ) }
                        </Btn>
                    ) }
                >
                    <div className="fundkit-log__detail-head">
                        <code className="fundkit-log__source">{ fullType( detail ) }</code>
                        <span className="fundkit-row__sub">{ formatDate( detail.occurred_at ) }</span>
                    </div>
                    <div className="fundkit-log__message">{ detail.message }</div>
                    <pre className="fundkit-log__context">{ JSON.stringify( detail.context, null, 2 ) }</pre>
                </Dialog>
            ) }

            <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
        </div>
    );
}
