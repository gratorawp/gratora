import { useEffect, useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __, _n, sprintf } from '@wordpress/i18n';
import { AlertTriangle } from 'lucide-react';

import EmptyState from '../_shared/components/EmptyState';
import Notice from '../_shared/components/Notice';
import Btn from '../_shared/components/Btn';
import WidgetGrid from '../_shared/widgets/WidgetGrid';
import LayoutControls from '../_shared/widgets/LayoutControls';
import SectionBar from '../_shared/widgets/SectionBar';
import RevenueChart from '../_shared/widgets/RevenueChart';
import ChannelBreakdown from '../_shared/widgets/ChannelBreakdown';
import { useDonoLayout } from '../_shared/widgets/useDonoLayout';
import { defaultCurrency } from '../_shared/format';

import KpiRow from './widgets/KpiRow';
import ActiveCampaigns from './widgets/ActiveCampaigns';
import QuickActions from './widgets/QuickActions';
import TodayStrip from './widgets/TodayStrip';
import RecentActivity from './widgets/RecentActivity';
import TopCampaigns from './widgets/TopCampaigns';
import RecurringForecast from './widgets/RecurringForecast';
import NeedsAttention from './widgets/NeedsAttention';
import { Button } from '@wordpress/components';

const SCOPE = 'dashboard';

const WIDGET_KEYS = [
    'today',
    'attention',
    'kpis',
    'revenue',
    'active-campaigns',
    'recurring',
    'top-campaigns',
    'channel',
    'recent-activity',
    'quick-actions',
];

const EMPTY_METRICS = {
    kpi: {
        amount_raised_cents: 0,
        donations_count: 0,
        donors_count: 0,
        avg_donation_cents: 0,
        currency: 'USD',
        comparison: null,
    },
    revenue: { series: [], previous_series: null },
    active_campaigns: [],
    top_campaigns: [],
    by_channel: [],
    recurring: null,
    today: null,
    recent_activity: [],
    attention: [],
};

