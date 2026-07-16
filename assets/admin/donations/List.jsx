// Donations list: paginated DataViews against /dono/v1/admin/donations.

import { useState, useEffect, useMemo, useCallback } from '@wordpress/element';
import { DataViews } from '@wordpress/dataviews';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Mail as MailIcon, Check as CheckIcon, Coins } from 'lucide-react';

import Btn from '../_shared/components/Btn';
import DateField from '../_shared/components/DateField';
import EmptyState from '../_shared/components/EmptyState';
import ConfirmDialog from '../_shared/components/ConfirmDialog';
import KpiStrip from '../_shared/components/KpiStrip';
import StatusBadge from '../_shared/components/StatusBadge';
import { formatAmount, formatDate, STATUS_LABEL } from './format';
import { timeAgo, detailHref as campaignDetailHref, formEditorHref } from '../_shared/format';

const STATUS_OPTIONS = Object.entries( STATUS_LABEL ).map( ( [ value, label ] ) => ( {
    value,
    label,
} ) );

function detailHref( reference ) {
    return addQueryArgs( window.location.pathname, {
        page:      'dono-donations',
        view:      'detail',
        reference,
    } );
}

export default function List() {
    const [ view, setView ] = useState( {
        type:    'table',
        perPage: 25,
        page:    1,
        sort:    { field: 'created_at', direction: 'desc' },
        filters: [],
        search:  '',
        fields:  [ 'reference', 'status', 'donor', 'amount', 'gateway', 'campaign', 'form', 'created_at' ],
        // is_test column is opt-in via column picker; the badge appears on the
        // detail page regardless, so hiding it by default keeps the list lean.
    } );

    const [ data, setData ]       = useState( [] );
    const [ total, setTotal ]     = useState( 0 );
    const [ loading, setLoading ] = useState( false );
    const [ exporting, setExporting ] = useState( false );
    const [ actionError, setActionError ] = useState( null );
    const [ fetchError, setFetchError ]   = useState( null );
    const [ createdFrom, setCreatedFrom ] = useState( '' );
    const [ createdTo,   setCreatedTo ]   = useState( '' );
    const [ stats, setStats ]     = useState( null );
    const [ campaigns, setCampaigns ] = useState( [] );
    // Pending confirm dialog. Shape: { title, message, confirmLabel, isDestructive, onConfirm }.
    const [ confirm, setConfirm ] = useState( null );

    // Campaign list for the campaign filter dropdown. Forms could follow the
    // same pattern, but they typically run into the hundreds per org and
    // aren't worth front-loading here; the donor portal scopes by donor_id.
    useEffect( () => {
        let aborted = false;
        apiFetch( { path: addQueryArgs( '/dono/v1/admin/campaigns', { per_page: 100 } ) } )
            .then( ( res ) => { if ( ! aborted ) setCampaigns( Array.isArray( res ) ? res : [] ); } )
            .catch( () => { if ( ! aborted ) setCampaigns( [] ); } );
        return () => { aborted = true; };
    }, [] );

    const filterValue = ( field ) => view.filters?.find( ( f ) => f.field === field )?.value;
    const statusFilter   = filterValue( 'status' );
    const gatewayFilter  = filterValue( 'gateway' );
    const campaignFilter = filterValue( 'campaign' );
    const testFilter     = filterValue( 'is_test' );

    const apiParams = useMemo( () => ( {
        page:         view.page,
        per_page:     view.perPage,
        orderby:      view.sort?.field === 'amount' ? 'amount_cents' : ( view.sort?.field || 'created_at' ),
        order:        view.sort?.direction || 'desc',
        search:       view.search || undefined,
        status:       statusFilter || undefined,
        gateway:      gatewayFilter || undefined,
        campaign_id:  campaignFilter || undefined,
        is_test:      testFilter === 'yes' ? true : ( testFilter === 'no' ? false : undefined ),
        created_from: createdFrom || undefined,
        created_to:   createdTo   || undefined,
    } ), [ view, statusFilter, gatewayFilter, campaignFilter, testFilter, createdFrom, createdTo ] );

    useEffect( () => {
        let aborted = false;
        setLoading( true );

        setFetchError( null );
        apiFetch( {
            path:  addQueryArgs( '/dono/v1/admin/donations', apiParams ),
            parse: false,
        } )
            .then( async ( res ) => {
                if ( aborted ) return;
                const items = await res.json();
                setData( Array.isArray( items ) ? items : [] );
                setTotal( parseInt( res.headers.get( 'X-WP-Total' ) || '0', 10 ) );
            } )
            .catch( ( err ) => {
                if ( aborted ) return;
                setFetchError( err?.message || __( 'Failed to load donations.', 'dono' ) );
                setData( [] );
                setTotal( 0 );
            } )
            .finally( () => ! aborted && setLoading( false ) );

        // Stats use the filter shape (not pagination), so strip those keys.
        const statsParams = { ...apiParams };
        delete statsParams.page;
        delete statsParams.per_page;
        delete statsParams.orderby;
        delete statsParams.order;
        apiFetch( { path: addQueryArgs( '/dono/v1/admin/donations/stats', statsParams ) } )
            .then( ( res ) => { if ( ! aborted ) setStats( res || null ); } )
            .catch( () => { if ( ! aborted ) setStats( null ); } );

        return () => {
            aborted = true;
        };
    }, [ apiParams ] );

    const fields = useMemo( () => [
        {
            id:            'reference',
            label:         __( 'Reference', 'dono' ),
            enableSorting: true,
            render: ( { item } ) => (
                <a className="dono-mono-link" href={ detailHref( item.reference ) }>
                    { item.reference }
                </a>
            ),
        },
        {
            id:    'donor',
            label: __( 'Donor', 'dono' ),
            render: ( { item } ) => {
                const d = item.donor;
                if ( ! d ) return <span className="dono-row__sub">-</span>;
                const name = d.name || __( '(no name)', 'dono' );
                return (
                    <div className="dono-row">
                        <div className="dono-row__body">
                            <div className="dono-row__name">{ name }</div>
                            { d.email && <div className="dono-row__sub dono-row__sub--mono">{ d.email }</div> }
                        </div>
                    </div>
                );
            },
        },
        {
            id:            'amount',
            label:         __( 'Amount', 'dono' ),
            enableSorting: true,
            render: ( { item } ) => {
                const showBase =
                    item.base_amount_cents != null &&
                    item.base_currency &&
                    item.base_currency !== item.currency;
                return (
                    <span className={ `dono-amount${ item.status === 'refunded' ? ' dono-amount--strike' : '' }` }>
                        { formatAmount( item.amount_cents, item.currency ) }
                        { showBase && (
                            <span className="dono-amount__base">
                                { '≈ ' }{ formatAmount( item.base_amount_cents, item.base_currency ) }
                            </span>
                        ) }
                    </span>
                );
            },
        },
        {
            id:            'status',
            label:         __( 'Status', 'dono' ),
            elements:      STATUS_OPTIONS,
            filterBy:      { operators: [ 'is' ] },
            enableSorting: true,
            render:        ( { item } ) => <StatusBadge status={ item.status } />,
        },
        {
            id:     'gateway',
            label:  __( 'Gateway', 'dono' ),
            elements: [
                { value: 'stripe',  label: __( 'Stripe', 'dono' ) },
                { value: 'offline', label: __( 'Offline', 'dono' ) },
            ],
            filterBy: { operators: [ 'is' ] },
            render: ( { item } ) => (
                <span style={ { textTransform: 'capitalize' } }>{ item.gateway || '-' }</span>
            ),
        },
        {
            id:       'campaign',
            label:    __( 'Campaign', 'dono' ),
            elements: campaigns.map( ( c ) => ( { value: String( c.id ), label: c.title || `#${ c.id }` } ) ),
            filterBy: { operators: [ 'is' ] },
            render: ( { item } ) => (
                item.campaign?.title
                    ? <a className="dono-row__link" href={ campaignDetailHref( item.campaign.id ) }>{ item.campaign.title }</a>
                    : <span className="dono-row__sub">-</span>
            ),
        },
        {
            id:       'is_test',
            label:    __( 'Test mode', 'dono' ),
            elements: [
                { value: 'yes', label: __( 'Test only', 'dono' ) },
                { value: 'no',  label: __( 'Live only', 'dono' ) },
            ],
            filterBy:    { operators: [ 'is' ] },
            getValue:    ( { item } ) => ( item.is_test ? 'yes' : 'no' ),
            render:      ( { item } ) => item.is_test
                ? <span className="dono-pill dono-pill--warning">{ __( 'Test', 'dono' ) }</span>
                : <span className="dono-row__sub">-</span>,
        },
        {
            id:     'form',
            label:  __( 'Form', 'dono' ),
            render: ( { item } ) => (
                item.form?.title
                    ? <a className="dono-row__link" href={ formEditorHref( item.form.id ) }>{ item.form.title }</a>
                    : <span className="dono-row__sub">-</span>
            ),
        },
        {
            id:            'created_at',
            label:         __( 'Created', 'dono' ),
            enableSorting: true,
            render: ( { item } ) => (
                <span className="dono-time" title={ formatDate( item.created_at ) }>
                    <span className="dono-time__rel">{ timeAgo( item.created_at ) }</span>
                    <span className="dono-time__abs">{ formatDate( item.created_at ) }</span>
                </span>
            ),
        },
    ], [ campaigns ] );

    const paginationInfo = useMemo(
        () => ( {
            totalItems: total,
            totalPages: Math.max( 1, Math.ceil( total / view.perPage ) ),
        } ),
        [ total, view.perPage ]
    );

    const refetch = () => setView( ( v ) => ( { ...v } ) );

    const actions = useMemo( () => [
        {
            id:           'mark-paid',
            label:        __( 'Mark as paid', 'dono' ),
            icon:         () => <CheckIcon size={ 16 } strokeWidth={ 1.75 } />,
            supportsBulk: true,
            // Only pending donations can be flipped to paid; failed ones use
            // the per-row detail action (which captures a reason).
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
                            'Mark %d donation as paid? Receipts will be sent to each donor.',
                            'Mark %d donations as paid? Receipts will be sent to each donor.',
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
                            setActionError( err?.message || __( 'Could not mark one or more donations paid.', 'dono' ) );
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
            // Only paid donations have a receipt to resend.
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
                            setActionError( err?.message || __( 'Could not resend one or more receipts.', 'dono' ) );
                        }
                    },
                } );
            },
        },
    ], [] );

    const doExport = async () => {
        setExporting( true );
        setActionError( null );
        try {
            // The CSV endpoint takes the same filters as the JSON list so the
            // export matches what the admin is currently viewing. `parse:false`
            // gives us the raw Response object - apiFetch otherwise tries to
            // JSON-decode the CSV body.
            const params = { ...apiParams, _wpnonce: window.wpApiSettings?.nonce };
            delete params.page;
            delete params.per_page;
            const res = await fetch(
                addQueryArgs( window.wpApiSettings.root + 'dono/v1/admin/donations/export.csv', params ),
                {
                    credentials: 'include',
                    headers:     { 'X-WP-Nonce': window.wpApiSettings.nonce },
                }
            );
            if ( ! res.ok ) throw new Error( `Export failed (${ res.status })` );
            const blob = await res.blob();
            const url  = URL.createObjectURL( blob );
            const a    = document.createElement( 'a' );
            const ts   = new Date().toISOString().replace( /[:.]/g, '-' ).slice( 0, 19 );
            a.href     = url;
            a.download = `dono-donations-${ ts }.csv`;
            a.click();
            URL.revokeObjectURL( url );
        } catch ( err ) {
            setActionError( err?.message || __( 'Export failed.', 'dono' ) );
        } finally {
            setExporting( false );
        }
    };

    return (
        <div>
            <div className="dono-crumbs">
                <a href={ addQueryArgs( window.location.pathname, { page: 'dono' } ) }>{ __( 'Dono', 'dono' ) }</a>
                <span className="sep">›</span>
                <span>{ __( 'Donations', 'dono' ) }</span>
            </div>
            <div className="dono-page-head">
                <div className="dono-page-head__title-row">
                    <h1>{ __( 'Donations', 'dono' ) }</h1>
                </div>
                <div className="dono-page-head__right">
                    <div className="dono-page-head__date-filters">
                        <span className="dono-page-head__date-filters-label">{ __( 'From', 'dono' ) }</span>
                        <DateField
                            value={ createdFrom }
                            onChange={ ( v ) => setCreatedFrom( v || '' ) }
                            ariaLabel={ __( 'Filter donations from', 'dono' ) }
                            placeholder={ __( 'Any', 'dono' ) }
                        />
                        <span className="dono-page-head__date-filters-label">{ __( 'To', 'dono' ) }</span>
                        <DateField
                            value={ createdTo }
                            onChange={ ( v ) => setCreatedTo( v || '' ) }
                            ariaLabel={ __( 'Filter donations to', 'dono' ) }
                            placeholder={ __( 'Any', 'dono' ) }
                        />
                        { ( createdFrom || createdTo ) && (
                            <button
                                type="button"
                                className="dono-page-head__date-filters-clear"
                                onClick={ () => { setCreatedFrom( '' ); setCreatedTo( '' ); } }
                            >
                                { __( 'Clear', 'dono' ) }
                            </button>
                        ) }
                    </div>
                    <span className="dono-page-head__meta">
                        { sprintf( /* translators: %s: number of donations */ _n( '%s donation', '%s donations', total, 'dono' ), total.toLocaleString() ) }
                    </span>
                    <Btn
                        variant="secondary"
                        onClick={ doExport }
                        disabled={ exporting || total === 0 }
                        isBusy={ exporting }
                    >
                        { exporting ? __( 'Exporting…', 'dono' ) : __( 'Export CSV', 'dono' ) }
                    </Btn>
                </div>
            </div>
            { ( actionError || fetchError ) && (
                <div className="dono-advanced-notice dono-advanced-notice--error" style={ { marginBottom: 12 } }>
                    { actionError || fetchError }
                </div>
            ) }

            <KpiStrip items={ donationKpis( stats ) } loading={ loading && ! stats } />

            { ! loading && total === 0 && ! view.search && ! statusFilter && ! createdFrom && ! createdTo && ! view.filters?.length ? (
                <EmptyState
                    icon={ <Coins size={ 22 } strokeWidth={ 1.75 } /> }
                    title={ __( 'No donations yet', 'dono' ) }
                    body={ __( 'Donations made through your published forms will appear here. Donors are created automatically from each completed donation.', 'dono' ) }
                />
            ) : (
                <div className="dono-dataviews">
                    <DataViews
                        data={ data }
                        isLoading={ loading }
                        fields={ fields }
                        view={ view }
                        onChangeView={ setView }
                        actions={ actions }
                        paginationInfo={ paginationInfo }
                        defaultLayouts={ { table: {}, list: {} } }
                        getItemId={ ( item ) => String( item.id ) }
                    />
                </div>
            ) }

            <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
        </div>
    );
}

function donationKpis( stats ) {
    return [
        {
            label: __( 'Total donations', 'dono' ),
            value: stats ? stats.total_count.toLocaleString() : '-',
        },
        {
            label: __( 'Paid', 'dono' ),
            value: stats ? stats.paid_count.toLocaleString() : '-',
        },
        {
            label: __( 'Raised', 'dono' ),
            value: stats && stats.raised_cents > 0
                ? formatAmount( stats.raised_cents, stats.currency || undefined )
                : '-',
            sub: stats?.currency
                ? sprintf( /* translators: %s: currency code */ __( 'in %s', 'dono' ), stats.currency )
                : null,
        },
        {
            label: __( 'Unique donors', 'dono' ),
            value: stats ? stats.donors_count.toLocaleString() : '-',
        },
    ];
}

