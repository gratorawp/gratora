// Subscriptions list: paginated DataViews against /dono/v1/admin/recurring.

import { useState, useEffect, useMemo } from '@wordpress/element';
import { DataViews } from '@wordpress/dataviews';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __, _n, sprintf } from '@wordpress/i18n';

import { RotateCw } from 'lucide-react';

import Btn from '../_shared/components/Btn';
import EmptyState from '../_shared/components/EmptyState';
import KpiStrip from '../_shared/components/KpiStrip';
import Notice from '../_shared/components/Notice';
import StatusBadge from '../_shared/components/StatusBadge';
import { Switch } from '../_shared/components/Switch';
import PlanActionDialog, { actionsFor, dueIn, isTerminal, retryActionFor } from '../_shared/recurring/PlanActions';
import notify from '../_shared/notify';
import { rowLinkProps } from '../_shared/rowLink';
import { formatAmount, formatDate } from '../donations/format';

const STATUS_OPTIONS = [
    { value: 'active',    label: __( 'Active', 'dono-fundraising-platform' ) },
    { value: 'past_due',  label: __( 'Past due', 'dono-fundraising-platform' ) },
    { value: 'paused',    label: __( 'Paused', 'dono-fundraising-platform' ) },
    { value: 'cancelled', label: __( 'Cancelled', 'dono-fundraising-platform' ) },
    { value: 'expired',   label: __( 'Expired', 'dono-fundraising-platform' ) },
];

const INTERVAL_OPTIONS = [
    { value: 'month', label: __( 'Monthly', 'dono-fundraising-platform' ) },
    { value: 'year',  label: __( 'Yearly', 'dono-fundraising-platform' ) },
    { value: 'week',  label: __( 'Weekly', 'dono-fundraising-platform' ) },
];

// A donation carries the cadence the donor chose on the form, not the plan's
// interval pair, so it reads from its own labels.
const FREQUENCY_LABEL = {
    weekly:    __( 'Weekly', 'dono-fundraising-platform' ),
    biweekly:  __( 'Every 2 weeks', 'dono-fundraising-platform' ),
    monthly:   __( 'Monthly', 'dono-fundraising-platform' ),
    quarterly: __( 'Quarterly', 'dono-fundraising-platform' ),
    yearly:    __( 'Yearly', 'dono-fundraising-platform' ),
};

// A view preference, not a setting: it belongs to the person looking at the
// screen, and having it reset on every page load would make it useless for the
// thing it is for, which is watching test plans appear while you make them.
const TEST_PREF = 'dono.subscriptions.includeTest';

const readTestPref = () => {
    try {
        return window.localStorage?.getItem( TEST_PREF ) === '1';
    } catch ( e ) {
        return false;
    }
};
// How many the notice lists before it offers the rest behind a click.
const UNLINKED_PREVIEW = 5;

function intervalLabel( unit, count ) {
    const n = Number( count ) || 1;
    switch ( unit ) {
        case 'week':
            /* translators: %d: number of weeks between charges. */
            return sprintf( _n( '%d week', '%d weeks', n, 'dono-fundraising-platform' ), n );
        case 'year':
            /* translators: %d: number of years between charges. */
            return sprintf( _n( '%d year', '%d years', n, 'dono-fundraising-platform' ), n );
        case 'month':
            /* translators: %d: number of months */
            return sprintf( _n( '%d month', '%d months', n, 'dono-fundraising-platform' ), n );
        default:
            return n > 1 ? `${ n } ${ unit }` : String( unit );
    }
}

// The donor profile owns a plan's full history; this list links there rather
// than building a second detail view of the same thing.
function donorHref( donorId ) {
    return addQueryArgs( window.location.pathname, { page: 'dono-donors' } ) + `#donor/${ donorId }`;
}

// The donation screen is where the retry lives, so each unlinked donation links
// straight to its own record rather than to a list the org has to search.
function donationHref( reference ) {
    return addQueryArgs( window.location.pathname, {
        page: 'dono-donations',
        view: 'detail',
        reference,
    } );
}

