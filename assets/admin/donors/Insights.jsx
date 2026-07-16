import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { Users as UsersIcon, History } from 'lucide-react';

import EmptyState from '../_shared/components/EmptyState';
import MetricCard from '../_shared/widgets/MetricCard';
import { WidgetCard } from '../_shared/widgets/Widget';
import { formatAmount, formatAmountCompact, formatDate } from '../_shared/format';
import { downloadFile } from '../_shared/download';
import notify from '../_shared/notify';
import { IconUsers, IconHeart, IconActivity, IconCoins } from '../_shared/widgets/icons';

const SEGMENT_META = {
    champions:   { label: __( 'Champions',    'dono' ), color: 'var(--dono-seg-champions, #16a34a)',   hint: __( 'Active, frequent, high LTV', 'dono' ) },
    loyal:       { label: __( 'Loyal',        'dono' ), color: 'var(--dono-seg-loyal, #0891b2)',       hint: __( 'Active, 2+ donations',        'dono' ) },
    new:         { label: __( 'New',          'dono' ), color: 'var(--dono-seg-new, #7c3aed)',         hint: __( 'Joined recently',             'dono' ) },
    at_risk:     { label: __( 'At risk',      'dono' ), color: 'var(--dono-seg-at-risk, #f59e0b)',     hint: __( 'Was active, slowing down',    'dono' ) },
    hibernating: { label: __( 'Hibernating',  'dono' ), color: 'var(--dono-seg-hibernating, #a16207)', hint: __( 'Long lapsed, low LTV',        'dono' ) },
    lost:        { label: __( 'Lost',         'dono' ), color: 'var(--dono-seg-lost, #dc2626)',        hint: __( '> 12 months silent',           'dono' ) },
    other:       { label: __( 'Other',        'dono' ), color: 'var(--dono-seg-other, #6b7280)',       hint: __( 'Uncategorised',               'dono' ) },
};

function formatBucketLabel( min, max ) {
    if ( max === null ) return `> ${ formatAmountCompact( min - 1 ) }`;
    if ( min === 1 ) return `${ formatAmountCompact( 0 ) }-${ formatAmountCompact( max ) }`;
    return `${ formatAmountCompact( min - 1 ) }-${ formatAmountCompact( max ) }`;
}

function LifecycleKpis( { kpi } ) {
    return (
        <div className="dono-overview__metrics">
            <MetricCard
                label={ __( 'Total donors', 'dono' ) }
                value={ String( kpi.total ) }
                sub={ kpi.new ? `+${ kpi.new } ${ __( 'new (30d)', 'dono' ) }` : __( 'no new donors', 'dono' ) }
                icon={ <IconUsers /> }
            />
            <MetricCard
                label={ __( 'Active', 'dono' ) }
                value={ String( kpi.active ) }
                sub={ `${ kpi.active_pct }% ${ __( 'of base · gave in 90d', 'dono' ) }` }
                icon={ <IconHeart /> }
            />
            <MetricCard
                label={ __( 'At risk', 'dono' ) }
                value={ String( kpi.at_risk ) }
                sub={ `${ kpi.at_risk_pct }% ${ __( 'silent 90-180d', 'dono' ) }` }
                icon={ <IconActivity /> }
            />
            <MetricCard
                label={ __( 'Lapsed', 'dono' ) }
                value={ String( kpi.lapsed ) }
                sub={ `${ kpi.lapsed_pct }% ${ __( 'silent 180-365d', 'dono' ) }` }
                icon={ <IconActivity /> }
            />
            <MetricCard
                label={ __( 'Lost', 'dono' ) }
                value={ String( kpi.lost ) }
                sub={ `${ kpi.lost_pct }% ${ __( '> 365d silent', 'dono' ) }` }
                icon={ <IconActivity /> }
            />
            <MetricCard
                label={ __( 'Median LTV', 'dono' ) }
                value={ formatAmount( kpi.median_ltv_cents ) }
                sub={ `${ __( 'avg', 'dono' ) } ${ formatAmount( kpi.avg_ltv_cents ) }` }
                icon={ <IconCoins /> }
            />
        </div>
    );
}

