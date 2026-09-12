// Subscriptions list: paginated DataViews against /gratora/v1/admin/recurring.

import { useState, useEffect, useMemo } from '@wordpress/element';
import { DataViews } from '@wordpress/dataviews';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __, _n, sprintf } from '@wordpress/i18n';

import { RotateCw, SearchX } from 'lucide-react';

import Btn from '../_shared/components/Btn';
import PlanDetailDialog from './PlanDetailDialog';
import { useTableView } from '../_shared/useTableView';
import EmptyState from '../_shared/components/EmptyState';
import { isViewFiltered, clearedView } from '../_shared/viewFilters';
import KpiStrip from '../_shared/components/KpiStrip';
import Notice from '../_shared/components/Notice';
import StatusBadge from '../_shared/components/StatusBadge';
import { Switch } from '../_shared/components/Switch';
import PlanActionDialog, { actionsFor, dueIn, isTerminal, retryActionFor } from '../_shared/recurring/PlanActions';
import { CADENCE_LABEL, cadenceLabel, renderHealth, viewDetailsAction, copySubscriptionIdAction } from '../_shared/recurring/planColumns';
import { dashboardHref } from '../_shared/adminPages';
import { rowLinkProps } from '../_shared/rowLink';
import { formatAmount, formatDate } from '../donations/format';

const STATUS_OPTIONS = [
    { value: 'active',    label: __( 'Active', 'gratora-donation-platform' ) },
    { value: 'past_due',  label: __( 'Past due', 'gratora-donation-platform' ) },
    { value: 'paused',    label: __( 'Paused', 'gratora-donation-platform' ) },
    { value: 'cancelled', label: __( 'Cancelled', 'gratora-donation-platform' ) },
    { value: 'expired',   label: __( 'Expired', 'gratora-donation-platform' ) },
];

// A cadence, not an interval unit: quarterly is three months and biweekly is
// two weeks, so filtering on the unit filed both under a chip they are not.
const INTERVAL_OPTIONS = Object.entries( CADENCE_LABEL ).map( ( [ value, label ] ) => ( { value, label } ) );

// The field the cadence filter hangs on. dataviews offers every field it is
// given as a column, so the saved view is told to keep this one out of `fields`
// while still recognising a saved filter on it.
const FILTER_ONLY = 'interval';

// A view preference, not a setting: it belongs to the person looking at the
// screen, and having it reset on every page load would make it useless for the
// thing it is for, which is watching test plans appear while you make them.
const TEST_PREF = 'gratora.subscriptions.includeTest';

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

function donorHref( donorId ) {
    return addQueryArgs( window.location.pathname, { page: 'gratora-donors' } ) + `#donor/${ donorId }`;
}

function donationHref( reference ) {
    return addQueryArgs( window.location.pathname, {
        page: 'gratora-donations',
        view: 'detail',
        reference,
    } );
}

// Count running plans on the card and disclose the broader health-filter count separately.
// Unlinked donations appear in the note, including test donations regardless of the plan
// toggle.
function attentionSub( failing, unlinked, failingEver = 0 ) {
    const declined = sprintf(
        /* translators: %d: number of plans still running that carry a failed renewal. */
        _n(
            '%d plan still running that the gateway could not collect from',
            '%d plans still running that the gateway could not collect from',
            failing,
            'gratora-donation-platform'
        ),
        failing
    );

    const everLine = failingEver > failing
        ? sprintf(
            /* translators: %d: number of plans that have ever failed a renewal, including ended ones. */
            _n(
                '%d plan has ever failed a renewal, including ended ones',
                '%d plans have ever failed a renewal, including ended ones',
                failingEver,
                'gratora-donation-platform'
            ),
            failingEver
        )
        : null;

    const noPlan = sprintf(
        /* translators: %d: number of paid recurring donations with no plan. */
        _n(
            '%d paid recurring donation has no plan and is listed above',
            '%d paid recurring donations have no plan and are listed above',
            unlinked.total,
            'gratora-donation-platform'
        ),
        unlinked.total
    );

    // An unread count is not a zero, so the card says so rather than
    // resolving the unknown half in the org's favour.
    let second = null;
    if ( unlinked.error ) {
        second = __( 'Donations charged with no plan could not be checked', 'gratora-donation-platform' );
    } else if ( unlinked.total > 0 ) {
        second = noPlan;
    }

    const lines = [
        failing > 0 ? declined : null,
        everLine,
        second,
    ].filter( Boolean );

    if ( lines.length === 0 ) return __( 'Nothing to chase', 'gratora-donation-platform' );
    if ( lines.length === 1 ) return lines[ 0 ];

    return <>{ lines.map( ( line, i ) => <div key={ i }>{ line }</div> ) }</>;
}