// The card counts plans, and the failing filter below returns exactly those
// rows. A donation charged on a schedule that was never created has no plan
// row for any filter here to return, so it is named in the sub line and left
// out of the number, with the notice above as its surface.
//
// That second line counts test donations whichever way the test toggle is set,
// which is why the card's note is worded about subscriptions: it is the number
// and the first line that the toggle moves.
function attentionSub( failing, unlinked ) {
    const declined = sprintf(
        /* translators: %d: number of plans carrying a failed renewal. */
        _n(
            '%d plan the gateway could not collect from',
            '%d plans the gateway could not collect from',
            failing,
            'dono-fundraising-platform'
        ),
        failing
    );

    const noPlan = sprintf(
        /* translators: %d: number of paid recurring donations with no plan. */
        _n(
            '%d paid recurring donation has no plan and is listed above',
            '%d paid recurring donations have no plan and are listed above',
            unlinked.total,
            'dono-fundraising-platform'
        ),
        unlinked.total
    );

    // An unread count is not a zero, so the card says so rather than
    // resolving the unknown half in the org's favour.
    let second = null;
    if ( unlinked.error ) {
        second = __( 'Donations charged with no plan could not be checked', 'dono-fundraising-platform' );
    } else if ( unlinked.total > 0 ) {
        second = noPlan;
    }

    if ( second === null ) {
        return failing > 0 ? declined : __( 'Nothing to chase', 'dono-fundraising-platform' );
    }

    return failing === 0 ? second : (
        <>
            <div>{ declined }</div>
            <div>{ second }</div>
        </>
    );
}

// Said on the card itself rather than once over the strip, so a figure read on
// its own, or screenshotted, carries the disclaimer with it.
function withTestNote( sub, includeTest ) {
    if ( ! includeTest ) return sub;

    const note = __( 'Includes test subscriptions', 'dono-fundraising-platform' );
    if ( ! sub ) return note;

    return (
        <>
            <div>{ sub }</div>
            <div>{ note }</div>
        </>
    );
}

function subscriptionKpis( stats, unlinked, includeTest ) {
    if ( ! stats ) return [];
    const failing = Number( stats.failing_count ) || 0;
    return [
        {
            id:    'mrr',
            label: __( 'Monthly recurring revenue', 'dono-fundraising-platform' ),
            value: formatAmount( stats.mrr_cents ),
            sub:   withTestNote(
                stats.unconverted > 0
                    ? sprintf(
                        /* translators: %d: number of plans with no converted amount. */
                        _n(
                            '%d plan could not be converted and is not counted',
                            '%d plans could not be converted and are not counted',
                            stats.unconverted,
                            'dono-fundraising-platform'
                        ),
                        stats.unconverted
                    )
                    : __( 'Active plans, normalised', 'dono-fundraising-platform' ),
                includeTest
            ),
        },
        {
            id:    'active',
            label: __( 'Active plans', 'dono-fundraising-platform' ),
            value: String( stats.active_count ),
            sub:   withTestNote( null, includeTest ),
        },
        {
            id:    'failing',
            label: __( 'Needs attention', 'dono-fundraising-platform' ),
            value: String( failing ),
            sub:   withTestNote( attentionSub( failing, unlinked ), includeTest ),
        },
        {
            id:    'churn',
            label: __( 'Churn this month', 'dono-fundraising-platform' ),
            value: `${ stats.churn_pct }%`,
            sub:   withTestNote(
                sprintf(
                    /* translators: %d: number of plans cancelled this month. */
                    _n( '%d cancelled', '%d cancelled', stats.churned_this_month, 'dono-fundraising-platform' ),
                    stats.churned_this_month
                ),
                includeTest
            ),
        },
    ];
}