function SegmentBreakdown( { segments } ) {
    const total = segments.reduce( ( s, r ) => s + r.donor_count, 0 ) || 1;
    return (
        <div className="dono-segments">
            <div className="dono-segments__bar" role="img" aria-label={ __( 'Donor segment distribution', 'dono' ) }>
                { segments.map( ( s ) => {
                    const meta = SEGMENT_META[ s.segment ] || SEGMENT_META.other;
                    const pct  = ( s.donor_count / total ) * 100;
                    if ( pct < 0.5 ) return null;
                    return (
                        <span
                            key={ s.segment }
                            className="dono-segments__bar-slice"
                            style={ { width: `${ pct }%`, background: meta.color } }
                            title={ `${ meta.label }: ${ s.donor_count } (${ pct.toFixed( 1 ) }%)` }
                        />
                    );
                } ) }
            </div>
            <table className="dono-segments__table">
                <thead>
                    <tr>
                        <th>{ __( 'Segment', 'dono' ) }</th>
                        <th className="dono-num">{ __( 'Donors', 'dono' ) }</th>
                        <th className="dono-num">{ __( '% of base', 'dono' ) }</th>
                        <th className="dono-num">{ __( 'Avg LTV', 'dono' ) }</th>
                        <th className="dono-num">{ __( 'Total LTV', 'dono' ) }</th>
                    </tr>
                </thead>
                <tbody>
                    { segments.map( ( s ) => {
                        const meta = SEGMENT_META[ s.segment ] || SEGMENT_META.other;
                        const pct  = ( s.donor_count / total ) * 100;
                        return (
                            <tr key={ s.segment }>
                                <td>
                                    <span className="dono-seg-chip" style={ { background: meta.color } } />
                                    { meta.label }
                                    <span className="dono-seg-hint"> · { meta.hint }</span>
                                </td>
                                <td className="dono-num">{ s.donor_count }</td>
                                <td className="dono-num">{ pct.toFixed( 1 ) }%</td>
                                <td className="dono-num">{ formatAmount( s.avg_ltv_cents ) }</td>
                                <td className="dono-num">{ formatAmount( s.total_ltv_cents ) }</td>
                            </tr>
                        );
                    } ) }
                </tbody>
            </table>
        </div>
    );
}

function LtvHistogram( { buckets } ) {
    const max = buckets.reduce( ( m, b ) => Math.max( m, b.donor_count ), 0 ) || 1;
    return (
        <div className="dono-ltv-hist">
            { buckets.map( ( b, i ) => {
                const h = ( b.donor_count / max ) * 100;
                return (
                    <div key={ i } className="dono-ltv-hist__col" title={ `${ b.donor_count } ${ __( 'donors', 'dono' ) }` }>
                        <div className="dono-ltv-hist__bar-wrap">
                            <div className="dono-ltv-hist__bar" style={ { height: `${ Math.max( h, 2 ) }%` } } />
                        </div>
                        <div className="dono-ltv-hist__count">{ b.donor_count }</div>
                        <div className="dono-ltv-hist__label">{ formatBucketLabel( b.min_cents, b.max_cents ) }</div>
                    </div>
                );
            } ) }
        </div>
    );
}

function donorHref( id ) {
    return `#donor/${ id }`;
}

function TopDonorsLeaderboard( { rows } ) {
    if ( ! rows.length ) {
        return (
            <EmptyState
                compact
                icon={ <UsersIcon size={ 22 } strokeWidth={ 1.75 } /> }
                title={ __( 'No donors yet', 'dono' ) }
                body={ __( 'The top donor leaderboard fills in as your first completed donations roll in.', 'dono' ) }
            />
        );
    }
    return (
        <table className="dono-top-donors">
            <thead>
                <tr>
                    <th>#</th>
                    <th>{ __( 'Donor', 'dono' ) }</th>
                    <th>{ __( 'Country', 'dono' ) }</th>
                    <th className="dono-num">{ __( 'Donations', 'dono' ) }</th>
                    <th className="dono-num">{ __( 'Total', 'dono' ) }</th>
                    <th>{ __( 'Last donation', 'dono' ) }</th>
                </tr>
            </thead>
            <tbody>
                { rows.map( ( r, i ) => (
                    <tr key={ r.id }>
                        <td className="dono-num dono-rank">{ i + 1 }</td>
                        <td><a href={ donorHref( r.id ) }>{ r.name }</a></td>
                        <td>{ r.country || '-' }</td>
                        <td className="dono-num">{ r.donations_count }</td>
                        <td className="dono-num">{ formatAmount( r.total_donated_cents ) }</td>
                        <td>{ formatDate( r.last_donation_at ) }</td>
                    </tr>
                ) ) }
            </tbody>
        </table>
    );
}

