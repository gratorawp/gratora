import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { DataViews } from '@wordpress/dataviews';
import { Notice } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Mail as MailIcon, Check as CheckIcon } from 'lucide-react';

import ConfirmDialog from '../../../_shared/components/ConfirmDialog';
import { notify } from '../../../_shared/notify';
import { formatAmount, formatDateTime, timeAgo, donationStatusPill } from '../helpers';

const STATUS_OPTIONS = [
    { value: 'paid',           label: __( 'Paid', 'dono' ) },
    { value: 'pending',        label: __( 'Pending', 'dono' ) },
    { value: 'failed',         label: __( 'Failed', 'dono' ) },
    { value: 'refunded',       label: __( 'Refunded', 'dono' ) },
    { value: 'partial_refund', label: __( 'Partial refund', 'dono' ) },
    { value: 'disputed',       label: __( 'Disputed', 'dono' ) },
];

function donationHref( reference ) {
    return addQueryArgs( window.location.pathname, {
        page:      'dono-donations',
        view:      'detail',
        reference,
    } );
}

export default function DonationsTab( { donorId } ) {
    const [ view, setView ] = useState( {
        type:    'table',
        perPage: 25,
        page:    1,
        sort:    { field: 'created_at', direction: 'desc' },
        filters: [],
        search:  '',
        fields:  [ 'reference', 'amount', 'status', 'frequency', 'campaign', 'created_at' ],
    } );

    const [ data, setData ]       = useState( [] );
    const [ total, setTotal ]     = useState( 0 );
    const [ loading, setLoading ] = useState( false );
    const [ error, setError ]     = useState( '' );
    const [ confirm, setConfirm ] = useState( null );

    const statusFilter = view.filters?.find( ( f ) => f.field === 'status' );

    const apiParams = useMemo( () => ( {
        donor_id: donorId,
        page:     view.page,
        per_page: view.perPage,
        orderby:  view.sort?.field === 'amount' ? 'amount_cents' : ( view.sort?.field || 'created_at' ),
        order:    view.sort?.direction || 'desc',
        status:   statusFilter?.value || undefined,
        search:   view.search || undefined,
    } ), [ donorId, view, statusFilter ] );

    useEffect( () => {
        let aborted = false;
        setLoading( true );
        apiFetch( { path: addQueryArgs( '/dono/v1/admin/donations', apiParams ), parse: false } )
            .then( async ( res ) => {
                if ( aborted ) return;
                const items = await res.json();
                setData( Array.isArray( items ) ? items : [] );
                setTotal( parseInt( res.headers.get( 'X-WP-Total' ) || '0', 10 ) );
                setError( '' );
            } )
            .catch( () => { if ( ! aborted ) { setData( [] ); setTotal( 0 ); setError( __( 'Could not load donations. Refresh to try again.', 'dono' ) ); } } )
            .finally( () => { if ( ! aborted ) setLoading( false ); } );
        return () => { aborted = true; };
    }, [ apiParams ] );

    const fields = useMemo( () => [
        {
            id:    'reference',
            label: __( 'Reference', 'dono' ),
            render: ( { item } ) => (
                <a
                    href={ donationHref( item.reference ) }
                    style={ { fontFamily: 'ui-monospace, monospace', fontSize: 12.5, color: '#14693a', textDecoration: 'none' } }
                >
                    { item.reference }
                </a>
            ),
        },
        {
            id:    'amount',
            label: __( 'Amount', 'dono' ),
            enableSorting: true,
            render: ( { item } ) => (
                <span style={ { fontVariantNumeric: 'tabular-nums', fontWeight: 500 } }>
                    { formatAmount( item.amount_cents, item.currency ) }
                </span>
            ),
        },
        {
            id:    'status',
            label: __( 'Status', 'dono' ),
            elements: STATUS_OPTIONS,
            enableSorting: false,
            filterBy: { operators: [ 'is' ] },
            render: ( { item } ) => {
                const p = donationStatusPill( item.status );
                return <span className={ `dp-pill ${ p.cls }` }>{ p.label }</span>;
            },
        },
        {
            id:    'frequency',
            label: __( 'Frequency', 'dono' ),
            render: ( { item } ) => item.frequency === 'one_time'
                ? __( 'One-time', 'dono' )
                : <span style={ { textTransform: 'capitalize' } }>{ item.frequency }</span>,
        },
        {
            id:    'campaign',
            label: __( 'Campaign', 'dono' ),
            render: ( { item } ) => item.campaign?.title || '-',
        },
        {
            id:    'created_at',
            label: __( 'When', 'dono' ),
            enableSorting: true,
            render: ( { item } ) => {
                const iso = item.paid_at || item.created_at;
                return (
                    <span title={ formatDateTime( iso ) } style={ { fontSize: 12, color: '#6b7280' } }>
                        { timeAgo( iso ) }
                    </span>
                );
            },
        },
    ], [] );

    const paginationInfo = useMemo(
        () => ( {
            totalItems: total,
            totalPages: Math.max( 1, Math.ceil( total / view.perPage ) ),
        } ),
        [ total, view.perPage ]
    );

    const refetch = useCallback( () => setView( ( v ) => ( { ...v } ) ), [] );

    const actions = useMemo( () => [
        {
            id:           'mark-paid',
            label:        __( 'Mark as paid', 'dono' ),
            icon:         () => <CheckIcon size={ 16 } strokeWidth={ 1.75 } />,
            supportsBulk: true,
            isEligible:   ( item ) => item.status === 'pending',
            callback: ( items ) => {
                const targets = items.filter( ( i ) => i.status === 'pending' );
                if ( ! targets.length ) return;
                const n = targets.length;
                const message = n === 1
                    ? __( 'Mark this donation as paid? A receipt will be sent.', 'dono' )
                    : sprintf(
                        /* translators: %d: donation count */
                        _n(
                            'Mark %d donation as paid? Receipts will be sent.',
                            'Mark %d donations as paid? Receipts will be sent.',
                            n,
                            'dono'
                        ),
                        n
                    );
                setConfirm( {
                    title:        __( 'Mark donations as paid', 'dono' ),
                    message,
                    confirmLabel: __( 'Mark as paid', 'dono' ),
                    onConfirm: async () => {
                        try {
                            await Promise.all( targets.map( ( i ) => apiFetch( {
                                path:   `/dono/v1/admin/donations/${ encodeURIComponent( i.reference ) }/mark-paid`,
                                method: 'POST',
                            } ) ) );
                            refetch();
                        } catch ( err ) {
                            notify.error( err?.message || __( 'Could not mark donations paid.', 'dono' ) );
                        }
                    },
                } );
            },
        },
        {
            id:           'resend-receipt',
            label:        __( 'Resend receipt', 'dono' ),
            icon:         () => <MailIcon size={ 16 } strokeWidth={ 1.75 } />,
            supportsBulk: true,
            isEligible:   ( item ) => item.status === 'paid',
            callback: ( items ) => {
                const targets = items.filter( ( i ) => i.status === 'paid' );
                if ( ! targets.length ) return;
                const n = targets.length;
                const message = n === 1
                    ? __( 'Resend the receipt for this donation?', 'dono' )
                    : sprintf(
                        /* translators: %d: donation count */
                        _n( 'Resend receipts for %d donation?', 'Resend receipts for %d donations?', n, 'dono' ),
                        n
                    );
                setConfirm( {
                    title:        __( 'Resend receipts', 'dono' ),
                    message,
                    confirmLabel: __( 'Resend', 'dono' ),
                    onConfirm: async () => {
                        try {
                            await Promise.all( targets.map( ( i ) => apiFetch( {
                                path:   `/dono/v1/admin/donations/${ encodeURIComponent( i.reference ) }/resend-receipt`,
                                method: 'POST',
                            } ) ) );
                        } catch ( err ) {
                            notify.error( err?.message || __( 'Could not resend receipts.', 'dono' ) );
                        }
                    },
                } );
            },
        },
    ], [ refetch ] );

    return (
        <div className="dono-dataviews dp-donations-dv">
            { error && (
                <Notice status="error" isDismissible={ false }>{ error }</Notice>
            ) }
            <DataViews
                data={ data }
                isLoading={ loading }
                fields={ fields }
                view={ view }
                onChangeView={ setView }
                actions={ actions }
                paginationInfo={ paginationInfo }
                defaultLayouts={ { table: {} } }
                getItemId={ ( item ) => String( item.id ) }
                searchLabel={ __( 'Search by reference', 'dono' ) }
            />
            <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
        </div>
    );
}