// Said on the card itself rather than once over the strip, so a figure read on
// its own, or screenshotted, carries the disclaimer with it.
function withTestNote( sub, includeTest ) {
    if ( ! includeTest ) return sub;

    const note = __( 'Includes test subscriptions', 'gratora-donation-platform' );
    if ( ! sub ) return note;

    return (
        <>
            <div>{ sub }</div>
            <div>{ note }</div>
        </>
    );
}

export function subscriptionKpis( stats, unlinked, includeTest ) {
    if ( ! stats ) return [];
    const failing = Number( stats.failing_count ) || 0;
    return [
        {
            id:    'mrr',
            label: __( 'Monthly recurring revenue', 'gratora-donation-platform' ),
            value: formatAmount( stats.mrr_cents ),
            sub:   withTestNote(
                stats.unconverted > 0
                    ? sprintf(
                        /* translators: %d: number of plans with no converted amount. */
                        _n(
                            '%d plan could not be converted and is not counted',
                            '%d plans could not be converted and are not counted',
                            stats.unconverted,
                            'gratora-donation-platform'
                        ),
                        stats.unconverted
                    )
                    : __( 'Active plans, normalised', 'gratora-donation-platform' ),
                includeTest
            ),
        },
        {
            id:    'active',
            label: __( 'Active plans', 'gratora-donation-platform' ),
            value: String( stats.active_count ),
            sub:   withTestNote( null, includeTest ),
        },
        {
            id:    'failing',
            label: __( 'Needs attention', 'gratora-donation-platform' ),
            value: String( failing ),
            sub:   withTestNote( attentionSub( failing, unlinked, Number( stats.failing_ever_count ) || 0 ), includeTest ),
        },
        {
            id:    'churn',
            label: __( 'Churn this month', 'gratora-donation-platform' ),
            value: `${ stats.churn_pct }%`,
            sub:   withTestNote(
                sprintf(
                    /* translators: %d: number of plans cancelled this month. */
                    _n( '%d cancelled', '%d cancelled', stats.churned_this_month, 'gratora-donation-platform' ),
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
                        'gratora-donation-platform'
                    ) }
                </div>
                <div className="gratora-row__sub">{ error }</div>
                <Btn variant="ghost" size="sm" onClick={ onReload }>
                    { __( 'Check again', 'gratora-donation-platform' ) }
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
                        'gratora-donation-platform'
                    ),
                    total
                ) }
            </div>
            { windowDays > 0 && (
                <div className="gratora-row__sub">
                    { sprintf(
                        /* translators: %d: number of days the check looks back over. */
                        _n(
                            'Covers donations paid in the last %d day.',
                            'Covers donations paid in the last %d days.',
                            windowDays,
                            'gratora-donation-platform'
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
                    { CADENCE_LABEL[ it.frequency ] || it.frequency }
                    { ' ' }
                    <span className="gratora-row__sub">
                        { it.failure_recorded
                            ? __( 'failure recorded', 'gratora-donation-platform' )
                            : __( 'no failure recorded', 'gratora-donation-platform' ) }
                    </span>
                </div>
            ) ) }
            { hidden > 0 && (
                <Btn variant="ghost" size="sm" onClick={ onShowAll }>
                    { sprintf(
                        /* translators: %d: number of donations not yet listed. */
                        _n( 'Show %d more', 'Show %d more', hidden, 'gratora-donation-platform' ),
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
                            'gratora-donation-platform'
                        ),
                        beyond
                    ) }
                </div>
            ) }
            { canRetry && anyRecorded && (
                <div>{ __( 'Open a donation with a recorded failure to create its plan.', 'gratora-donation-platform' ) }</div>
            ) }
            { ! canRetry && (
                <div>
                    { __(
                        'Creating a plan needs permission to issue refunds, so pass these references to someone who has it.',
                        'gratora-donation-platform'
                    ) }
                </div>
            ) }
            { anyUnrecorded && (
                <div>
                    { __(
                        'Where no failure was recorded, check the payment provider for a subscription before asking the donor to set one up again.',
                        'gratora-donation-platform'
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
            title: __( 'No live subscriptions', 'gratora-donation-platform' ),
            // The count and the way to reveal them are in the notice above, so
            // this says what the empty table means rather than repeating them.
            body:  __( 'Nothing here is charging real money yet.', 'gratora-donation-platform' ),
        };
    }

    if ( unlinked.error ) {
        return {
            title: __( 'No subscriptions to show', 'gratora-donation-platform' ),
            body:  __(
                'Whether a recurring donation was charged with no plan behind it is unknown, so this is not the whole picture.',
                'gratora-donation-platform'
            ),
        };
    }

    if ( unlinked.total > 0 ) {
        return {
            title: __( 'No subscriptions were created', 'gratora-donation-platform' ),
            body:  unlinked.canRetry
                ? __( 'The recurring donations above were charged, but no plan was ever created for them. Open one with a recorded failure to create its plan.', 'gratora-donation-platform' )
                : __( 'The recurring donations above were charged, but no plan was ever created for them. Creating a plan needs permission to issue refunds.', 'gratora-donation-platform' ),
        };
    }

    return {
        title: __( 'No subscriptions yet', 'gratora-donation-platform' ),
        body:  __( 'Recurring plans appear here once a donor sets one up on a form that offers it.', 'gratora-donation-platform' ),
    };
}

export default function List() {
    const [ view, setView, viewReady ] = useTableView( 'subscriptions', {
        type:    'table',
        perPage: 25,
        page:    1,
        sort:    { field: 'next_payment_at', direction: 'asc' },
        filters: [],
        search:  '',
        fields:  [ 'id', 'donor', 'amount', 'status', 'next_payment_at', 'started_at', 'campaign', 'gateway', 'lifetime' ],
    }, () => fields.map( ( f ) => f.id ), [ FILTER_ONLY ] );

    const [ data, setData ]         = useState( [] );
    const [ total, setTotal ]       = useState( 0 );
    const [ loading, setLoading ]   = useState( true );
    const [ stats, setStats ]       = useState( null );
    // A total nobody could take is not a zero, and the toast that said so
    // dismissed itself after seven seconds.
    const [ statsFailed, setStatsFailed ] = useState( null );
    const [ fetchError, setError ]  = useState( null );
    const [ gateways, setGateways ] = useState( [] );
    const [ campaigns, setCampaigns ] = useState( [] );
    const [ dialog, setDialog ]     = useState( null );
    const [ detail, setDetail ]     = useState( null );
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
        apiFetch( { path: '/gratora/v1/admin/recurring/gateway-options' } )
            .then( ( r ) => { if ( ! aborted ) setGateways( Array.isArray( r ) ? r : [] ); } )
            .catch( () => { if ( ! aborted ) setGateways( [] ); } );
        // Same route the donations list uses: /admin/campaigns needs a
        // capability this screen does not, and would 403 into an empty filter.
        apiFetch( { path: '/gratora/v1/admin/donations/campaign-options' } )
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
        frequency:   intervalFilter || undefined,
        failing:     failingFilter === 'yes' ? true : undefined,
        search:      view.search || undefined,
        include_test: includeTest || undefined,
    } ), [ view, statusFilter, gatewayFilter, campaignFilter, intervalFilter, failingFilter, includeTest ] );

    const load = () => {
        setLoading( true );
        return apiFetch( { path: addQueryArgs( '/gratora/v1/admin/recurring', apiParams ), parse: false } )
            .then( async ( res ) => {
                const items = await res.json();
                setData( Array.isArray( items ) ? items : [] );
                setTotal( parseInt( res.headers.get( 'X-WP-Total' ) || '0', 10 ) );
                setTestHidden( parseInt( res.headers.get( 'X-Gratora-Test-Hidden' ) || '0', 10 ) );
                setError( null );
            } )
            .catch( ( err ) => {
                setError( err?.message || __( 'Failed to load subscriptions.', 'gratora-donation-platform' ) );
                setData( [] );
                setTotal( 0 );
                setTestHidden( 0 );
            } )
            .finally( () => setLoading( false ) );
    };

    useEffect( () => {
        // Nothing until the saved view lands: fetching under the screen's
        // defaults first spends a request on rows the reader's own sort is
        // about to replace.
        if ( ! viewReady ) {
            return undefined;
        }

        let aborted = false;
        setLoading( true );
        apiFetch( { path: addQueryArgs( '/gratora/v1/admin/recurring', apiParams ), parse: false } )
            .then( async ( res ) => {
                if ( aborted ) return;
                const items = await res.json();
                setData( Array.isArray( items ) ? items : [] );
                setTotal( parseInt( res.headers.get( 'X-WP-Total' ) || '0', 10 ) );
                setTestHidden( parseInt( res.headers.get( 'X-Gratora-Test-Hidden' ) || '0', 10 ) );
                setError( null );
            } )
            .catch( ( err ) => {
                if ( aborted ) return;
                setError( err?.message || __( 'Failed to load subscriptions.', 'gratora-donation-platform' ) );
                setData( [] );
                setTotal( 0 );
                setTestHidden( 0 );
            } )
            .finally( () => { if ( ! aborted ) setLoading( false ); } );
        return () => { aborted = true; };
    }, [ apiParams, viewReady ] );

    // The strip totals the whole book, so the list filters are deliberately not
    // passed. The test toggle is not one of them: it decides what counts as the
    // book, and figures that disagreed with the rows under them would read as a
    // broken integration to an org whose plans are all still in test mode.
    const fetchStats = ( test ) => apiFetch( {
        path: addQueryArgs( '/gratora/v1/admin/recurring/stats', { include_test: test || undefined } ),
    } );

    const statsMessage = ( e ) => e?.message || __( 'The recurring totals could not be loaded.', 'gratora-donation-platform' );

    const loadStats = () => fetchStats( includeTest )
        .then( ( r ) => {
            setStats( r );
            setStatsFailed( null );
        } )
        .catch( ( e ) => setStatsFailed( statsMessage( e ) ) );

    // These donations have no plan row, so no filter on this list can reach
    // them; they are fetched on their own and read out above the table. A
    // failure here is kept on screen: a count nobody could take is not a zero.
    const loadUnlinked = () => apiFetch( {
        path: addQueryArgs( '/gratora/v1/admin/recurring/unlinked', { limit: 50 } ),
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
            error:      err?.message || __( 'The check could not be run.', 'gratora-donation-platform' ),
        } ) );

    // Two flips of the toggle land in whatever order the network decides, and
    // the loser would leave test money on a card carrying no note about it.
    useEffect( () => {
        let aborted = false;
        fetchStats( includeTest )
            .then( ( r ) => { if ( ! aborted ) { setStats( r ); setStatsFailed( null ); } } )
            .catch( ( e ) => { if ( ! aborted ) setStatsFailed( statsMessage( e ) ); } );
        return () => { aborted = true; };
    }, [ includeTest ] );

    useEffect( () => { loadUnlinked(); }, [] );

    const fields = useMemo( () => [
        {
            id:    'id',
            label: __( 'ID', 'gratora-donation-platform' ),
            render: ( { item } ) => (
                <span className="gratora-ref-cell">
                    <a
                        className="gratora-mono-link"
                        href={ `#subscription/${ item.id }` }
                        onClick={ ( e ) => { e.preventDefault(); setDetail( item ); } }
                    >
                        { `#${ item.id }` }
                    </a>
                </span>
            ),
        },
        {
            id:    'donor',
            label: __( 'Donor', 'gratora-donation-platform' ),
            render: ( { item } ) => {
                const d = item.donor;
                if ( ! d ) return <span className="gratora-row__sub">-</span>;
                return (
                    <div className="gratora-row">
                        <div className="gratora-row__body">
                            <span className="gratora-ref-cell">
                                <a className="gratora-row__link gratora-row__link--strong" href={ donorHref( d.id ) } { ...rowLinkProps }>
                                    { d.name || __( '(no name)', 'gratora-donation-platform' ) }
                                </a>
                                { item.simulated && (
                                    <span
                                        className="gratora-pill gratora-pill--test"
                                        title={ sprintf(
                                            /* translators: %d: minutes between simulated renewals. */
                                            __( 'Test plan. It renews every %d minutes so a full cycle can be watched, and no money moves.', 'gratora-donation-platform' ),
                                            item.simulated_cycle_minutes || 0
                                        ) }
                                    >
                                        { __( 'Simulated', 'gratora-donation-platform' ) }
                                    </span>
                                ) }
                            </span>
                            { d.email && <div className="gratora-row__sub gratora-row__sub--mono">{ d.email }</div> }
                        </div>
                    </div>
                );
            },
        },
        {
            id:            'amount',
            label:         __( 'Amount / interval', 'gratora-donation-platform' ),
            enableSorting: true,
            render: ( { item } ) => (
                // Muted once the plan has ended: it describes a charge that will
                // not happen again, and Lifetime beside it says what was taken.
                <span className={ isTerminal( item.status ) ? 'gratora-row__sub' : undefined }>
                    { formatAmount( item.amount_cents, item.currency ) }
                    <span className="gratora-row__sub"> / { cadenceLabel( item ) }</span>
                </span>
            ),
        },
        {
            id:       'status',
            label:    __( 'Status', 'gratora-donation-platform' ),
            elements: STATUS_OPTIONS,
            filterBy: { operators: [ 'is' ] },
            enableSorting: true,
            render: ( { item } ) => (
                <>
                    <StatusBadge status={ item.status } />
                    { item.failed_renewals_count > 0 && (
                        <span className="gratora-row__sub" style={ { marginLeft: 6 } }>
                            { sprintf(
                                /* translators: %d: consecutive failed renewals. */
                                _n( '%d failure', '%d failures', item.failed_renewals_count, 'gratora-donation-platform' ),
                                item.failed_renewals_count
                            ) }
                        </span>
                    ) }
                </>
            ),
        },
        {
            id:            'next_payment_at',
            label:         __( 'Next charge', 'gratora-donation-platform' ),
            enableSorting: true,
            render: ( { item } ) => (
                isTerminal( item.status )
                    ? (
                        <span className="gratora-row__sub">
                            { item.cancelled_at
                                ? sprintf(
                                    /* translators: %s: date the plan ended. */
                                    __( 'Ended %s', 'gratora-donation-platform' ),
                                    formatDate( item.cancelled_at )
                                )
                                : __( 'Ended', 'gratora-donation-platform' ) }
                        </span>
                    )
                    : item.status === 'paused' && item.resume_at
                        ? (
                            <div className="gratora-row">
                                <div className="gratora-row__body">
                                    <div className="gratora-row__name">{ formatDate( item.resume_at ) }</div>
                                    <div className="gratora-row__sub">{ __( 'when it resumes', 'gratora-donation-platform' ) }</div>
                                </div>
                            </div>
                        )
                    : (
                        <div className="gratora-row">
                            <div className="gratora-row__body">
                                <div className="gratora-row__name">{ formatDate( item.next_payment_at ) }</div>
                                { item.next_payment_at && <div className="gratora-row__sub">{ dueIn( item.next_payment_at ) }</div> }
                            </div>
                        </div>
                    )
            ),
        },
        {
            id:            'started_at',
            label:         __( 'Giving since', 'gratora-donation-platform' ),
            enableSorting: true,
            render: ( { item } ) => (
                item.started_at
                    ? <span>{ formatDate( item.started_at ) }</span>
                    : <span className="gratora-row__sub">-</span>
            ),
        },
        {            id:       'campaign',
            label:    __( 'Campaign', 'gratora-donation-platform' ),
            elements: campaigns.map( ( c ) => ( { value: String( c.id ), label: c.title || `#${ c.id }` } ) ),
            filterBy: { operators: [ 'is' ] },
            render: ( { item } ) => (
                item.campaign
                    ? <span>{ item.campaign.title }</span>
                    : <span className="gratora-row__sub">-</span>
            ),
        },
        {
            id:       'gateway',
            label:    __( 'Gateway', 'gratora-donation-platform' ),
            elements: gateways,
            filterBy: { operators: [ 'is' ] },
            render: ( { item } ) => (
                <div>
                    <div style={ { textTransform: 'capitalize' } }>{ item.gateway }</div>
                    { item.gateway_subscription_id
                        ? <code className="gratora-row__sub gratora-row__sub--mono">{ item.gateway_subscription_id }</code>
                        : <span className="gratora-row__sub">{ __( 'Not linked', 'gratora-donation-platform' ) }</span> }
                </div>
            ),
        },
        {
            id:       'failing',
            label:    __( 'Health', 'gratora-donation-platform' ),
            elements: [
                { value: 'yes', label: __( 'Has ever failed a renewal', 'gratora-donation-platform' ) },
            ],
            filterBy: { operators: [ 'is' ] },
            render: ( { item } ) => renderHealth( item ),
        },
        {
            id:           FILTER_ONLY,
            label:        __( 'Interval', 'gratora-donation-platform' ),
            elements:     INTERVAL_OPTIONS,
            filterBy:     { operators: [ 'is' ] },
            // Locks the one toggle dataviews draws for it, so the cadence chip
            // stays reachable without the field being offered as a column.
            enableHiding: false,
        },
        {
            id:            'lifetime',
            label:         __( 'Lifetime', 'gratora-donation-platform' ),
            enableSorting: true,
            render: ( { item } ) => (
                <div className="gratora-row">
                    <div className="gratora-row__body">
                        <div className="gratora-row__name">{ formatAmount( item.total_paid_cents, item.currency ) }</div>
                        <div className="gratora-row__sub">
                            { sprintf(
                                /* translators: %d: number of payments taken so far. */
                                _n( '%d payment', '%d payments', item.payments_count, 'gratora-donation-platform' ),
                                item.payments_count
                            ) }
                        </div>
                    </div>
                </div>
            ),
        },
    ], [ gateways, campaigns ] );

    const actions = useMemo( () => [
        viewDetailsAction( setDetail ),
        copySubscriptionIdAction(),
        {
            id:    'retry',
            label: __( 'Retry payment', 'gratora-donation-platform' ),
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
            label:    __( 'Pause', 'gratora-donation-platform' ),
            isEligible: ( item ) => actionsFor( item ).some( ( a ) => a.id === 'pause' ),
            callback: ( items ) => setDialog( { plan: items[ 0 ], action: 'pause' } ),
        },
        {
            id:       'resume',
            label:    __( 'Resume', 'gratora-donation-platform' ),
            isEligible: ( item ) => actionsFor( item ).some( ( a ) => a.id === 'resume' ),
            callback: ( items ) => setDialog( { plan: items[ 0 ], action: 'resume' } ),
        },
        {
            id:       'skip_next',
            label:    __( 'Skip next', 'gratora-donation-platform' ),
            isEligible: ( item ) => actionsFor( item ).some( ( a ) => a.id === 'skip_next' ),
            callback: ( items ) => setDialog( { plan: items[ 0 ], action: 'skip_next' } ),
        },
        {
            id:       'change_amount',
            label:    __( 'Change amount', 'gratora-donation-platform' ),
            isEligible: ( item ) => actionsFor( item ).some( ( a ) => a.id === 'change_amount' ),
            callback: ( items ) => setDialog( { plan: items[ 0 ], action: 'change_amount' } ),
        },
        {
            id:            'cancel',
            label:         __( 'Cancel', 'gratora-donation-platform' ),
            isDestructive: true,
            isEligible: ( item ) => actionsFor( item ).some( ( a ) => a.id === 'cancel' ),
            callback: ( items ) => setDialog( { plan: items[ 0 ], action: 'cancel' } ),
        },
    ], [] );

    const paginationInfo = useMemo(
        () => ( { totalItems: total, totalPages: Math.max( 1, Math.ceil( total / view.perPage ) ) } ),
        [ total, view.perPage ]
    );

    return (
        <div className="gratora-admin">
            <div className="gratora-crumbs">
                <a href={ dashboardHref( window.location.pathname ) }>{ __( 'Fundraising', 'gratora-donation-platform' ) }</a>
                <span className="sep">›</span>
                <span>{ __( 'Subscriptions', 'gratora-donation-platform' ) }</span>
            </div>
            <div className="gratora-page-head">
                <div className="gratora-page-head__title-row">
                    <h1>{ __( 'Subscriptions', 'gratora-donation-platform' ) }</h1>
                </div>
                <div className="gratora-page-head__right">
                    { /* Offered once there is something to reveal, or while it
                         is on and needs turning off. An org sets recurring up
                         entirely in test mode, and a screen that hides every
                         plan it made reads as a broken integration. */ }
                    { ( testHidden > 0 || includeTest ) && (
                        <label className="gratora-inline-toggle">
                            <Switch
                                checked={ includeTest }
                                onChange={ () => toggleTest( ! includeTest ) }
                                label={ __( 'Show test subscriptions', 'gratora-donation-platform' ) }
                            />
                            <span>{ __( 'Show test subscriptions', 'gratora-donation-platform' ) }</span>
                        </label>
                    ) }
                    <span className="gratora-page-head__meta">
                        { sprintf(
                            /* translators: %s: number of recurring plans. */
                            _n( '%s plan', '%s plans', total, 'gratora-donation-platform' ),
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
                            'gratora-donation-platform'
                        ),
                        testHidden
                    ) }
                    { ' ' }
                    <Btn variant="link" onClick={ () => toggleTest( true ) }>
                        { __( 'Show them', 'gratora-donation-platform' ) }
                    </Btn>
                </Notice>
            ) }
            <UnlinkedNotice
                unlinked={ unlinked }
                showAll={ showAllUnlinked }
                onShowAll={ () => setShowAllUnlinked( true ) }
                onReload={ loadUnlinked }
            />

            { statsFailed && ! stats ? (
                <Notice status="error" isDismissible={ false }>
                    <div>{ __( 'The recurring totals could not be loaded, so nothing on this screen totals the book.', 'gratora-donation-platform' ) }</div>
                    <div className="gratora-row__sub">{ statsFailed }</div>
                    <Btn variant="ghost" size="sm" onClick={ loadStats }>{ __( 'Try again', 'gratora-donation-platform' ) }</Btn>
                </Notice>
            ) : (
                <>
                    { statsFailed && (
                        <Notice status="error" onRemove={ () => setStatsFailed( null ) }>
                            { __( 'These totals are from before your last change. They could not be refreshed.', 'gratora-donation-platform' ) }
                        </Notice>
                    ) }
                    <KpiStrip items={ subscriptionKpis( stats, unlinked, includeTest ) } loading={ ! stats && ! statsFailed } />
                </>
            ) }

            { fetchError && (
                <Notice status="error" isDismissible={ false }>{ fetchError }</Notice>
            ) }

            { ! loading && total === 0 && ! filtered && ! fetchError ? (
                <EmptyState { ...emptyStateCopy( unlinked, testHidden ) } />
            ) : (
                // The card chrome the other list screens sit in lives on this
                // wrapper, so without it the table renders bare on the page.
                <div className={ `gratora-dataviews${ ! loading && data.length === 0 && filtered ? ' is-no-results' : '' }` }>
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
                            title={ __( 'Nothing matches these filters', 'gratora-donation-platform' ) }
                            body={ __( 'Try a different search, or clear the filters to see everything again.', 'gratora-donation-platform' ) }
                            action={
                                <Btn variant="secondary" onClick={ clearFilters }>
                                    { __( 'Clear filters', 'gratora-donation-platform' ) }
                                </Btn>
                            }
                        />
                    ) }
                </div>
            ) }

            { detail && (
                <PlanDetailDialog
                    plan={ detail }
                    onClose={ () => setDetail( null ) }
                    onAction={ ( action ) => { setDialog( { plan: detail, action } ); setDetail( null ); } }
                />
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