function CohortHeatmap( { retention } ) {
    const { cohorts, max_offset: maxOffset } = retention;
    if ( ! cohorts.length ) {
        return (
            <EmptyState
                compact
                icon={ <History size={ 22 } strokeWidth={ 1.75 } /> }
                title={ __( 'Not enough history yet', 'dono' ) }
                body={ __( 'Cohort retention needs at least one donation in a cohort month before the heatmap can render.', 'dono' ) }
            />
        );
    }
    const cols = Array.from( { length: maxOffset + 1 }, ( _, i ) => i );

    const cellStyle = ( pct ) => {
        if ( pct <= 0 ) return { background: 'var(--dono-bg-soft, #f3f4f6)', color: 'var(--dono-text-muted, #9ca3af)' };
        // Linear interpolation from light to accent green.
        const intensity = Math.min( 1, pct / 100 );
        const r = Math.round( 240 + ( 30 - 240 ) * intensity );
        const g = Math.round( 244 + ( 138 - 244 ) * intensity );
        const b = Math.round( 234 + ( 78 - 234 ) * intensity );
        const fg = intensity > 0.45 ? '#fff' : '#205c2d';
        return { background: `rgb(${ r },${ g },${ b })`, color: fg };
    };

    return (
        <div className="dono-cohort">
            <table className="dono-cohort__table">
                <thead>
                    <tr>
                        <th>{ __( 'Cohort', 'dono' ) }</th>
                        <th className="dono-num">{ __( 'Size', 'dono' ) }</th>
                        { cols.map( ( i ) => (
                            <th key={ i } className="dono-num">{ i === 0 ? __( 'M0', 'dono' ) : `+${ i }` }</th>
                        ) ) }
                    </tr>
                </thead>
                <tbody>
                    { cohorts.map( ( row ) => (
                        <tr key={ row.month }>
                            <td>{ row.month }</td>
                            <td className="dono-num">{ row.size }</td>
                            { cols.map( ( i ) => {
                                const cell = row.retention[ i ] || { pct: 0, count: 0 };
                                return (
                                    <td
                                        key={ i }
                                        className="dono-cohort__cell"
                                        style={ cellStyle( cell.pct ) }
                                        title={ `${ cell.count } / ${ row.size } (${ cell.pct }%)` }
                                    >
                                        { cell.pct > 0 ? `${ cell.pct }%` : '-' }
                                    </td>
                                );
                            } ) }
                        </tr>
                    ) ) }
                </tbody>
            </table>
        </div>
    );
}

function RecurringStrip( { recurring } ) {
    return (
        <div className="dono-overview__metrics">
            <MetricCard
                label={ __( 'Active recurring', 'dono' ) }
                value={ String( recurring.active_count ) }
                sub={ __( 'plans currently billing', 'dono' ) }
                icon={ <IconHeart /> }
            />
            <MetricCard
                label={ __( 'MRR', 'dono' ) }
                value={ formatAmount( recurring.mrr_cents ) }
                sub={ __( 'monthly-equivalent revenue', 'dono' ) }
                icon={ <IconCoins /> }
            />
            <MetricCard
                label={ __( 'New this month', 'dono' ) }
                value={ String( recurring.new_this_month ) }
                sub={ __( 'plans started', 'dono' ) }
                icon={ <IconActivity /> }
            />
            <MetricCard
                label={ __( 'Churn this month', 'dono' ) }
                value={ `${ recurring.churn_pct }%` }
                sub={ sprintf( /* translators: %d: count */ __( '%d cancellations', 'dono' ), recurring.churned_this_month ) }
                icon={ <IconActivity /> }
            />
        </div>
    );
}

