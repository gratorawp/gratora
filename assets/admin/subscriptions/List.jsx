// Subscriptions list: paginated DataViews against /fundkit/v1/admin/recurring.

import { useState, useEffect, useMemo } from '@wordpress/element';
import { DataViews } from '@wordpress/dataviews';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __, _n, sprintf } from '@wordpress/i18n';

import { RotateCw, SearchX } from 'lucide-react';

import Btn from '../_shared/components/Btn';
import { useTableView } from '../_shared/useTableView';
import EmptyState from '../_shared/components/EmptyState';
import { isViewFiltered, clearedView } from '../_shared/viewFilters';
import KpiStrip from '../_shared/components/KpiStrip';
import Notice from '../_shared/components/Notice';
import StatusBadge from '../_shared/components/StatusBadge';
import { Switch } from '../_shared/components/Switch';
import PlanActionDialog, { actionsFor, dueIn, isTerminal, retryActionFor } from '../_shared/recurring/PlanActions';
import notify from '../_shared/notify';
import { dashboardHref } from '../_shared/adminPages';
import { rowLinkProps } from '../_shared/rowLink';
import { formatAmount, formatDate } from '../donations/format';

const STATUS_OPTIONS = [
    { value: 'active',    label: __( 'Active', 'fundkit-fundraising-campaigns' ) },
    { value: 'past_due',  label: __( 'Past due', 'fundkit-fundraising-campaigns' ) },
    { value: 'paused',    label: __( 'Paused', 'fundkit-fundraising-campaigns' ) },
    { value: 'cancelled', label: __( 'Cancelled', 'fundkit-fundraising-campaigns' ) },
    { value: 'expired',   label: __( 'Expired', 'fundkit-fundraising-campaigns' ) },
];

const INTERVAL_OPTIONS = [
    { value: 'month', label: __( 'Monthly', 'fundkit-fundraising-campaigns' ) },
    { value: 'year',  label: __( 'Yearly', 'fundkit-fundraising-campaigns' ) },
    { value: 'week',  label: __( 'Weekly', 'fundkit-fundraising-campaigns' ) },
];

// A donation carries the cadence the donor chose on the form, not the plan's
// interval pair, so it reads from its own labels.
const FREQUENCY_LABEL = {
    weekly:    __( 'Weekly', 'fundkit-fundraising-campaigns' ),
    biweekly:  __( 'Every 2 weeks', 'fundkit-fundraising-campaigns' ),
    monthly:   __( 'Monthly', 'fundkit-fundraising-campaigns' ),
    quarterly: __( 'Quarterly', 'fundkit-fundraising-campaigns' ),
    yearly:    __( 'Yearly', 'fundkit-fundraising-campaigns' ),
};

// A view preference, not a setting: it belongs to the person looking at the
// screen, and having it reset on every page load would make it useless for the
// thing it is for, which is watching test plans appear while you make them.
const TEST_PREF = 'fundkit.subscriptions.includeTest';

const readTestPref = () => {
    try {
        return window.localStorage?.getItem( TEST_PREF ) === '1';
    } catch ( e ) {
        return false;
    }
};
// How many the notice lists before it offers the rest behind a click.
const UNLINKED_PREVIEW = 5;

// A column header sorts by its field id; the server sorts by column name and
// silently falls back to next_payment_at for a name it does not know, so an
// unmapped id turns the arrow without turning the list.
const ORDERBY_COLUMN = {
    amount:   'amount_cents',
    lifetime: 'total_paid_cents',
};

/** @since 1.0.0 */
export const orderbyFor = ( field ) => ORDERBY_COLUMN[ field ] || field || 'next_payment_at';

function intervalLabel( unit, count ) {
    const n = Number( count ) || 1;
    switch ( unit ) {
        case 'week':
            /* translators: %d: number of weeks between charges. */
            return sprintf( _n( '%d week', '%d weeks', n, 'fundkit-fundraising-campaigns' ), n );
        case 'year':
            /* translators: %d: number of years between charges. */
            return sprintf( _n( '%d year', '%d years', n, 'fundkit-fundraising-campaigns' ), n );
        case 'month':
            /* translators: %d: number of months */
            return sprintf( _n( '%d month', '%d months', n, 'fundkit-fundraising-campaigns' ), n );
        default:
            return n > 1 ? `${ n } ${ unit }` : String( unit );
    }
}