export default function Dashboard() {
    const [ range, setRange ]               = useState( 'last-30' );
    const [ includeTest, setIncludeTest ]   = useState( false );
    const [ compareMode, setCompareMode ]   = useState( 'none' );
    const [ metrics, setMetrics ]           = useState( null );
    const [ loading, setLoading ]           = useState( true );
    const [ fetchError, setFetchError ]     = useState( false );
    const [ reloadKey, setReloadKey ]       = useState( 0 );

    const layout = useDonoLayout( SCOPE, WIDGET_KEYS );

    // Only fetch sections for visible widgets; include= changes on hide/unhide.
    const includeKey = useMemo( () => layout.visibleOrder.join( ',' ), [ layout.visibleOrder ] );

    useEffect( () => {
        let aborted = false;
        setLoading( true );
        setFetchError( false );
        apiFetch( {
            path: addQueryArgs( '/dono/v1/admin/dashboard', { range, compare: compareMode, include: includeKey, include_test: includeTest } ),
        } )
            .then( ( m ) => { if ( ! aborted ) setMetrics( ( prev ) => ( { ...( prev || {} ), ...m } ) ); } )
            .catch( () => { if ( ! aborted ) setFetchError( true ); } )
            .finally( () => { if ( ! aborted ) setLoading( false ); } );
        return () => { aborted = true; };
    }, [ range, compareMode, includeKey, includeTest, reloadKey ] );

    const m = metrics || EMPTY_METRICS;
    const hiddenTotal = ( metrics?.test?.hidden?.donations || 0 )
        + ( metrics?.test?.hidden?.plans || 0 );

    const rangeIsComparable = range !== 'all-time' && range !== 'today';

    const compareOn = compareMode !== 'none' && rangeIsComparable;
    const currency = m.kpi?.currency || defaultCurrency();

    const registry = {
        today: {
            title:  __( 'Activity (last 24h)', 'dono-fundraising-platform' ),
            render: () => <TodayStrip today={ m.today } />,
        },
        kpis: {
            title:  __( 'Key metrics', 'dono-fundraising-platform' ),
            span:   'full',
            bare:   true,
            render: () => <KpiRow kpi={ m.kpi } compareOn={ compareOn } range={ range } includesTest={ !! m.test?.includes_test } loading={ metrics === null && loading } />,
        },
        attention: {
            title:  __( 'Needs attention', 'dono-fundraising-platform' ),
            render: () => <NeedsAttention items={ m.attention } />,
        },
        revenue: {
            title:  __( 'Revenue', 'dono-fundraising-platform' ),
            span:   'full',
            render: () => (
                <RevenueChart
                    series={ m.revenue?.series || [] }
                    currency={ currency }
                    compareOn={ compareOn }
                    comparison={ m.revenue?.previous_series ? { previous_series: m.revenue.previous_series } : null }
                />
            ),
        },
        'active-campaigns': {
            title:  __( 'Active campaigns', 'dono-fundraising-platform' ),
            render: () => <ActiveCampaigns rows={ m.active_campaigns } />,
        },
        recurring: {
            title:  __( 'Recurring revenue', 'dono-fundraising-platform' ),
            render: () => <RecurringForecast recurring={ m.recurring } />,
        },
        'top-campaigns': {
            title:  __( 'Top campaigns', 'dono-fundraising-platform' ),
            render: () => <TopCampaigns rows={ m.top_campaigns } />,
        },
        channel: {
            title:  __( 'Channels', 'dono-fundraising-platform' ),
            render: () => <ChannelBreakdown rows={ m.by_channel } currency={ currency } />,
        },
        'recent-activity': {
            title:  __( 'Recent donations', 'dono-fundraising-platform' ),
            render: () => <RecentActivity rows={ m.recent_activity } />,
        },
        'quick-actions': {
            title:  __( 'Quick actions', 'dono-fundraising-platform' ),
            render: () => <QuickActions />,
        },
    };

    return (
        <div className="dono-dashboard" data-loading={ loading ? 'true' : undefined }>
            <div className="dono-page-head">
                <div className="dono-page-head__title-row">
                    <h1>{ __( 'Dashboard', 'dono-fundraising-platform' ) }</h1>
                </div>
                <div className="dono-page-head__right">
                    <SectionBar
                        range={ range } onRangeChange={ setRange }
                        compareMode={ compareMode } onCompareModeChange={ setCompareMode }
                        compareAvailable={ rangeIsComparable }
                        layoutSlot={
                            <LayoutControls
                                hidden={ layout.hidden }
                                registry={ registry }
                                onUnhide={ layout.unhide }
                                onReset={ layout.reset }
                            />
                        }
                    />
                </div>
            </div>

            { /* A dashboard of zeroes on a site that has been rehearsing looks
                 broken. Say what is being held back, and offer the way to see
                 it, rather than letting the operator guess. */ }
            { metrics?.test && ! metrics.test.includes_test && hiddenTotal > 0 && (
                <Notice status="info" isDismissible={ false }>
                    { sprintf(
                        /* translators: %d: number of test records not counted. */
                        _n(
                            '%d test record is not counted here.',
                            '%d test records are not counted here.',
                            hiddenTotal,
                            'dono-fundraising-platform'
                        ),
                        hiddenTotal
                    ) }
                    { ' ' }
                    <Button variant="link" onClick={ () => setIncludeTest( true ) }>
                        { __( 'Show them', 'dono-fundraising-platform' ) }
                    </Button>
                </Notice>
            ) }

            { metrics?.test?.includes_test && (
                <Notice status="warning" isDismissible={ false }>
                    { __( 'These figures include test records. They contain money that was never actually taken, so they cannot be quoted as income.', 'dono-fundraising-platform' ) }
                    { ' ' }
                    <Button variant="link" onClick={ () => setIncludeTest( false ) }>
                        { __( 'Hide them', 'dono-fundraising-platform' ) }
                    </Button>
                </Notice>
            ) }

            { /* A range change that fails leaves the previous range's numbers on
                 screen with nothing marking them, so they read as belonging to
                 the range now selected. */ }
            { metrics !== null && fetchError && (
                <Notice status="error" onRemove={ () => setFetchError( false ) }>
                    { __( 'These numbers are from the previous range. The one you picked could not be loaded.', 'dono-fundraising-platform' ) }
                </Notice>
            ) }

            { metrics === null && fetchError ? (
                <EmptyState
                    icon={ <AlertTriangle size={ 24 } strokeWidth={ 1.75 } /> }
                    title={ __( 'Could not load your dashboard', 'dono-fundraising-platform' ) }
                    body={ __( 'Something went wrong fetching your metrics. Check your connection and try again.', 'dono-fundraising-platform' ) }
                    action={
                        <Btn variant="primary" onClick={ () => setReloadKey( ( k ) => k + 1 ) }>
                            { __( 'Retry', 'dono-fundraising-platform' ) }
                        </Btn>
                    }
                />
            ) : (
                <WidgetGrid
                    visibleOrder={ layout.visibleOrder }
                    registry={ registry }
                    onReorder={ ( from, to ) => {
                        const fromAll = layout.order.indexOf( layout.visibleOrder[ from ] );
                        const toAll   = layout.order.indexOf( layout.visibleOrder[ to ] );
                        layout.moveTo( fromAll, toAll );
                    } }
                    onHide={ layout.hide }
                />
            ) }
        </div>
    );
}
