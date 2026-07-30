import { useEffect, useMemo, useState } from '@wordpress/element';
import { DataViews } from '@wordpress/dataviews';
import { Notice } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __ } from '@wordpress/i18n';

import { eventMeta, formatAmount, formatDateTime, timeAgo } from '../helpers';
import { TimelineDot } from './ActivityTab';

// The full activity log for one donor, paginated server-side. The overview tab
// shows the 10 most recent; this is the whole history.
export default function ActivityLogTab( { donorId } ) {
    const [ view, setView ] = useState( {
        type:    'table',
        perPage: 25,
        page:    1,
        sort:    { field: 'occurred_at', direction: 'desc' },
        filters: [],
        search:  '',
        fields:  [ 'event', 'campaign', 'amount', 'occurred_at' ],
    } );

    const [ data, setData ]       = useState( [] );
    const [ total, setTotal ]     = useState( 0 );
    const [ loading, setLoading ] = useState( false );
    const [ error, setError ]     = useState( '' );

    const apiParams = useMemo( () => ( {
        page:     view.page,
        per_page: view.perPage,
        order:    view.sort?.direction || 'desc',
    } ), [ view.page, view.perPage, view.sort?.direction ] );

    useEffect( () => {
        let aborted = false;
        setLoading( true );
        apiFetch( { path: addQueryArgs( `/dono/v1/admin/donors/${ donorId }/events`, apiParams ), parse: false } )
            .then( async ( res ) => {
                if ( aborted ) return;
                const items = await res.json();
                setData( Array.isArray( items ) ? items : [] );
                setTotal( parseInt( res.headers.get( 'X-WP-Total' ) || '0', 10 ) );
                setError( '' );
            } )
            .catch( () => { if ( ! aborted ) { setData( [] ); setTotal( 0 ); setError( __( 'Could not load activity. Refresh to try again.', 'dono' ) ); } } )
            .finally( () => { if ( ! aborted ) setLoading( false ); } );
        return () => { aborted = true; };
    }, [ donorId, apiParams ] );

    const fields = useMemo( () => [
        {
            id:    'event',
            label: __( 'Event', 'dono' ),
            enableSorting: false,
            render: ( { item } ) => {
                const meta = eventMeta( item );
                return (
                    <span className="dp-actlog__event">
                        <TimelineDot variant={ meta.dot } />
                        <span>
                            { meta.label }
                            { item.note && (
                                <span className="dp-actlog__note">“{ item.note }”</span>
                            ) }
                        </span>
                    </span>
                );
            },
        },
        {
            id:    'campaign',
            label: __( 'Campaign', 'dono' ),
            enableSorting: false,
            render: ( { item } ) => item.campaign?.title || '-',
        },
        {
            id:    'amount',
            label: __( 'Amount', 'dono' ),
            enableSorting: false,
            render: ( { item } ) => item.amount_cents !== null && item.amount_cents !== undefined
                ? (
                    <span style={ { fontVariantNumeric: 'tabular-nums', fontWeight: 500 } }>
                        { formatAmount( item.amount_cents, item.currency ) }
                    </span>
                )
                : '-',
        },
        {
            id:    'occurred_at',
            label: __( 'When', 'dono' ),
            enableSorting: true,
            render: ( { item } ) => (
                <span title={ formatDateTime( item.occurred_at ) } style={ { fontSize: 12, color: '#6b7280' } }>
                    { timeAgo( item.occurred_at ) }
                </span>
            ),
        },
    ], [] );

    const paginationInfo = useMemo(
        () => ( {
            totalItems: total,
            totalPages: Math.max( 1, Math.ceil( total / view.perPage ) ),
        } ),
        [ total, view.perPage ]
    );

    return (
        <div className="dono-dataviews dp-actlog-dv">
            { error && (
                <Notice status="error" isDismissible={ false }>{ error }</Notice>
            ) }
            <DataViews
                data={ data }
                isLoading={ loading }
                fields={ fields }
                view={ view }
                onChangeView={ setView }
                paginationInfo={ paginationInfo }
                defaultLayouts={ { table: {} } }
                getItemId={ ( item ) => String( item.id ) }
                search={ false }
            />
        </div>
    );
}
