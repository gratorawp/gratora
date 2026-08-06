// Subscriptions list: paginated DataViews against /dono/v1/admin/recurring.

import { useState, useEffect, useMemo } from '@wordpress/element';
import { DataViews } from '@wordpress/dataviews';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __, _n, sprintf } from '@wordpress/i18n';

import { RotateCw } from 'lucide-react';

import EmptyState from '../_shared/components/EmptyState';
import KpiStrip from '../_shared/components/KpiStrip';
import StatusBadge from '../_shared/components/StatusBadge';
import PlanActionDialog, { actionsFor, dueIn, isTerminal, retryActionFor } from '../_shared/recurring/PlanActions';
import notify from '../_shared/notify';
import { rowLinkProps } from '../_shared/rowLink';
import { formatAmount, formatDate } from '../donations/format';

const STATUS_OPTIONS = [
    { value: 'active',    label: __( 'Active', 'dono' ) },
    { value: 'past_due',  label: __( 'Past due', 'dono' ) },
    { value: 'paused',    label: __( 'Paused', 'dono' ) },
    { value: 'cancelled', label: __( 'Cancelled', 'dono' ) },
    { value: 'expired',   label: __( 'Expired', 'dono' ) },
];

const INTERVAL_OPTIONS = [
    { value: 'month', label: __( 'Monthly', 'dono' ) },
    { value: 'year',  label: __( 'Yearly', 'dono' ) },
    { value: 'week',  label: __( 'Weekly', 'dono' ) },
];

function intervalLabel( unit, count ) {
    const n = Number( count ) || 1;
    switch ( unit ) {
        case 'week':
            /* translators: %d: number of weeks between charges. */
            return sprintf( _n( '%d week', '%d weeks', n, 'dono' ), n );
        case 'year':
            /* translators: %d: number of years between charges. */
            return sprintf( _n( '%d year', '%d years', n, 'dono' ), n );
        case 'month':
            /* translators: %d: number of months between charges. */
            return sprintf( _n( '%d month', '%d months', n, 'dono' ), n );
        default:
            return n > 1 ? `${ n } ${ unit }` : String( unit );
    }
}

// The donor profile owns a plan's full history; this list links there rather
// than building a second detail view of the same thing.
function donorHref( donorId ) {
    return addQueryArgs( window.location.pathname, { page: 'dono-donors' } ) + `#donor/${ donorId }`;
}

function subscriptionKpis( stats ) {
    if ( ! stats ) return [];
    return [
        {
            id:    'mrr',
            label: __( 'Monthly recurring revenue', 'dono' ),
            value: formatAmount( stats.mrr_cents ),
            sub:   stats.unconverted > 0
                ? sprintf(
                    /* translators: %d: number of plans with no converted amount. */
                    _n(
                        '%d plan could not be converted and is not counted',
                        '%d plans could not be converted and are not counted',
                        stats.unconverted,
                        'dono'
                    ),
                    stats.unconverted
                )
                : __( 'Active plans, normalised', 'dono' ),
        },
        { id: 'active',  label: __( 'Active plans', 'dono' ),   value: String( stats.active_count ) },
        {
            id:    'failing',
            label: __( 'Needs attention', 'dono' ),
            value: String( stats.failing_count ),
            sub:   stats.failing_count > 0
                ? __( 'Renewals the gateway could not collect', 'dono' )
                : __( 'No failed renewals', 'dono' ),
        },
        {
            id:    'churn',
            label: __( 'Churn this month', 'dono' ),
            value: `${ stats.churn_pct }%`,
            sub:   sprintf(
                /* translators: %d: number of plans cancelled this month. */
                _n( '%d cancelled', '%d cancelled', stats.churned_this_month, 'dono' ),
                stats.churned_this_month
            ),
        },
    ];
}

