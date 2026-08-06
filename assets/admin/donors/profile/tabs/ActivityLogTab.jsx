import { useEffect, useMemo, useState } from '@wordpress/element';
import { DataViews } from '@wordpress/dataviews';
import { Notice } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __ } from '@wordpress/i18n';

import { eventMeta, formatAmount, formatDateTime, timeAgo } from '../helpers';
import { TimelineDot, eventTitle } from './ActivityTab';

// Deep-link to a donation's detail view, the same target the timeline uses.
function donationHref( reference ) {
    return addQueryArgs( window.location.pathname, { page: 'dono-donations', view: 'detail', reference } );
}

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
        fields:  [ 'event', 'reference', 'campaign', 'amount', 'occurred_at' ],
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
                            { /* The same sentence the overview timeline shows,
                                 minus the campaign: this table has a column for
                                 it, and the timeline does not. */ }
                            { eventTitle( item ) }
                            { item.payload?.by === 'admin' && (
                                <span className="dp-actlog__note">{ __( 'by an admin', 'dono' ) }</span>
                            ) }
                            { item.note && (
                                <span className="dp-actlog__note">“{ item.note }”</span>
                            ) }
                        </span>
                    </span>
                );
            },
        },
        {
            id:    'reference',
            label: __( 'Reference', 'dono' ),
            enableSorting: false,
            // A receipt event carries both a donation and a receipt, so
            // returning on the first would have meant a receipt row never
            // showed the number that identifies it.
            render: ( { item } ) => {
                if ( ! item.reference && ! item.receipt_number ) return '-';
                return (
                    <div className="dono-row">
                        <div className="dono-row__body">
                            { item.reference && (
                                <a className="dono-mono-link" href={ donationHref( item.reference ) }>{ item.reference }</a>
                            ) }
                            { item.receipt_number && (
                                <div className="dono-row__sub dono-row__sub--mono">{ item.receipt_number }</div>
                            ) }
                        </div>
                    </div>
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
            // Relative over absolute, the way the overview timeline reads it:
            // a bare "15h ago" with the real moment hidden in a tooltip made
            // the column impossible to scan by date.
            render: ( { item } ) => (
                <div className="dono-row">
                    <div className="dono-row__body">
                        <div className="dono-row__name">{ timeAgo( item.occurred_at ) }</div>
                        <div className="dono-row__sub">{ formatDateTime( item.occurred_at ) }</div>
                    </div>
                </div>
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
