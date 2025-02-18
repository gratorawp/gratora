import { useEffect, useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __ } from '@wordpress/i18n';
import { AlertTriangle } from 'lucide-react';

import EmptyState from '../_shared/components/EmptyState';
import Btn from '../_shared/components/Btn';
import WidgetGrid from '../_shared/widgets/WidgetGrid';
import LayoutControls from '../_shared/widgets/LayoutControls';
import SectionBar from '../_shared/widgets/SectionBar';
import RevenueChart from '../_shared/widgets/RevenueChart';
import ChannelBreakdown from '../_shared/widgets/ChannelBreakdown';
import { useDonoLayout } from '../_shared/widgets/useDonoLayout';

import KpiRow from './widgets/KpiRow';
import ActiveCampaigns from './widgets/ActiveCampaigns';
import QuickActions from './widgets/QuickActions';
import TodayStrip from './widgets/TodayStrip';
import RecentActivity from './widgets/RecentActivity';
import TopCampaigns from './widgets/TopCampaigns';
import RecurringForecast from './widgets/RecurringForecast';
import NeedsAttention from './widgets/NeedsAttention';

const SCOPE = 'dashboard';

const WIDGET_KEYS = [
    'today',
    'kpis',
    'attention',
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
            path: addQueryArgs( '/dono/v1/admin/dashboard', { range, compare: compareMode, include: includeKey } ),
        } )
            .then( ( m ) => { if ( ! aborted ) setMetrics( ( prev ) => ( { ...( prev || {} ), ...m } ) ); } )
            .catch( () => { if ( ! aborted ) setFetchError( true ); } )
            .finally( () => { if ( ! aborted ) setLoading( false ); } );
        return () => { aborted = true; };
    }, [ range, compareMode, includeKey, reloadKey ] );

    const m = metrics || EMPTY_METRICS;
    const rangeIsComparable = range !== 'all-time' && range !== 'today';

    const compareOn = compareMode !== 'none' && rangeIsComparable;
    const currency = m.kpi?.currency || 'USD';

    const registry = {
        today: {
            title:  __( 'Activity (last 24h)', 'dono' ),
            span:   'full',
            render: () => <TodayStrip today={ m.today } />,
        },
        kpis: {
            title:  __( 'Key metrics', 'dono' ),
            span:   'full',
            bare:   true,
            render: () => <KpiRow kpi={ m.kpi } compareOn={ compareOn } range={ range } loading={ metrics === null && loading } />,
        },
        attention: {
            title:  __( 'Needs attention', 'dono' ),
            render: () => <NeedsAttention items={ m.attention } />,
        },
        revenue: {
            title:  __( 'Revenue', 'dono' ),
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
            title:  __( 'Active campaigns', 'dono' ),
            render: () => <ActiveCampaigns rows={ m.active_campaigns } />,
        },
        recurring: {
            title:  __( 'Recurring revenue', 'dono' ),
            render: () => <RecurringForecast recurring={ m.recurring } />,
        },
        'top-campaigns': {
            title:  __( 'Top campaigns', 'dono' ),
            render: () => <TopCampaigns rows={ m.top_campaigns } />,
        },
        channel: {
            title:  __( 'Channels', 'dono' ),
            render: () => <ChannelBreakdown rows={ m.by_channel } currency={ currency } />,
        },
        'recent-activity': {
            title:  __( 'Recent donations', 'dono' ),
            render: () => <RecentActivity rows={ m.recent_activity } />,
        },
        'quick-actions': {
            title:  __( 'Quick actions', 'dono' ),
            render: () => <QuickActions />,
        },
    };

    return (
        <div className="dono-dashboard" data-loading={ loading ? 'true' : undefined }>
            <div className="dono-page-head">
                <div className="dono-page-head__title-row">
                    <h1>{ __( 'Dashboard', 'dono' ) }</h1>
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

            { metrics === null && fetchError ? (
                <EmptyState
                    icon={ <AlertTriangle size={ 24 } strokeWidth={ 1.75 } /> }
                    title={ __( 'Could not load your dashboard', 'dono' ) }
                    body={ __( 'Something went wrong fetching your metrics. Check your connection and try again.', 'dono' ) }
                    action={
                        <Btn variant="primary" onClick={ () => setReloadKey( ( k ) => k + 1 ) }>
                            { __( 'Retry', 'dono' ) }
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