// Money taken on a repeating schedule that the gateway never created: the
// charge landed, the plan did not, and no row in the table below stands for it.
function UnlinkedNotice( { unlinked, showAll, onShowAll, onReload } ) {
    const { total, items, windowDays, canRetry, error } = unlinked;

    if ( error ) {
        return (
            <Notice status="error" isDismissible={ false }>
                <div>
                    { __(
                        'Recurring donations charged with no plan behind them could not be checked, so nothing on this screen rules them out.',
                        'dono-fundraising-platform'
                    ) }
                </div>
                <div className="dono-row__sub">{ error }</div>
                <Btn variant="ghost" size="sm" onClick={ onReload }>
                    { __( 'Check again', 'dono-fundraising-platform' ) }
                </Btn>
            </Notice>
        );
    }

    if ( total === 0 ) return null;

    const shown  = showAll ? items : items.slice( 0, UNLINKED_PREVIEW );
    const hidden = items.length - shown.length;
    const beyond = total - items.length;
    const anyRecorded   = items.some( ( it ) => it.failure_recorded );
    const anyUnrecorded = items.some( ( it ) => ! it.failure_recorded );

    return (
        <Notice status="warning" isDismissible={ false }>
            <div>
                { sprintf(
                    /* translators: %d: number of paid recurring donations with no plan. */
                    _n(
                        '%d recurring donation was charged, but no plan was created for it. Nothing will collect the next payment.',
                        '%d recurring donations were charged, but no plans were created for them. Nothing will collect their next payments.',
                        total,
                        'dono-fundraising-platform'
                    ),
                    total
                ) }
            </div>
            { windowDays > 0 && (
                <div className="dono-row__sub">
                    { sprintf(
                        /* translators: %d: number of days the check looks back over. */
                        _n(
                            'Covers donations paid in the last %d day.',
                            'Covers donations paid in the last %d days.',
                            windowDays,
                            'dono-fundraising-platform'
                        ),
                        windowDays
                    ) }
                </div>
            ) }
            { shown.map( ( it ) => (
                <div key={ it.reference }>
                    <a href={ donationHref( it.reference ) }>{ it.reference }</a>
                    { ' ' }
                    { formatAmount( it.amount_cents, it.currency ) }
                    { ' ' }
                    { FREQUENCY_LABEL[ it.frequency ] || it.frequency }
                    { ' ' }
                    <span className="dono-row__sub">
                        { it.failure_recorded
                            ? __( 'failure recorded', 'dono-fundraising-platform' )
                            : __( 'no failure recorded', 'dono-fundraising-platform' ) }
                    </span>
                </div>
            ) ) }
            { hidden > 0 && (
                <Btn variant="ghost" size="sm" onClick={ onShowAll }>
                    { sprintf(
                        /* translators: %d: number of donations not yet listed. */
                        _n( 'Show %d more', 'Show %d more', hidden, 'dono-fundraising-platform' ),
                        hidden
                    ) }
                </Btn>
            ) }
            { beyond > 0 && showAll && (
                <div>
                    { sprintf(
                        /* translators: %d: donations beyond the ones listed. */
                        _n(
                            '%d more is not listed here.',
                            '%d more are not listed here.',
                            beyond,
                            'dono-fundraising-platform'
                        ),
                        beyond
                    ) }
                </div>
            ) }
            { canRetry && anyRecorded && (
                <div>{ __( 'Open a donation with a recorded failure to create its plan.', 'dono-fundraising-platform' ) }</div>
            ) }
            { ! canRetry && (
                <div>
                    { __(
                        'Creating a plan needs permission to issue refunds, so pass these references to someone who has it.',
                        'dono-fundraising-platform'
                    ) }
                </div>
            ) }
            { anyUnrecorded && (
                <div>
                    { __(
                        'Where no failure was recorded, check the payment provider for a subscription before asking the donor to set one up again.',
                        'dono-fundraising-platform'
                    ) }
                </div>
            ) }
        </Notice>
    );
}

function emptyStateCopy( unlinked, testHidden ) {
    // Said before anything else: plans that exist and are merely filtered out are
    // not an absence, and telling an org there is nothing here sends them to
    // debug an integration that worked.
    if ( testHidden > 0 ) {
        return {
            title: __( 'No live subscriptions', 'dono-fundraising-platform' ),
            // The count and the way to reveal them are in the notice above, so
            // this says what the empty table means rather than repeating them.
            body:  __( 'Nothing here is charging real money yet.', 'dono-fundraising-platform' ),
        };
    }

    if ( unlinked.error ) {
        return {
            title: __( 'No subscriptions to show', 'dono-fundraising-platform' ),
            body:  __(
                'Whether a recurring donation was charged with no plan behind it is unknown, so this is not the whole picture.',
                'dono-fundraising-platform'
            ),
        };
    }

    if ( unlinked.total > 0 ) {
        return {
            title: __( 'No subscriptions were created', 'dono-fundraising-platform' ),
            body:  unlinked.canRetry
                ? __( 'The recurring donations above were charged, but no plan was ever created for them. Open one with a recorded failure to create its plan.', 'dono-fundraising-platform' )
                : __( 'The recurring donations above were charged, but no plan was ever created for them. Creating a plan needs permission to issue refunds.', 'dono-fundraising-platform' ),
        };
    }

    return {
        title: __( 'No subscriptions yet', 'dono-fundraising-platform' ),
        body:  __( 'Recurring plans appear here once a donor sets one up on a form that offers it.', 'dono-fundraising-platform' ),
    };
}

