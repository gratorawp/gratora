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
import { useFundKitLayout } from '../_shared/widgets/useFundKitLayout';
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
    'kpis',
    'revenue',
    'attention',
    'today',
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

    const layout = useFundKitLayout( SCOPE, WIDGET_KEYS );

    // Only fetch sections for visible widgets; include= changes on hide/unhide.
    const includeKey = useMemo( () => layout.visibleOrder.join( ',' ), [ layout.visibleOrder ] );

    useEffect( () => {
        let aborted = false;
        setLoading( true );
        setFetchError( false );
        apiFetch( {
            path: addQueryArgs( '/fundkit/v1/admin/dashboard', { range, compare: compareMode, include: includeKey, include_test: includeTest } ),
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
            title:  __( 'Activity (last 24h)', 'fundraising-toolkit' ),
            render: () => <TodayStrip today={ m.today } />,
        },
        kpis: {
            title:  __( 'Key metrics', 'fundraising-toolkit' ),
            span:   'full',
            bare:   true,
            render: () => <KpiRow kpi={ m.kpi } compareOn={ compareOn } range={ range } includesTest={ !! m.test?.includes_test } loading={ metrics === null && loading } />,
        },
        attention: {
            title:  __( 'Needs attention', 'fundraising-toolkit' ),
            render: () => <NeedsAttention items={ m.attention } />,
        },
        revenue: {
            title:  __( 'Revenue', 'fundraising-toolkit' ),
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
            title:  __( 'Active campaigns', 'fundraising-toolkit' ),
            render: () => <ActiveCampaigns rows={ m.active_campaigns } />,
        },
        recurring: {
            title:  __( 'Recurring revenue', 'fundraising-toolkit' ),
            render: () => <RecurringForecast recurring={ m.recurring } />,
        },
        'top-campaigns': {
            title:  __( 'Top campaigns', 'fundraising-toolkit' ),
            render: () => <TopCampaigns rows={ m.top_campaigns } />,
        },
        channel: {
            title:  __( 'Channels', 'fundraising-toolkit' ),
            render: () => <ChannelBreakdown rows={ m.by_channel } currency={ currency } />,
        },
        'recent-activity': {
            title:  __( 'Recent donations', 'fundraising-toolkit' ),
            render: () => <RecentActivity rows={ m.recent_activity } />,
        },
        'quick-actions': {
            title:  __( 'Quick actions', 'fundraising-toolkit' ),
            render: () => <QuickActions />,
        },
    };

    return (
        <div className="fundkit-dashboard" data-loading={ loading ? 'true' : undefined }>
            <div className="fundkit-page-head">
                <div className="fundkit-page-head__title-row">
                    <h1>{ __( 'Dashboard', 'fundraising-toolkit' ) }</h1>
                </div>
                <div className="fundkit-page-head__right">
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
                            'fundraising-toolkit'
                        ),
                        hiddenTotal
                    ) }
                    { ' ' }
                    <Button variant="link" onClick={ () => setIncludeTest( true ) }>
                        { __( 'Show them', 'fundraising-toolkit' ) }
                    </Button>
                </Notice>
            ) }

            { metrics?.test?.includes_test && (
                <Notice status="warning" isDismissible={ false }>
                    { __( 'These figures include test records. They contain money that was never actually taken, so they cannot be quoted as income.', 'fundraising-toolkit' ) }
                    { ' ' }
                    <Button variant="link" onClick={ () => setIncludeTest( false ) }>
                        { __( 'Hide them', 'fundraising-toolkit' ) }
                    </Button>
                </Notice>
            ) }

            { /* A range change that fails leaves the previous range's numbers on
                 screen with nothing marking them, so they read as belonging to
                 the range now selected. */ }
            { metrics !== null && fetchError && (
                <Notice status="error" onRemove={ () => setFetchError( false ) }>
                    { __( 'These numbers are from the previous range. The one you picked could not be loaded.', 'fundraising-toolkit' ) }
                </Notice>
            ) }

            { metrics === null && fetchError ? (
                <EmptyState
                    icon={ <AlertTriangle size={ 24 } strokeWidth={ 1.75 } /> }
                    title={ __( 'Could not load your dashboard', 'fundraising-toolkit' ) }
                    body={ __( 'Something went wrong fetching your metrics. Check your connection and try again.', 'fundraising-toolkit' ) }
                    action={
                        <Btn variant="primary" onClick={ () => setReloadKey( ( k ) => k + 1 ) }>
                            { __( 'Retry', 'fundraising-toolkit' ) }
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