function AtRiskTable() {
    const [ data, setData ]       = useState( null );
    const [ total, setTotal ]     = useState( 0 );
    const [ page, setPage ]       = useState( 1 );
    const [ loading, setLoading ] = useState( true );
    const [ error, setError ]     = useState( null );
    const perPage = 10;

    useEffect( () => {
        let aborted = false;
        setLoading( true );
        apiFetch( {
            path: `/dono/v1/admin/donors/at-risk?page=${ page }&per_page=${ perPage }`,
            parse: false,
        } )
            .then( async ( res ) => {
                if ( aborted ) return;
                const totalHeader = res.headers.get( 'X-WP-Total' );
                setTotal( Number( totalHeader || 0 ) );
                const rows = await res.json();
                setData( rows );
                setError( null );
            } )
            // On failure keep data null and record the error so we show a
            // problem, not the celebratory "no donors slipping" empty state.
            .catch( ( e ) => { if ( ! aborted ) setError( e?.message || __( 'Could not load at-risk donors.', 'dono' ) ); } )
            .finally( () => { if ( ! aborted ) setLoading( false ); } );
        return () => { aborted = true; };
    }, [ page ] );

    const pageCount = Math.max( 1, Math.ceil( total / perPage ) );

    return (
        <div className="dono-at-risk">
            <div className="dono-at-risk__head">
                <span className="dono-at-risk__count">
                    { total === 1
                        ? __( '1 at-risk donor', 'dono' )
                        : sprintf( /* translators: %s: count */ __( '%s at-risk donors', 'dono' ), total.toLocaleString() ) }
                </span>
                <button
                    type="button"
                    className="components-button is-secondary"
                    onClick={ () => downloadFile( '/dono/v1/admin/donors/at-risk/export', `dono-at-risk-${ new Date().toISOString().slice( 0, 10 ) }.csv` ).catch( ( e ) => notify.error( e?.message || __( 'Could not export the list.', 'dono' ) ) ) }
                >
                    { __( 'Export CSV', 'dono' ) }
                </button>
            </div>
            { loading && ! data && <p className="dono-loading">{ __( 'Loading…', 'dono' ) }</p> }
            { error && ! loading && <p className="dono-error">{ error }</p> }
            { ! error && data && data.length === 0 && <p className="dono-empty">{ __( 'No donors are slipping. Nice work.', 'dono' ) }</p> }
            { data && data.length > 0 && (
                <>
                    <table className="dono-table">
                        <thead>
                            <tr>
                                <th>{ __( 'Donor', 'dono' ) }</th>
                                <th>{ __( 'Email', 'dono' ) }</th>
                                <th>{ __( 'Country', 'dono' ) }</th>
                                <th className="dono-num">{ __( 'Total', 'dono' ) }</th>
                                <th>{ __( 'Last donation', 'dono' ) }</th>
                            </tr>
                        </thead>
                        <tbody>
                            { data.map( ( r ) => (
                                <tr key={ r.id }>
                                    <td><a href={ donorHref( r.id ) }>{ r.name }</a></td>
                                    <td>{ r.email || '-' }</td>
                                    <td>{ r.country || '-' }</td>
                                    <td className="dono-num">{ formatAmount( r.total_donated_cents ) }</td>
                                    <td>{ formatDate( r.last_donation_at ) }</td>
                                </tr>
                            ) ) }
                        </tbody>
                    </table>
                    { pageCount > 1 && (
                        <div className="dono-pagination">
                            <button type="button" disabled={ page <= 1 } onClick={ () => setPage( ( p ) => p - 1 ) }>
                                ← { __( 'Prev', 'dono' ) }
                            </button>
                            <span>{ sprintf( /* translators: 1: current page, 2: total pages */ __( 'Page %1$d of %2$d', 'dono' ), page, pageCount ) }</span>
                            <button type="button" disabled={ page >= pageCount } onClick={ () => setPage( ( p ) => p + 1 ) }>
                                { __( 'Next', 'dono' ) } →
                            </button>
                        </div>
                    ) }
                </>
            ) }
        </div>
    );
}

export default function Insights( { toggleSlot } ) {
    const [ data, setData ]       = useState( null );
    const [ loading, setLoading ] = useState( true );
    const [ error, setError ]     = useState( null );

    useEffect( () => {
        let aborted = false;
        setLoading( true );
        apiFetch( { path: '/dono/v1/admin/donors/insights' } )
            .then( ( d ) => { if ( ! aborted ) { setData( d ); setError( null ); } } )
            .catch( ( e ) => { if ( ! aborted ) setError( e?.message || 'Error' ); } )
            .finally( () => { if ( ! aborted ) setLoading( false ); } );
        return () => { aborted = true; };
    }, [] );

    if ( loading && ! data ) {
        return <p className="dono-loading">{ __( 'Loading insights…', 'dono' ) }</p>;
    }
    if ( error ) {
        return <p className="dono-error">{ error }</p>;
    }
    if ( ! data ) return null;

    return (
        <div className="dono-donor-insights" data-loading={ loading ? 'true' : undefined }>
            { toggleSlot && (
                <div className="dono-page-head">
                    <div className="dono-page-head__title-row">
                        <h1>{ __( 'Donors', 'dono' ) }</h1>
                    </div>
                    <div className="dono-page-head__right">{ toggleSlot }</div>
                </div>
            ) }
            <LifecycleKpis kpi={ data.kpi } />

            <WidgetCard title={ __( 'Recurring revenue', 'dono' ) }>
                <RecurringStrip recurring={ data.recurring } />
            </WidgetCard>

            <div className="dono-overview__grid">
                <WidgetCard title={ __( 'Donor segments', 'dono' ) }>
                    <SegmentBreakdown segments={ data.segments } />
                </WidgetCard>
                <WidgetCard title={ __( 'Lifetime value distribution', 'dono' ) }>
                    <LtvHistogram buckets={ data.ltv_buckets } />
                </WidgetCard>
            </div>

            <WidgetCard title={ __( 'Cohort retention', 'dono' ) }>
                <CohortHeatmap retention={ data.retention } />
            </WidgetCard>

            <WidgetCard title={ __( 'Needs attention: at-risk donors', 'dono' ) }>
                <AtRiskTable />
            </WidgetCard>

            <WidgetCard title={ __( 'Top donors by lifetime value', 'dono' ) }>
                <TopDonorsLeaderboard rows={ data.top_donors } />
            </WidgetCard>
        </div>
    );
}