export default function List() {
    const [ view, setView ] = useState( {
        type:    'table',
        perPage: 25,
        page:    1,
        sort:    { field: 'next_payment_at', direction: 'asc' },
        filters: [],
        search:  '',
        fields:  [ 'donor', 'amount', 'status', 'next_payment_at', 'started_at', 'campaign', 'gateway', 'lifetime' ],
    } );

    const [ data, setData ]         = useState( [] );
    const [ total, setTotal ]       = useState( 0 );
    const [ loading, setLoading ]   = useState( false );
    const [ stats, setStats ]       = useState( null );
    const [ fetchError, setError ]  = useState( null );
    const [ gateways, setGateways ] = useState( [] );
    const [ campaigns, setCampaigns ] = useState( [] );
    const [ dialog, setDialog ]     = useState( null );
    const [ unlinked, setUnlinked ] = useState( {
        total:      0,
        items:      [],
        windowDays: 0,
        canRetry:   false,
        error:      null,
    } );
    const [ showAllUnlinked, setShowAllUnlinked ] = useState( false );
    const [ includeTest, setIncludeTest ] = useState( readTestPref );
    const [ testHidden, setTestHidden ]   = useState( 0 );

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

    const toggleTest = ( on ) => {
        setIncludeTest( on );
        try {
            window.localStorage?.setItem( TEST_PREF, on ? '1' : '0' );
        } catch ( e ) { /* private mode: the toggle still works for this visit */ }
        setView( ( v ) => ( { ...v, page: 1 } ) );
    };
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
        include_test: includeTest || undefined,
    } ), [ view, statusFilter, gatewayFilter, campaignFilter, intervalFilter, failingFilter, includeTest ] );

    const load = () => {
        setLoading( true );
        return apiFetch( { path: addQueryArgs( '/dono/v1/admin/recurring', apiParams ), parse: false } )
            .then( async ( res ) => {
                const items = await res.json();
                setData( Array.isArray( items ) ? items : [] );
                setTotal( parseInt( res.headers.get( 'X-WP-Total' ) || '0', 10 ) );
                setTestHidden( parseInt( res.headers.get( 'X-Dono-Test-Hidden' ) || '0', 10 ) );
                setError( null );
            } )
            .catch( ( err ) => {
                setError( err?.message || __( 'Failed to load subscriptions.', 'dono-fundraising-platform' ) );
                setData( [] );
                setTotal( 0 );
                setTestHidden( 0 );
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
                setTestHidden( parseInt( res.headers.get( 'X-Dono-Test-Hidden' ) || '0', 10 ) );
                setError( null );
            } )
            .catch( ( err ) => {
                if ( aborted ) return;
                setError( err?.message || __( 'Failed to load subscriptions.', 'dono-fundraising-platform' ) );
                setData( [] );
                setTotal( 0 );
                setTestHidden( 0 );
            } )
            .finally( () => { if ( ! aborted ) setLoading( false ); } );
        return () => { aborted = true; };
    }, [ apiParams ] );

    // The strip totals the whole book, so the list filters are deliberately not
    // passed. The test toggle is not one of them: it decides what counts as the
    // book, and figures that disagreed with the rows under them would read as a
    // broken integration to an org whose plans are all still in test mode.
    const fetchStats = ( test ) => apiFetch( {
        path: addQueryArgs( '/dono/v1/admin/recurring/stats', { include_test: test || undefined } ),
    } );

    const statsError = () => notify.error( __( 'The recurring totals could not be loaded.', 'dono-fundraising-platform' ) );

    const loadStats = () => fetchStats( includeTest ).then( setStats ).catch( statsError );

    // These donations have no plan row, so no filter on this list can reach
    // them; they are fetched on their own and read out above the table. A
    // failure here is kept on screen: a count nobody could take is not a zero.
    const loadUnlinked = () => apiFetch( {
        path: addQueryArgs( '/dono/v1/admin/recurring/unlinked', { limit: 50 } ),
    } )
        .then( ( r ) => setUnlinked( {
            total:      Number( r?.total ) || 0,
            items:      Array.isArray( r?.items ) ? r.items : [],
            windowDays: Number( r?.window_days ) || 0,
            canRetry:   !! r?.can_retry,
            error:      null,
        } ) )
        .catch( ( err ) => setUnlinked( {
            total:      0,
            items:      [],
            windowDays: 0,
            canRetry:   false,
            error:      err?.message || __( 'The check could not be run.', 'dono-fundraising-platform' ),
        } ) );

    // Two flips of the toggle land in whatever order the network decides, and
    // the loser would leave test money on a card carrying no note about it.
    useEffect( () => {
        let aborted = false;
        fetchStats( includeTest )
            .then( ( r ) => { if ( ! aborted ) setStats( r ); } )
            .catch( () => { if ( ! aborted ) statsError(); } );
        return () => { aborted = true; };
    }, [ includeTest ] );

    useEffect( () => { loadUnlinked(); }, [] );

    const fields = useMemo( () => [
        {
            id:    'donor',
            label: __( 'Donor', 'dono-fundraising-platform' ),
            render: ( { item } ) => {
                const d = item.donor;
                if ( ! d ) return <span className="dono-row__sub">-</span>;
                return (
                    <div className="dono-row">
                        <div className="dono-row__body">
                            <span className="dono-ref-cell">
                                <a className="dono-row__link dono-row__link--strong" href={ donorHref( d.id ) } { ...rowLinkProps }>
                                    { d.name || __( '(no name)', 'dono-fundraising-platform' ) }
                                </a>
                                { item.simulated && (
                                    <span
                                        className="dono-pill dono-pill--test"
                                        title={ sprintf(
                                            /* translators: %d: minutes between simulated renewals. */
                                            __( 'Test plan. It renews every %d minutes so a full cycle can be watched, and no money moves.', 'dono-fundraising-platform' ),
                                            item.simulated_cycle_minutes || 0
                                        ) }
                                    >
                                        { __( 'Simulated', 'dono-fundraising-platform' ) }
                                    </span>
                                ) }
                            </span>
                            { d.email && <div className="dono-row__sub dono-row__sub--mono">{ d.email }</div> }
                        </div>
                    </div>
                );
            },
        },
        {
            id:            'amount',
            label:         __( 'Amount', 'dono-fundraising-platform' ),
            enableSorting: true,
            render: ( { item } ) => (
                // Muted once the plan has ended: it describes a charge that will
                // not happen again, and Lifetime beside it says what was taken.
                <span className={ isTerminal( item.status ) ? 'dono-row__sub' : undefined }>
                    { formatAmount( item.amount_cents, item.currency ) }
                    <span className="dono-row__sub"> / { intervalLabel( item.interval_unit, item.interval_count ) }</span>
                </span>
            ),
        },
        {
            id:       'status',
            label:    __( 'Status', 'dono-fundraising-platform' ),
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
                                _n( '%d failure', '%d failures', item.failed_renewals_count, 'dono-fundraising-platform' ),
                                item.failed_renewals_count
                            ) }
                        </span>
                    ) }
                </>
            ),
        },
        {
            id:            'next_payment_at',
            label:         __( 'Next charge', 'dono-fundraising-platform' ),
            enableSorting: true,
            render: ( { item } ) => (
                isTerminal( item.status )
                    ? (
                        <span className="dono-row__sub">
                            { item.cancelled_at
                                ? sprintf(
                                    /* translators: %s: date the plan ended. */
                                    __( 'Ended %s', 'dono-fundraising-platform' ),
                                    formatDate( item.cancelled_at )
                                )
                                : __( 'Ended', 'dono-fundraising-platform' ) }
                        </span>
                    )
                    : item.status === 'paused' && item.resume_at
                        ? (
                            <div className="dono-row">
                                <div className="dono-row__name">{ formatDate( item.resume_at ) }</div>
                                <div className="dono-row__sub">{ __( 'when it resumes', 'dono-fundraising-platform' ) }</div>
                            </div>
                        )
                    : (
                        <div className="dono-row">
                            <div className="dono-row__name">{ formatDate( item.next_payment_at ) }</div>
                            { item.next_payment_at && <div className="dono-row__sub">{ dueIn( item.next_payment_at ) }</div> }
                        </div>
                    )
            ),
        },
        {
            id:            'started_at',
            label:         __( 'Giving since', 'dono-fundraising-platform' ),
            enableSorting: true,
            render: ( { item } ) => (
                item.started_at
                    ? <span>{ formatDate( item.started_at ) }</span>
                    : <span className="dono-row__sub">-</span>
            ),
        },
        {            id:       'campaign',
            label:    __( 'Campaign', 'dono-fundraising-platform' ),
            elements: campaigns.map( ( c ) => ( { value: String( c.id ), label: c.title || `#${ c.id }` } ) ),
            filterBy: { operators: [ 'is' ] },
            render: ( { item } ) => (
                item.campaign
                    ? <span>{ item.campaign.title }</span>
                    : <span className="dono-row__sub">-</span>
            ),
        },
        {
            id:       'gateway',
            label:    __( 'Gateway', 'dono-fundraising-platform' ),
            elements: gateways,
            filterBy: { operators: [ 'is' ] },
            render: ( { item } ) => (
                <span style={ { textTransform: 'capitalize' } }>{ item.gateway }</span>
            ),
        },
        {
            id:       'failing',
            label:    __( 'Renewal health', 'dono-fundraising-platform' ),
            elements: [
                { value: 'yes', label: __( 'Has failed renewals', 'dono-fundraising-platform' ) },
            ],
            filterBy: { operators: [ 'is' ] },
            render: ( { item } ) => (
                item.failed_renewals_count > 0
                    ? <span className="dono-pill is-warn">{ sprintf(
                        /* translators: %d: consecutive failed renewals. */
                        _n( '%d failure', '%d failures', item.failed_renewals_count, 'dono-fundraising-platform' ),
                        item.failed_renewals_count
                    ) }</span>
                    : <span className="dono-row__sub">{ __( 'OK', 'dono-fundraising-platform' ) }</span>
            ),
        },
        {
            id:       'interval',
            label:    __( 'Interval', 'dono-fundraising-platform' ),
            elements: INTERVAL_OPTIONS,
            filterBy: { operators: [ 'is' ] },
            render: ( { item } ) => <span>{ intervalLabel( item.interval_unit, item.interval_count ) }</span>,
        },
        {
            id:            'lifetime',
            label:         __( 'Lifetime', 'dono-fundraising-platform' ),
            enableSorting: true,
            render: ( { item } ) => (
                <div className="dono-row">
                    <div className="dono-row__name">{ formatAmount( item.total_paid_cents, item.currency ) }</div>
                    <div className="dono-row__sub">
                        { sprintf(
                            /* translators: %d: number of payments taken so far. */
                            _n( '%d payment', '%d payments', item.payments_count, 'dono-fundraising-platform' ),
                            item.payments_count
                        ) }
                    </div>
                </div>
            ),
        },
    ], [ gateways, campaigns ] );

    const actions = useMemo( () => [
        {
            id:          'copy_subscription_id',
            label:       __( 'Copy subscription id', 'dono-fundraising-platform' ),
            isPrimary:   false,
            isEligible:  ( item ) => !! item.gateway_subscription_id,
            callback:    async ( [ item ] ) => {
                try {
                    await window.navigator?.clipboard?.writeText( item.gateway_subscription_id );
                    notify.success( __( 'Subscription id copied.', 'dono-fundraising-platform' ) );
                } catch ( e ) {
                    // No clipboard permission, so show it instead of failing
                    // silently: it is a lookup key and reading it is the point.
                    notify.error( item.gateway_subscription_id );
                }
            },
        },
        {
            id:    'retry',
            label: __( 'Retry payment', 'dono-fundraising-platform' ),
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
            label:    __( 'Pause', 'dono-fundraising-platform' ),
            isEligible: ( item ) => actionsFor( item ).some( ( a ) => a.id === 'pause' ),
            callback: ( items ) => setDialog( { plan: items[ 0 ], action: 'pause' } ),
        },
        {
            id:       'resume',
            label:    __( 'Resume', 'dono-fundraising-platform' ),
            isEligible: ( item ) => actionsFor( item ).some( ( a ) => a.id === 'resume' ),
            callback: ( items ) => setDialog( { plan: items[ 0 ], action: 'resume' } ),
        },
        {
            id:       'skip_next',
            label:    __( 'Skip next', 'dono-fundraising-platform' ),
            isEligible: ( item ) => actionsFor( item ).some( ( a ) => a.id === 'skip_next' ),
            callback: ( items ) => setDialog( { plan: items[ 0 ], action: 'skip_next' } ),
        },
        {
            id:       'change_amount',
            label:    __( 'Change amount', 'dono-fundraising-platform' ),
            isEligible: ( item ) => ! isTerminal( item.status ),
            callback: ( items ) => setDialog( { plan: items[ 0 ], action: 'change_amount' } ),
        },
        {
            id:            'cancel',
            label:         __( 'Cancel', 'dono-fundraising-platform' ),
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
                <a href={ addQueryArgs( window.location.pathname, { page: 'dono-fundraising-platform' } ) }>{ __( 'Dono', 'dono-fundraising-platform' ) }</a>
                <span className="sep">›</span>
                <span>{ __( 'Subscriptions', 'dono-fundraising-platform' ) }</span>
            </div>
            <div className="dono-page-head">
                <div className="dono-page-head__title-row">
                    <h1>{ __( 'Subscriptions', 'dono-fundraising-platform' ) }</h1>
                </div>
                <div className="dono-page-head__right">
                    { /* Offered once there is something to reveal, or while it
                         is on and needs turning off. An org sets recurring up
                         entirely in test mode, and a screen that hides every
                         plan it made reads as a broken integration. */ }
                    { ( testHidden > 0 || includeTest ) && (
                        <label className="dono-inline-toggle">
                            <Switch
                                checked={ includeTest }
                                onChange={ () => toggleTest( ! includeTest ) }
                                label={ __( 'Show test subscriptions', 'dono-fundraising-platform' ) }
                            />
                            <span>{ __( 'Show test subscriptions', 'dono-fundraising-platform' ) }</span>
                        </label>
                    ) }
                    <span className="dono-page-head__meta">
                        { sprintf(
                            /* translators: %s: number of recurring plans. */
                            _n( '%s plan', '%s plans', total, 'dono-fundraising-platform' ),
                            total.toLocaleString()
                        ) }
                    </span>
                </div>
            </div>

            { testHidden > 0 && ! includeTest && (
                <Notice status="info" isDismissible={ false }>
                    { sprintf(
                        /* translators: %d: number of test subscriptions hidden. */
                        _n(
                            '%d test subscription is hidden.',
                            '%d test subscriptions are hidden.',
                            testHidden,
                            'dono-fundraising-platform'
                        ),
                        testHidden
                    ) }
                    { ' ' }
                    <Btn variant="link" onClick={ () => toggleTest( true ) }>
                        { __( 'Show them', 'dono-fundraising-platform' ) }
                    </Btn>
                </Notice>
            ) }
            <UnlinkedNotice
                unlinked={ unlinked }
                showAll={ showAllUnlinked }
                onShowAll={ () => setShowAllUnlinked( true ) }
                onReload={ loadUnlinked }
            />

            <KpiStrip items={ subscriptionKpis( stats, unlinked, includeTest ) } loading={ ! stats } />

            { fetchError && (
                <Notice status="error" isDismissible={ false }>{ fetchError }</Notice>
            ) }

            { ! loading && total === 0 && ! fetchError ? (
                <EmptyState { ...emptyStateCopy( unlinked, testHidden ) } />
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