// The donor profile owns a plan's full history; this list links there rather
// than building a second detail view of the same thing.
function donorHref( donorId ) {
    return addQueryArgs( window.location.pathname, { page: 'fundkit-donors' } ) + `#donor/${ donorId }`;
}

// The donation screen is where the retry lives, so each unlinked donation links
// straight to its own record rather than to a list the org has to search.
function donationHref( reference ) {
    return addQueryArgs( window.location.pathname, {
        page: 'fundkit-donations',
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
            'fundkit-fundraising-campaigns'
        ),
        failing
    );

    const noPlan = sprintf(
        /* translators: %d: number of paid recurring donations with no plan. */
        _n(
            '%d paid recurring donation has no plan and is listed above',
            '%d paid recurring donations have no plan and are listed above',
            unlinked.total,
            'fundkit-fundraising-campaigns'
        ),
        unlinked.total
    );

    // An unread count is not a zero, so the card says so rather than
    // resolving the unknown half in the org's favour.
    let second = null;
    if ( unlinked.error ) {
        second = __( 'Donations charged with no plan could not be checked', 'fundkit-fundraising-campaigns' );
    } else if ( unlinked.total > 0 ) {
        second = noPlan;
    }

    if ( second === null ) {
        return failing > 0 ? declined : __( 'Nothing to chase', 'fundkit-fundraising-campaigns' );
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

    const note = __( 'Includes test subscriptions', 'fundkit-fundraising-campaigns' );
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
            label: __( 'Monthly recurring revenue', 'fundkit-fundraising-campaigns' ),
            value: formatAmount( stats.mrr_cents ),
            sub:   withTestNote(
                stats.unconverted > 0
                    ? sprintf(
                        /* translators: %d: number of plans with no converted amount. */
                        _n(
                            '%d plan could not be converted and is not counted',
                            '%d plans could not be converted and are not counted',
                            stats.unconverted,
                            'fundkit-fundraising-campaigns'
                        ),
                        stats.unconverted
                    )
                    : __( 'Active plans, normalised', 'fundkit-fundraising-campaigns' ),
                includeTest
            ),
        },
        {
            id:    'active',
            label: __( 'Active plans', 'fundkit-fundraising-campaigns' ),
            value: String( stats.active_count ),
            sub:   withTestNote( null, includeTest ),
        },
        {
            id:    'failing',
            label: __( 'Needs attention', 'fundkit-fundraising-campaigns' ),
            value: String( failing ),
            sub:   withTestNote( attentionSub( failing, unlinked ), includeTest ),
        },
        {
            id:    'churn',
            label: __( 'Churn this month', 'fundkit-fundraising-campaigns' ),
            value: `${ stats.churn_pct }%`,
            sub:   withTestNote(
                sprintf(
                    /* translators: %d: number of plans cancelled this month. */
                    _n( '%d cancelled', '%d cancelled', stats.churned_this_month, 'fundkit-fundraising-campaigns' ),
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
                        'fundkit-fundraising-campaigns'
                    ) }
                </div>
                <div className="fundkit-row__sub">{ error }</div>
                <Btn variant="ghost" size="sm" onClick={ onReload }>
                    { __( 'Check again', 'fundkit-fundraising-campaigns' ) }
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
                        'fundkit-fundraising-campaigns'
                    ),
                    total
                ) }
            </div>
            { windowDays > 0 && (
                <div className="fundkit-row__sub">
                    { sprintf(
                        /* translators: %d: number of days the check looks back over. */
                        _n(
                            'Covers donations paid in the last %d day.',
                            'Covers donations paid in the last %d days.',
                            windowDays,
                            'fundkit-fundraising-campaigns'
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
                    <span className="fundkit-row__sub">
                        { it.failure_recorded
                            ? __( 'failure recorded', 'fundkit-fundraising-campaigns' )
                            : __( 'no failure recorded', 'fundkit-fundraising-campaigns' ) }
                    </span>
                </div>
            ) ) }
            { hidden > 0 && (
                <Btn variant="ghost" size="sm" onClick={ onShowAll }>
                    { sprintf(
                        /* translators: %d: number of donations not yet listed. */
                        _n( 'Show %d more', 'Show %d more', hidden, 'fundkit-fundraising-campaigns' ),
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
                            'fundkit-fundraising-campaigns'
                        ),
                        beyond
                    ) }
                </div>
            ) }
            { canRetry && anyRecorded && (
                <div>{ __( 'Open a donation with a recorded failure to create its plan.', 'fundkit-fundraising-campaigns' ) }</div>
            ) }
            { ! canRetry && (
                <div>
                    { __(
                        'Creating a plan needs permission to issue refunds, so pass these references to someone who has it.',
                        'fundkit-fundraising-campaigns'
                    ) }
                </div>
            ) }
            { anyUnrecorded && (
                <div>
                    { __(
                        'Where no failure was recorded, check the payment provider for a subscription before asking the donor to set one up again.',
                        'fundkit-fundraising-campaigns'
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
            title: __( 'No live subscriptions', 'fundkit-fundraising-campaigns' ),
            // The count and the way to reveal them are in the notice above, so
            // this says what the empty table means rather than repeating them.
            body:  __( 'Nothing here is charging real money yet.', 'fundkit-fundraising-campaigns' ),
        };
    }

    if ( unlinked.error ) {
        return {
            title: __( 'No subscriptions to show', 'fundkit-fundraising-campaigns' ),
            body:  __(
                'Whether a recurring donation was charged with no plan behind it is unknown, so this is not the whole picture.',
                'fundkit-fundraising-campaigns'
            ),
        };
    }

    if ( unlinked.total > 0 ) {
        return {
            title: __( 'No subscriptions were created', 'fundkit-fundraising-campaigns' ),
            body:  unlinked.canRetry
                ? __( 'The recurring donations above were charged, but no plan was ever created for them. Open one with a recorded failure to create its plan.', 'fundkit-fundraising-campaigns' )
                : __( 'The recurring donations above were charged, but no plan was ever created for them. Creating a plan needs permission to issue refunds.', 'fundkit-fundraising-campaigns' ),
        };
    }

    return {
        title: __( 'No subscriptions yet', 'fundkit-fundraising-campaigns' ),
        body:  __( 'Recurring plans appear here once a donor sets one up on a form that offers it.', 'fundkit-fundraising-campaigns' ),
    };
}

export default function List() {
    const [ view, setView ] = useTableView( 'subscriptions', {
        type:    'table',
        perPage: 25,
        page:    1,
        sort:    { field: 'next_payment_at', direction: 'asc' },
        filters: [],
        search:  '',
        fields:  [ 'donor', 'amount', 'status', 'next_payment_at', 'started_at', 'campaign', 'gateway', 'lifetime' ],
    }, () => fields.map( ( f ) => f.id ) );

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
        apiFetch( { path: '/fundkit/v1/admin/recurring/gateway-options' } )
            .then( ( r ) => { if ( ! aborted ) setGateways( Array.isArray( r ) ? r : [] ); } )
            .catch( () => { if ( ! aborted ) setGateways( [] ); } );
        // Same route the donations list uses: /admin/campaigns needs a
        // capability this screen does not, and would 403 into an empty filter.
        apiFetch( { path: '/fundkit/v1/admin/donations/campaign-options' } )
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

    // Which empty this screen shows depends on it. See _shared/viewFilters.
    const filtered = isViewFiltered( view );
    const clearFilters = () => {
        setView( clearedView( view ) );
    };
    const gatewayFilter  = filterValue( 'gateway' );
    const campaignFilter = filterValue( 'campaign' );
    const intervalFilter = filterValue( 'interval' );
    const failingFilter  = filterValue( 'failing' );

    const apiParams = useMemo( () => ( {
        page:        view.page,
        per_page:    view.perPage,
        orderby:     orderbyFor( view.sort?.field ),
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
        return apiFetch( { path: addQueryArgs( '/fundkit/v1/admin/recurring', apiParams ), parse: false } )
            .then( async ( res ) => {
                const items = await res.json();
                setData( Array.isArray( items ) ? items : [] );
                setTotal( parseInt( res.headers.get( 'X-WP-Total' ) || '0', 10 ) );
                setTestHidden( parseInt( res.headers.get( 'X-FundKit-Test-Hidden' ) || '0', 10 ) );
                setError( null );
            } )
            .catch( ( err ) => {
                setError( err?.message || __( 'Failed to load subscriptions.', 'fundkit-fundraising-campaigns' ) );
                setData( [] );
                setTotal( 0 );
                setTestHidden( 0 );
            } )
            .finally( () => setLoading( false ) );
    };

    useEffect( () => {
        let aborted = false;
        setLoading( true );
        apiFetch( { path: addQueryArgs( '/fundkit/v1/admin/recurring', apiParams ), parse: false } )
            .then( async ( res ) => {
                if ( aborted ) return;
                const items = await res.json();
                setData( Array.isArray( items ) ? items : [] );
                setTotal( parseInt( res.headers.get( 'X-WP-Total' ) || '0', 10 ) );
                setTestHidden( parseInt( res.headers.get( 'X-FundKit-Test-Hidden' ) || '0', 10 ) );
                setError( null );
            } )
            .catch( ( err ) => {
                if ( aborted ) return;
                setError( err?.message || __( 'Failed to load subscriptions.', 'fundkit-fundraising-campaigns' ) );
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
        path: addQueryArgs( '/fundkit/v1/admin/recurring/stats', { include_test: test || undefined } ),
    } );

    const statsError = () => notify.error( __( 'The recurring totals could not be loaded.', 'fundkit-fundraising-campaigns' ) );

    const loadStats = () => fetchStats( includeTest ).then( setStats ).catch( statsError );

    // These donations have no plan row, so no filter on this list can reach
    // them; they are fetched on their own and read out above the table. A
    // failure here is kept on screen: a count nobody could take is not a zero.
    const loadUnlinked = () => apiFetch( {
        path: addQueryArgs( '/fundkit/v1/admin/recurring/unlinked', { limit: 50 } ),
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
            error:      err?.message || __( 'The check could not be run.', 'fundkit-fundraising-campaigns' ),
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
            label: __( 'Donor', 'fundkit-fundraising-campaigns' ),
            render: ( { item } ) => {
                const d = item.donor;
                if ( ! d ) return <span className="fundkit-row__sub">-</span>;
                return (
                    <div className="fundkit-row">
                        <div className="fundkit-row__body">
                            <span className="fundkit-ref-cell">
                                <a className="fundkit-row__link fundkit-row__link--strong" href={ donorHref( d.id ) } { ...rowLinkProps }>
                                    { d.name || __( '(no name)', 'fundkit-fundraising-campaigns' ) }
                                </a>
                                { item.simulated && (
                                    <span
                                        className="fundkit-pill fundkit-pill--test"
                                        title={ sprintf(
                                            /* translators: %d: minutes between simulated renewals. */
                                            __( 'Test plan. It renews every %d minutes so a full cycle can be watched, and no money moves.', 'fundkit-fundraising-campaigns' ),
                                            item.simulated_cycle_minutes || 0
                                        ) }
                                    >
                                        { __( 'Simulated', 'fundkit-fundraising-campaigns' ) }
                                    </span>
                                ) }
                            </span>
                            { d.email && <div className="fundkit-row__sub fundkit-row__sub--mono">{ d.email }</div> }
                        </div>
                    </div>
                );
            },
        },
        {
            id:            'amount',
            label:         __( 'Amount', 'fundkit-fundraising-campaigns' ),
            enableSorting: true,
            render: ( { item } ) => (
                // Muted once the plan has ended: it describes a charge that will
                // not happen again, and Lifetime beside it says what was taken.
                <span className={ isTerminal( item.status ) ? 'fundkit-row__sub' : undefined }>
                    { formatAmount( item.amount_cents, item.currency ) }
                    <span className="fundkit-row__sub"> / { intervalLabel( item.interval_unit, item.interval_count ) }</span>
                </span>
            ),
        },
        {
            id:       'status',
            label:    __( 'Status', 'fundkit-fundraising-campaigns' ),
            elements: STATUS_OPTIONS,
            filterBy: { operators: [ 'is' ] },
            enableSorting: true,
            render: ( { item } ) => (
                <>
                    <StatusBadge status={ item.status } />
                    { item.failed_renewals_count > 0 && (
                        <span className="fundkit-row__sub" style={ { marginLeft: 6 } }>
                            { sprintf(
                                /* translators: %d: consecutive failed renewals. */
                                _n( '%d failure', '%d failures', item.failed_renewals_count, 'fundkit-fundraising-campaigns' ),
                                item.failed_renewals_count
                            ) }
                        </span>
                    ) }
                </>
            ),
        },
        {
            id:            'next_payment_at',
            label:         __( 'Next charge', 'fundkit-fundraising-campaigns' ),
            enableSorting: true,
            render: ( { item } ) => (
                isTerminal( item.status )
                    ? (
                        <span className="fundkit-row__sub">
                            { item.cancelled_at
                                ? sprintf(
                                    /* translators: %s: date the plan ended. */
                                    __( 'Ended %s', 'fundkit-fundraising-campaigns' ),
                                    formatDate( item.cancelled_at )
                                )
                                : __( 'Ended', 'fundkit-fundraising-campaigns' ) }
                        </span>
                    )
                    : item.status === 'paused' && item.resume_at
                        ? (
                            <div className="fundkit-row">
                                <div className="fundkit-row__name">{ formatDate( item.resume_at ) }</div>
                                <div className="fundkit-row__sub">{ __( 'when it resumes', 'fundkit-fundraising-campaigns' ) }</div>
                            </div>
                        )
                    : (
                        <div className="fundkit-row">
                            <div className="fundkit-row__name">{ formatDate( item.next_payment_at ) }</div>
                            { item.next_payment_at && <div className="fundkit-row__sub">{ dueIn( item.next_payment_at ) }</div> }
                        </div>
                    )
            ),
        },
        {
            id:            'started_at',
            label:         __( 'Giving since', 'fundkit-fundraising-campaigns' ),
            enableSorting: true,
            render: ( { item } ) => (
                item.started_at
                    ? <span>{ formatDate( item.started_at ) }</span>
                    : <span className="fundkit-row__sub">-</span>
            ),
        },
        {            id:       'campaign',
            label:    __( 'Campaign', 'fundkit-fundraising-campaigns' ),
            elements: campaigns.map( ( c ) => ( { value: String( c.id ), label: c.title || `#${ c.id }` } ) ),
            filterBy: { operators: [ 'is' ] },
            render: ( { item } ) => (
                item.campaign
                    ? <span>{ item.campaign.title }</span>
                    : <span className="fundkit-row__sub">-</span>
            ),
        },
        {
            id:       'gateway',
            label:    __( 'Gateway', 'fundkit-fundraising-campaigns' ),
            elements: gateways,
            filterBy: { operators: [ 'is' ] },
            render: ( { item } ) => (
                <span style={ { textTransform: 'capitalize' } }>{ item.gateway }</span>
            ),
        },
        {
            id:       'failing',
            label:    __( 'Renewal health', 'fundkit-fundraising-campaigns' ),
            elements: [
                { value: 'yes', label: __( 'Has failed renewals', 'fundkit-fundraising-campaigns' ) },
            ],
            filterBy: { operators: [ 'is' ] },
            render: ( { item } ) => (
                item.failed_renewals_count > 0
                    ? <span className="fundkit-pill is-warn">{ sprintf(
                        /* translators: %d: consecutive failed renewals. */
                        _n( '%d failure', '%d failures', item.failed_renewals_count, 'fundkit-fundraising-campaigns' ),
                        item.failed_renewals_count
                    ) }</span>
                    : <span className="fundkit-row__sub">{ __( 'OK', 'fundkit-fundraising-campaigns' ) }</span>
            ),
        },
        {
            id:       'interval',
            label:    __( 'Interval', 'fundkit-fundraising-campaigns' ),
            elements: INTERVAL_OPTIONS,
            filterBy: { operators: [ 'is' ] },
            render: ( { item } ) => <span>{ intervalLabel( item.interval_unit, item.interval_count ) }</span>,
        },
        {
            id:            'lifetime',
            label:         __( 'Lifetime', 'fundkit-fundraising-campaigns' ),
            enableSorting: true,
            render: ( { item } ) => (
                <div className="fundkit-row">
                    <div className="fundkit-row__name">{ formatAmount( item.total_paid_cents, item.currency ) }</div>
                    <div className="fundkit-row__sub">
                        { sprintf(
                            /* translators: %d: number of payments taken so far. */
                            _n( '%d payment', '%d payments', item.payments_count, 'fundkit-fundraising-campaigns' ),
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
            label:       __( 'Copy subscription id', 'fundkit-fundraising-campaigns' ),
            isPrimary:   false,
            isEligible:  ( item ) => !! item.gateway_subscription_id,
            callback:    async ( [ item ] ) => {
                try {
                    await window.navigator?.clipboard?.writeText( item.gateway_subscription_id );
                    notify.success( __( 'Subscription id copied.', 'fundkit-fundraising-campaigns' ) );
                } catch ( e ) {
                    // No clipboard permission, so show it instead of failing
                    // silently: it is a lookup key and reading it is the point.
                    notify.error( item.gateway_subscription_id );
                }
            },
        },
        {
            id:    'retry',
            label: __( 'Retry payment', 'fundkit-fundraising-campaigns' ),
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
            label:    __( 'Pause', 'fundkit-fundraising-campaigns' ),
            isEligible: ( item ) => actionsFor( item ).some( ( a ) => a.id === 'pause' ),
            callback: ( items ) => setDialog( { plan: items[ 0 ], action: 'pause' } ),
        },
        {
            id:       'resume',
            label:    __( 'Resume', 'fundkit-fundraising-campaigns' ),
            isEligible: ( item ) => actionsFor( item ).some( ( a ) => a.id === 'resume' ),
            callback: ( items ) => setDialog( { plan: items[ 0 ], action: 'resume' } ),
        },
        {
            id:       'skip_next',
            label:    __( 'Skip next', 'fundkit-fundraising-campaigns' ),
            isEligible: ( item ) => actionsFor( item ).some( ( a ) => a.id === 'skip_next' ),
            callback: ( items ) => setDialog( { plan: items[ 0 ], action: 'skip_next' } ),
        },
        {
            id:       'change_amount',
            label:    __( 'Change amount', 'fundkit-fundraising-campaigns' ),
            isEligible: ( item ) => ! isTerminal( item.status ),
            callback: ( items ) => setDialog( { plan: items[ 0 ], action: 'change_amount' } ),
        },
        {
            id:            'cancel',
            label:         __( 'Cancel', 'fundkit-fundraising-campaigns' ),
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
        <div className="fundkit-admin">
            <div className="fundkit-crumbs">
                <a href={ dashboardHref( window.location.pathname ) }>{ __( 'FundKit', 'fundkit-fundraising-campaigns' ) }</a>
                <span className="sep">›</span>
                <span>{ __( 'Subscriptions', 'fundkit-fundraising-campaigns' ) }</span>
            </div>
            <div className="fundkit-page-head">
                <div className="fundkit-page-head__title-row">
                    <h1>{ __( 'Subscriptions', 'fundkit-fundraising-campaigns' ) }</h1>
                </div>
                <div className="fundkit-page-head__right">
                    { /* Offered once there is something to reveal, or while it
                         is on and needs turning off. An org sets recurring up
                         entirely in test mode, and a screen that hides every
                         plan it made reads as a broken integration. */ }
                    { ( testHidden > 0 || includeTest ) && (
                        <label className="fundkit-inline-toggle">
                            <Switch
                                checked={ includeTest }
                                onChange={ () => toggleTest( ! includeTest ) }
                                label={ __( 'Show test subscriptions', 'fundkit-fundraising-campaigns' ) }
                            />
                            <span>{ __( 'Show test subscriptions', 'fundkit-fundraising-campaigns' ) }</span>
                        </label>
                    ) }
                    <span className="fundkit-page-head__meta">
                        { sprintf(
                            /* translators: %s: number of recurring plans. */
                            _n( '%s plan', '%s plans', total, 'fundkit-fundraising-campaigns' ),
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
                            'fundkit-fundraising-campaigns'
                        ),
                        testHidden
                    ) }
                    { ' ' }
                    <Btn variant="link" onClick={ () => toggleTest( true ) }>
                        { __( 'Show them', 'fundkit-fundraising-campaigns' ) }
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

            { ! loading && total === 0 && ! filtered && ! fetchError ? (
                <EmptyState { ...emptyStateCopy( unlinked, testHidden ) } />
            ) : (
                // The card chrome the other list screens sit in lives on this
                // wrapper, so without it the table renders bare on the page.
                <div className={ `fundkit-dataviews${ ! loading && data.length === 0 && filtered ? ' is-no-results' : '' }` }>
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

                    { ! loading && data.length === 0 && filtered && (
                        <EmptyState
                            compact
                            icon={ <SearchX size={ 22 } strokeWidth={ 1.75 } /> }
                            title={ __( 'Nothing matches these filters', 'fundkit-fundraising-campaigns' ) }
                            body={ __( 'Try a different search, or clear the filters to see everything again.', 'fundkit-fundraising-campaigns' ) }
                            action={
                                <Btn variant="secondary" onClick={ clearFilters }>
                                    { __( 'Clear filters', 'fundkit-fundraising-campaigns' ) }
                                </Btn>
                            }
                        />
                    ) }
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