export default function List() {
    const [ view, setView ] = useState( {
        type:    'table',
        perPage: 25,
        page:    1,
        sort:    { field: 'next_payment_at', direction: 'asc' },
        filters: [],
        search:  '',
        fields:  [ 'donor', 'amount', 'status', 'next_payment_at', 'campaign', 'gateway', 'lifetime' ],
    } );

    const [ data, setData ]         = useState( [] );
    const [ total, setTotal ]       = useState( 0 );
    const [ loading, setLoading ]   = useState( false );
    const [ stats, setStats ]       = useState( null );
    const [ fetchError, setError ]  = useState( null );
    const [ gateways, setGateways ] = useState( [] );
    const [ campaigns, setCampaigns ] = useState( [] );
    const [ dialog, setDialog ]     = useState( null );

    useEffect( () => {
        let aborted = false;
        apiFetch( { path: '/dono/v1/admin/recurring/gateway-options' } )
            .then( ( r ) => { if ( ! aborted ) setGateways( Array.isArray( r ) ? r : [] ); } )
            .catch( () => { if ( ! aborted ) setGateways( [] ); } );
        // Same route the donations list uses: /admin/campaigns needs a
        // capability this screen does not, and would 403 into an empty filter.
        apiFetch( { path: '/dono/v1/admin/donations/campaign-options' } )
            .then( ( r ) => { if ( ! aborted ) setCampaigns( Array.isArray( r ) ? r : [] ); } )
            .catch( () => { if ( ! aborted ) setCampaigns( [] ); } );
        return () => { aborted = true; };
    }, [] );

    const filterValue = ( field ) => view.filters?.find( ( f ) => f.field === field )?.value;
    const statusFilter   = filterValue( 'status' );
    const gatewayFilter  = filterValue( 'gateway' );
    const campaignFilter = filterValue( 'campaign' );
    const intervalFilter = filterValue( 'interval' );
    const failingFilter  = filterValue( 'failing' );

    const apiParams = useMemo( () => ( {
        page:        view.page,
        per_page:    view.perPage,
        orderby:     view.sort?.field || 'next_payment_at',
        order:       view.sort?.direction || 'asc',
        status:      statusFilter || undefined,
        gateway:     gatewayFilter || undefined,
        campaign_id: campaignFilter || undefined,
        interval:    intervalFilter || undefined,
        failing:     failingFilter === 'yes' ? true : undefined,
        search:      view.search || undefined,
    } ), [ view, statusFilter, gatewayFilter, campaignFilter, intervalFilter, failingFilter ] );

    const load = () => {
        setLoading( true );
        return apiFetch( { path: addQueryArgs( '/dono/v1/admin/recurring', apiParams ), parse: false } )
            .then( async ( res ) => {
                const items = await res.json();
                setData( Array.isArray( items ) ? items : [] );
                setTotal( parseInt( res.headers.get( 'X-WP-Total' ) || '0', 10 ) );
                setError( null );
            } )
            .catch( ( err ) => {
                setError( err?.message || __( 'Failed to load subscriptions.', 'dono' ) );
                setData( [] );
                setTotal( 0 );
            } )
            .finally( () => setLoading( false ) );
    };

    useEffect( () => {
        let aborted = false;
        setLoading( true );
        apiFetch( { path: addQueryArgs( '/dono/v1/admin/recurring', apiParams ), parse: false } )
            .then( async ( res ) => {
                if ( aborted ) return;
                const items = await res.json();
                setData( Array.isArray( items ) ? items : [] );
                setTotal( parseInt( res.headers.get( 'X-WP-Total' ) || '0', 10 ) );
                setError( null );
            } )
            .catch( ( err ) => {
                if ( aborted ) return;
                setError( err?.message || __( 'Failed to load subscriptions.', 'dono' ) );
                setData( [] );
                setTotal( 0 );
            } )
            .finally( () => { if ( ! aborted ) setLoading( false ); } );
        return () => { aborted = true; };
    }, [ apiParams ] );

    // The strip totals the whole book, so it is deliberately not filtered.
    const loadStats = () => apiFetch( { path: '/dono/v1/admin/recurring/stats' } )
        .then( setStats )
        .catch( () => notify.error( __( 'The recurring totals could not be loaded.', 'dono' ) ) );

    useEffect( () => { loadStats(); }, [] );

    const fields = useMemo( () => [
        {
            id:    'donor',
            label: __( 'Donor', 'dono' ),
            render: ( { item } ) => {
                const d = item.donor;
                if ( ! d ) return <span className="dono-row__sub">-</span>;
                return (
                    <div className="dono-row">
                        <div className="dono-row__body">
                            <a className="dono-row__link dono-row__link--strong" href={ donorHref( d.id ) } { ...rowLinkProps }>
                                { d.name || __( '(no name)', 'dono' ) }
                            </a>
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
            render: ( { item } ) => (
                <span>
                    { formatAmount( item.amount_cents, item.currency ) }
                    <span className="dono-row__sub"> / { intervalLabel( item.interval_unit, item.interval_count ) }</span>
                </span>
            ),
        },
        {
            id:       'status',
            label:    __( 'Status', 'dono' ),
            elements: STATUS_OPTIONS,
            filterBy: { operators: [ 'is' ] },
            enableSorting: true,
            render: ( { item } ) => (
                <>
                    <StatusBadge status={ item.status } />
                    { item.failed_renewals_count > 0 && (
                        <span className="dono-row__sub" style={ { marginLeft: 6 } }>
                            { sprintf(
                                /* translators: %d: consecutive failed renewals. */
                                _n( '%d failure', '%d failures', item.failed_renewals_count, 'dono' ),
                                item.failed_renewals_count
                            ) }
                        </span>
                    ) }
                </>
            ),
        },
        {
            id:            'next_payment_at',
            label:         __( 'Next charge', 'dono' ),
            enableSorting: true,
            render: ( { item } ) => (
                isTerminal( item.status )
                    ? <span className="dono-row__sub">-</span>
                    : (
                        <div className="dono-row">
                            <div className="dono-row__name">{ formatDate( item.next_payment_at ) }</div>
                            { item.next_payment_at && <div className="dono-row__sub">{ dueIn( item.next_payment_at ) }</div> }
                        </div>
                    )
            ),
        },
        {
            id:       'campaign',
            label:    __( 'Campaign', 'dono' ),
            elements: campaigns,
            filterBy: { operators: [ 'is' ] },
            render: ( { item } ) => (
                item.campaign
                    ? <span>{ item.campaign.title }</span>
                    : <span className="dono-row__sub">-</span>
            ),
        },
        {
            id:       'gateway',
            label:    __( 'Gateway', 'dono' ),
            elements: gateways,
            filterBy: { operators: [ 'is' ] },
            render: ( { item } ) => (
                <div className="dono-row">
                    <div className="dono-row__name" style={ { textTransform: 'capitalize' } }>{ item.gateway }</div>
                    <code className="dono-row__sub dono-row__sub--mono">{ item.gateway_subscription_id }</code>
                </div>
            ),
        },
        {
            id:       'failing',
            label:    __( 'Renewal health', 'dono' ),
            elements: [
                { value: 'yes', label: __( 'Has failed renewals', 'dono' ) },
            ],
            filterBy: { operators: [ 'is' ] },
            render: ( { item } ) => (
                item.failed_renewals_count > 0
                    ? <span className="dono-pill is-warn">{ sprintf(
                        /* translators: %d: consecutive failed renewals. */
                        _n( '%d failure', '%d failures', item.failed_renewals_count, 'dono' ),
                        item.failed_renewals_count
                    ) }</span>
                    : <span className="dono-row__sub">{ __( 'OK', 'dono' ) }</span>
            ),
        },
        {
            id:       'interval',
            label:    __( 'Interval', 'dono' ),
            elements: INTERVAL_OPTIONS,
            filterBy: { operators: [ 'is' ] },
            render: ( { item } ) => <span>{ intervalLabel( item.interval_unit, item.interval_count ) }</span>,
        },
        {
            id:            'lifetime',
            label:         __( 'Lifetime', 'dono' ),
            enableSorting: true,
            render: ( { item } ) => (
                <div className="dono-row">
                    <div className="dono-row__name">{ formatAmount( item.total_paid_cents, item.currency ) }</div>
                    <div className="dono-row__sub">
                        { sprintf(
                            /* translators: %d: number of payments taken so far. */
                            _n( '%d payment', '%d payments', item.payments_count, 'dono' ),
                            item.payments_count
                        ) }
                    </div>
                </div>
            ),
        },
    ], [ gateways, campaigns ] );

    const actions = useMemo( () => [
        {
            id:    'retry',
            label: __( 'Retry payment', 'dono' ),
            // DataViews draws a primary action as an icon button, so one with
            // no icon renders as nothing at all -- and being primary, it is
            // left out of the row menu too, taking the action out of reach.
            isPrimary:  true,
            icon:       () => <RotateCw size={ 16 } strokeWidth={ 1.75 } />,
            isEligible: ( item ) => !! retryActionFor( item ),
            callback:   ( items ) => setDialog( { plan: items[ 0 ], action: 'retry' } ),
        },
        {
            id:       'pause',
            label:    __( 'Pause', 'dono' ),
            isEligible: ( item ) => actionsFor( item ).some( ( a ) => a.id === 'pause' ),
            callback: ( items ) => setDialog( { plan: items[ 0 ], action: 'pause' } ),
        },
        {
            id:       'resume',
            label:    __( 'Resume', 'dono' ),
            isEligible: ( item ) => actionsFor( item ).some( ( a ) => a.id === 'resume' ),
            callback: ( items ) => setDialog( { plan: items[ 0 ], action: 'resume' } ),
        },
        {
            id:       'skip_next',
            label:    __( 'Skip next', 'dono' ),
            isEligible: ( item ) => actionsFor( item ).some( ( a ) => a.id === 'skip_next' ),
            callback: ( items ) => setDialog( { plan: items[ 0 ], action: 'skip_next' } ),
        },
        {
            id:       'change_amount',
            label:    __( 'Change amount', 'dono' ),
            isEligible: ( item ) => ! isTerminal( item.status ),
            callback: ( items ) => setDialog( { plan: items[ 0 ], action: 'change_amount' } ),
        },
        {
            id:            'cancel',
            label:         __( 'Cancel', 'dono' ),
            isDestructive: true,
            isEligible: ( item ) => ! isTerminal( item.status ),
            callback: ( items ) => setDialog( { plan: items[ 0 ], action: 'cancel' } ),
        },
    ], [] );

    const paginationInfo = useMemo(
        () => ( { totalItems: total, totalPages: Math.max( 1, Math.ceil( total / view.perPage ) ) } ),
        [ total, view.perPage ]
    );

    return (
        <div className="dono-admin">
            <div className="dono-crumbs">
                <a href={ addQueryArgs( window.location.pathname, { page: 'dono' } ) }>{ __( 'Dono', 'dono' ) }</a>
                <span className="sep">›</span>
                <span>{ __( 'Subscriptions', 'dono' ) }</span>
            </div>
            <div className="dono-page-head">
                <div className="dono-page-head__title-row">
                    <h1>{ __( 'Subscriptions', 'dono' ) }</h1>
                </div>
                <div className="dono-page-head__right">
                    <span className="dono-page-head__meta">
                        { sprintf(
                            /* translators: %s: number of recurring plans. */
                            _n( '%s plan', '%s plans', total, 'dono' ),
                            total.toLocaleString()
                        ) }
                    </span>
                </div>
            </div>

            <KpiStrip items={ subscriptionKpis( stats ) } loading={ ! stats } />

            { fetchError && <div className="dono-error-notice">{ fetchError }</div> }

            { ! loading && total === 0 && ! fetchError ? (
                <EmptyState
                    title={ __( 'No subscriptions yet', 'dono' ) }
                    body={ __( 'Recurring plans appear here once a donor sets one up on a form that offers it.', 'dono' ) }
                />
            ) : (
                // The card chrome the other list screens sit in lives on this
                // wrapper, so without it the table renders bare on the page.
                <div className="dono-dataviews">
                    <DataViews
                        data={ data }
                        fields={ fields }
                        view={ view }
                        onChangeView={ setView }
                        actions={ actions }
                        isLoading={ loading }
                        paginationInfo={ paginationInfo }
                        defaultLayouts={ { table: {} } }
                        getItemId={ ( item ) => String( item.id ) }
                    />
                </div>
            ) }

            { dialog && (
                <PlanActionDialog
                    plan={ dialog.plan }
                    action={ dialog.action }
                    onClose={ () => setDialog( null ) }
                    onDone={ () => { load(); loadStats(); } }
                />
            ) }
        </div>
    );
}
