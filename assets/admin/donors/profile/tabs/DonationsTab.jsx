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
    { value: 'paid',           label: __( 'Paid', 'dono-fundraising-platform' ) },
    { value: 'pending',        label: __( 'Pending', 'dono-fundraising-platform' ) },
    { value: 'processing',     label: __( 'Processing', 'dono-fundraising-platform' ) },
    { value: 'failed',         label: __( 'Failed', 'dono-fundraising-platform' ) },
    { value: 'refunded',       label: __( 'Refunded', 'dono-fundraising-platform' ) },
    { value: 'partial_refund', label: __( 'Partial refund', 'dono-fundraising-platform' ) },
    { value: 'disputed',       label: __( 'Disputed', 'dono-fundraising-platform' ) },
];

function donationHref( reference ) {
    return addQueryArgs( window.location.pathname, {
        page:      'dono-donations',
        view:      'detail',
        reference,
    } );
}

export default function DonationsTab( { donorId, redacted } ) {
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
        // This is one donor's own history, not the org's ledger. Hiding their
        // test donations here leaves the tab empty while the Overview card
        // above it lists them, and every row is badged either way.
        include_test: true,
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
            .catch( () => { if ( ! aborted ) { setData( [] ); setTotal( 0 ); setError( __( 'Could not load donations. Refresh to try again.', 'dono-fundraising-platform' ) ); } } )
            .finally( () => { if ( ! aborted ) setLoading( false ); } );
        return () => { aborted = true; };
    }, [ apiParams ] );

    const fields = useMemo( () => [
        {
            id:    'reference',
            label: __( 'Reference', 'dono-fundraising-platform' ),
            render: ( { item } ) => (
                <span className="dono-ref-cell">
                    <a
                        href={ donationHref( item.reference ) }
                        style={ { fontFamily: 'ui-monospace, monospace', fontSize: 12.5, color: '#14693a', textDecoration: 'none' } }
                    >
                        { item.reference }
                    </a>
                    { item.is_test && (
                        <span className="dono-pill dono-pill--test">{ __( 'Test', 'dono-fundraising-platform' ) }</span>
                    ) }
                </span>
            ),
        },
        {
            id:    'amount',
            label: __( 'Amount', 'dono-fundraising-platform' ),
            enableSorting: true,
            render: ( { item } ) => (
                <span style={ { fontVariantNumeric: 'tabular-nums', fontWeight: 500 } }>
                    { formatAmount( item.amount_cents, item.currency ) }
                </span>
            ),
        },
        {
            id:    'status',
            label: __( 'Status', 'dono-fundraising-platform' ),
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
            label: __( 'Frequency', 'dono-fundraising-platform' ),
            render: ( { item } ) => item.frequency === 'one_time'
                ? __( 'One-time', 'dono-fundraising-platform' )
                : <span style={ { textTransform: 'capitalize' } }>{ item.frequency }</span>,
        },
        {
            id:    'campaign',
            label: __( 'Campaign', 'dono-fundraising-platform' ),
            render: ( { item } ) => item.campaign?.title || '-',
        },
        {
            id:    'created_at',
            label: __( 'When', 'dono-fundraising-platform' ),
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
            label:        __( 'Mark as paid', 'dono-fundraising-platform' ),
            icon:         () => <CheckIcon size={ 16 } strokeWidth={ 1.75 } />,
            supportsBulk: true,
            isEligible:   ( item ) => item.status === 'pending' || item.status === 'processing',
            callback: ( items ) => {
                const targets = items.filter( ( i ) => i.status === 'pending' || i.status === 'processing' );
                if ( ! targets.length ) return;
                const n = targets.length;
                const message = n === 1
                    ? __( 'Mark this donation as paid? A receipt will be sent.', 'dono-fundraising-platform' )
                    : sprintf(
                        /* translators: %d: number of donations */
                        _n(
                            'Mark %d donation as paid? Receipts will be sent.',
                            'Mark %d donations as paid? Receipts will be sent.',
                            n,
                            'dono-fundraising-platform'
                        ),
                        n
                    );
                setConfirm( {
                    title:        __( 'Mark donations as paid', 'dono-fundraising-platform' ),
                    message,
                    confirmLabel: __( 'Mark as paid', 'dono-fundraising-platform' ),
                    onConfirm: async () => {
                        // allSettled and a finally: a partial failure still
                        // confirmed some of them and emailed those donors a
                        // receipt, and skipping the refetch left those rows
                        // reading Pending on screen.
                        const results = await Promise.allSettled( targets.map( ( i ) => apiFetch( {
                            path:   `/dono/v1/admin/donations/${ encodeURIComponent( i.reference ) }/mark-paid`,
                            method: 'POST',
                        } ) ) );

                        const done   = results.filter( ( r ) => r.status === 'fulfilled' ).length;
                        const failed = results.length - done;

                        if ( done > 0 ) {
                            notify.success( sprintf(
                                /* translators: %d: number of donations */
                                _n( '%d donation marked paid.', '%d donations marked paid.', done, 'dono-fundraising-platform' ),
                                done
                            ) );
                        }
                        if ( failed > 0 ) {
                            notify.error( sprintf(
                                /* translators: %d: number of donations */
                                _n( '%d donation could not be marked paid.', '%d donations could not be marked paid.', failed, 'dono-fundraising-platform' ),
                                failed
                            ) );
                        }

                        refetch();
                    },
                } );
            },
        },
        {
            id:           'resend-receipt',
            label:        __( 'Resend receipt', 'dono-fundraising-platform' ),
            icon:         () => <MailIcon size={ 16 } strokeWidth={ 1.75 } />,
            supportsBulk: true,
            // An erased donor has no address left to send a receipt to.
            isEligible:   ( item ) => item.status === 'paid' && ! redacted,
            callback: ( items ) => {
                // DataViews hands the callback the whole selection, not the
                // eligible subset, so the redacted guard from isEligible has to
                // be repeated here or a bulk resend tries to email an erased
                // donor who has no address left.
                const targets = items.filter( ( i ) => i.status === 'paid' && ! redacted );
                if ( ! targets.length ) return;
                const n = targets.length;
                const message = n === 1
                    ? __( 'Resend the receipt for this donation?', 'dono-fundraising-platform' )
                    : sprintf(
                        /* translators: %d: number of donations */
                        _n( 'Resend receipts for %d donation?', 'Resend receipts for %d donations?', n, 'dono-fundraising-platform' ),
                        n
                    );
                setConfirm( {
                    title:        __( 'Resend receipts', 'dono-fundraising-platform' ),
                    message,
                    confirmLabel: __( 'Resend', 'dono-fundraising-platform' ),
                    onConfirm: async () => {
                        // Silence read as nothing happening, so admins pressed
                        // it again and donors got the receipt twice.
                        const results = await Promise.allSettled( targets.map( ( i ) => apiFetch( {
                            path:   `/dono/v1/admin/donations/${ encodeURIComponent( i.reference ) }/resend-receipt`,
                            method: 'POST',
                        } ) ) );

                        const sent   = results.filter( ( r ) => r.status === 'fulfilled' ).length;
                        const failed = results.length - sent;

                        if ( sent > 0 ) {
                            notify.success( sprintf(
                                /* translators: %d: receipt count */
                                _n( '%d receipt resent.', '%d receipts resent.', sent, 'dono-fundraising-platform' ),
                                sent
                            ) );
                        }
                        if ( failed > 0 ) {
                            notify.error( sprintf(
                                /* translators: %d: receipt count */
                                _n( '%d receipt could not be resent.', '%d receipts could not be resent.', failed, 'dono-fundraising-platform' ),
                                failed
                            ) );
                        }
                    },
                } );
            },
        },
    ], [ refetch, redacted ] );

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
                searchLabel={ __( 'Search by reference', 'dono-fundraising-platform' ) }
            />
            <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
        </div>
    );
}
