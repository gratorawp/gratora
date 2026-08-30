import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Users as UsersIcon, History } from 'lucide-react';

import EmptyState from '../_shared/components/EmptyState';
import Notice from '../_shared/components/Notice';
import MetricCard from '../_shared/widgets/MetricCard';
import { WidgetCard } from '../_shared/widgets/Widget';
import { formatAmount, formatAmountCompact, formatDate } from '../_shared/format';
import { IconUsers, IconHeart, IconActivity, IconCoins } from '../_shared/widgets/icons';

const SEGMENT_META = {
    champions:   { label: __( 'Champions',    'fundkit-fundraising-campaigns' ), color: 'var(--fundkit-seg-champions, #6f5ce6)',   hint: __( 'Active, frequent, high LTV', 'fundkit-fundraising-campaigns' ) },
    loyal:       { label: __( 'Loyal',        'fundkit-fundraising-campaigns' ), color: 'var(--fundkit-seg-loyal, #0a9bab)',       hint: __( 'Active, 2+ donations',        'fundkit-fundraising-campaigns' ) },
    new:         { label: __( 'New',          'fundkit-fundraising-campaigns' ), color: 'var(--fundkit-seg-new, #1f7fb8)',         hint: __( 'Joined recently',             'fundkit-fundraising-campaigns' ) },
    at_risk:     { label: __( 'At risk',      'fundkit-fundraising-campaigns' ), color: 'var(--fundkit-seg-at-risk, #b08a12)',     hint: __( 'Was active, slowing down',    'fundkit-fundraising-campaigns' ) },
    hibernating: { label: __( 'Hibernating',  'fundkit-fundraising-campaigns' ), color: 'var(--fundkit-seg-hibernating, #9a3fb0)', hint: __( 'Long lapsed, low LTV',        'fundkit-fundraising-campaigns' ) },
    lost:        { label: __( 'Lost',         'fundkit-fundraising-campaigns' ), color: 'var(--fundkit-seg-lost, #c25050)',        hint: __( '> 12 months silent',           'fundkit-fundraising-campaigns' ) },
    other:       { label: __( 'Other',        'fundkit-fundraising-campaigns' ), color: 'var(--fundkit-seg-other, #6b7280)',       hint: __( 'Uncategorised',               'fundkit-fundraising-campaigns' ) },
};

function formatBucketLabel( min, max ) {
    if ( max === null ) return `> ${ formatAmountCompact( min - 1 ) }`;
    if ( min === 1 ) return `${ formatAmountCompact( 0 ) }-${ formatAmountCompact( max ) }`;
    return `${ formatAmountCompact( min - 1 ) }-${ formatAmountCompact( max ) }`;
}

function LifecycleKpis( { kpi } ) {
    return (
        <div className="fundkit-overview__metrics">
            <MetricCard
                label={ __( 'Total donors', 'fundkit-fundraising-campaigns' ) }
                value={ String( kpi.total ) }
                sub={ kpi.new ? `+${ kpi.new } ${ __( 'new (30d)', 'fundkit-fundraising-campaigns' ) }` : __( 'no new donors', 'fundkit-fundraising-campaigns' ) }
                icon={ <IconUsers /> }
            />
            <MetricCard
                label={ __( 'Active', 'fundkit-fundraising-campaigns' ) }
                value={ String( kpi.active ) }
                sub={ `${ kpi.active_pct }% ${ __( 'of base · gave in 90d', 'fundkit-fundraising-campaigns' ) }` }
                icon={ <IconHeart /> }
            />
            <MetricCard
                label={ __( 'At risk', 'fundkit-fundraising-campaigns' ) }
                value={ String( kpi.at_risk ) }
                sub={ `${ kpi.at_risk_pct }% ${ __( 'silent 90-180d', 'fundkit-fundraising-campaigns' ) }` }
                icon={ <IconActivity /> }
            />
            <MetricCard
                label={ __( 'Lapsed', 'fundkit-fundraising-campaigns' ) }
                value={ String( kpi.lapsed ) }
                sub={ `${ kpi.lapsed_pct }% ${ __( 'silent 180-365d', 'fundkit-fundraising-campaigns' ) }` }
                icon={ <IconActivity /> }
            />
            <MetricCard
                label={ __( 'Lost', 'fundkit-fundraising-campaigns' ) }
                value={ String( kpi.lost ) }
                sub={ `${ kpi.lost_pct }% ${ __( '> 365d silent', 'fundkit-fundraising-campaigns' ) }` }
                icon={ <IconActivity /> }
            />
            <MetricCard
                label={ __( 'Median LTV', 'fundkit-fundraising-campaigns' ) }
                value={ formatAmount( kpi.median_ltv_cents ) }
                sub={ `${ __( 'avg', 'fundkit-fundraising-campaigns' ) } ${ formatAmount( kpi.avg_ltv_cents ) }` }
                icon={ <IconCoins /> }
            />
        </div>
    );
}

function SegmentBreakdown( { segments } ) {
    const total = segments.reduce( ( s, r ) => s + r.donor_count, 0 ) || 1;
    return (
        <div className="fundkit-segments">
            <div className="fundkit-segments__bar" role="img" aria-label={ __( 'Donor segment distribution', 'fundkit-fundraising-campaigns' ) }>
                { segments.map( ( s ) => {
                    const meta = SEGMENT_META[ s.segment ] || SEGMENT_META.other;
                    const pct  = ( s.donor_count / total ) * 100;
                    if ( pct < 0.5 ) return null;
                    return (
                        <span
                            key={ s.segment }
                            className="fundkit-segments__bar-slice"
                            style={ { width: `${ pct }%`, background: meta.color } }
                            title={ `${ meta.label }: ${ s.donor_count } (${ pct.toFixed( 1 ) }%)` }
                        />
                    );
                } ) }
            </div>
            <table className="fundkit-segments__table">
                <thead>
                    <tr>
                        <th>{ __( 'Segment', 'fundkit-fundraising-campaigns' ) }</th>
                        <th className="fundkit-num">{ __( 'Donors', 'fundkit-fundraising-campaigns' ) }</th>
                        <th className="fundkit-num">{ __( '% of base', 'fundkit-fundraising-campaigns' ) }</th>
                        <th className="fundkit-num">{ __( 'Avg LTV', 'fundkit-fundraising-campaigns' ) }</th>
                        <th className="fundkit-num">{ __( 'Total LTV', 'fundkit-fundraising-campaigns' ) }</th>
                    </tr>
                </thead>
                <tbody>
                    { segments.map( ( s ) => {
                        const meta = SEGMENT_META[ s.segment ] || SEGMENT_META.other;
                        const pct  = ( s.donor_count / total ) * 100;
                        return (
                            <tr key={ s.segment }>
                                <td>
                                    <span className="fundkit-seg-chip" style={ { background: meta.color } } />
                                    { meta.label }
                                    <span className="fundkit-seg-hint"> · { meta.hint }</span>
                                </td>
                                <td className="fundkit-num">{ s.donor_count }</td>
                                <td className="fundkit-num">{ pct.toFixed( 1 ) }%</td>
                                <td className="fundkit-num">{ formatAmount( s.avg_ltv_cents ) }</td>
                                <td className="fundkit-num">{ formatAmount( s.total_ltv_cents ) }</td>
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
        <div className="fundkit-ltv-hist">
            { buckets.map( ( b ) => {
                const h = ( b.donor_count / max ) * 100;
                return (
                    <div key={ b.min_cents } className="fundkit-ltv-hist__col" title={ `${ b.donor_count } ${ __( 'donors', 'fundkit-fundraising-campaigns' ) }` }>
                        <div className="fundkit-ltv-hist__bar-wrap">
                            <div className="fundkit-ltv-hist__bar" style={ { height: `${ Math.max( h, 2 ) }%` } } />
                        </div>
                        <div className="fundkit-ltv-hist__count">{ b.donor_count }</div>
                        <div className="fundkit-ltv-hist__label">{ formatBucketLabel( b.min_cents, b.max_cents ) }</div>
                    </div>
                );
            } ) }
        </div>
    );
}

function donorHref( id ) {
    return `#donor/${ id }`;
}

// Deliberately not SEGMENT_META's colours: every row here is already the
// at-risk segment, so segment colours on this pill would argue with the
// segment chart on the same screen. These tones are about urgency.
const REASON_TONE = {
    plan_failing:    'is-error',
    plan_paused:     'is-info',
    plan_cancelled:  'is-warn',
    plan_active:     'is-ok',
    first_donation_only: 'is-violet',
    no_gap_yet:      'is-muted',
    well_past_gap:   'is-warn',
    past_gap:        'is-info',
    within_gap:      'is-ok',
};

function ReasonPill( { row } ) {
    if ( ! row.risk_reason_label ) return '-';
    const title = row.avg_gap_days
        ? sprintf(
            /* translators: %d: number of days */
            _n(
                'About %d day between donations, on average.',
                'About %d days between donations, on average.',
                row.avg_gap_days,
                'fundkit-fundraising-campaigns'
            ),
            row.avg_gap_days
        )
        : undefined;
    return (
        <span className={ `dp-pill ${ REASON_TONE[ row.risk_reason ] || 'is-muted' }` } title={ title }>
            { row.risk_reason_label }
        </span>
    );
}

// One table for both donor lists. They were two, and drifted: the same donor
// rendered as a link in one and as plain text in the other.
// showReason is opt-in rather than sniffed from the rows: the leaderboard
// shares this table and has no reason to carry.
function DonorTable( { rows, showReason } ) {
    return (
        <table className="fundkit-table">
            <thead>
                <tr>
                    <th>{ __( 'Donor', 'fundkit-fundraising-campaigns' ) }</th>
                    <th>{ __( 'Email', 'fundkit-fundraising-campaigns' ) }</th>
                    <th>{ __( 'Country', 'fundkit-fundraising-campaigns' ) }</th>
                    <th className="fundkit-num">{ __( 'Donations', 'fundkit-fundraising-campaigns' ) }</th>
                    <th className="fundkit-num">{ __( 'Total', 'fundkit-fundraising-campaigns' ) }</th>
                    <th className="fundkit-date">{ __( 'Last donation', 'fundkit-fundraising-campaigns' ) }</th>
                    { showReason && <th className="fundkit-why">{ __( 'Why', 'fundkit-fundraising-campaigns' ) }</th> }
                </tr>
            </thead>
            <tbody>
                { rows.map( ( r ) => (
                    <tr key={ r.id }>
                        <td>
                            <a className="fundkit-row__link fundkit-row__link--strong" href={ donorHref( r.id ) }>
                                { r.name }
                            </a>
                        </td>
                        <td>{ r.email || '-' }</td>
                        <td>{ r.country || '-' }</td>
                        <td className="fundkit-num">{ r.donations_count ?? '-' }</td>
                        <td className="fundkit-num">{ formatAmount( r.total_donated_cents ) }</td>
                        <td className="fundkit-date">{ formatDate( r.last_donation_at ) }</td>
                        { showReason && <td className="fundkit-why"><ReasonPill row={ r } /></td> }
                    </tr>
                ) ) }
            </tbody>
        </table>
    );
}

function TopDonorsLeaderboard( { rows } ) {
    if ( ! rows.length ) {
        return (
            <EmptyState
                compact
                icon={ <UsersIcon size={ 22 } strokeWidth={ 1.75 } /> }
                title={ __( 'No donors yet', 'fundkit-fundraising-campaigns' ) }
                body={ __( 'The top donor leaderboard fills in as your first completed donations roll in.', 'fundkit-fundraising-campaigns' ) }
            />
        );
    }
    return <DonorTable rows={ rows } />;
}

function CohortHeatmap( { retention } ) {
    const { cohorts, max_offset: maxOffset } = retention;
    if ( ! cohorts.length ) {
        return (
            <EmptyState
                compact
                icon={ <History size={ 22 } strokeWidth={ 1.75 } /> }
                title={ __( 'Not enough history yet', 'fundkit-fundraising-campaigns' ) }
                body={ __( 'Cohort retention needs at least one donation in a cohort month before the heatmap can render.', 'fundkit-fundraising-campaigns' ) }
            />
        );
    }
    const cols = Array.from( { length: maxOffset + 1 }, ( _, i ) => i );

    const cellStyle = ( pct ) => {
        if ( pct <= 0 ) return { background: 'var(--fundkit-bg-soft, #f3f4f6)', color: 'var(--fundkit-text-muted, #6b7280)' };
        // One hue, light to dark, so the cell reads as magnitude.
        const intensity = Math.min( 1, pct / 100 );
        const r = Math.round( 245 + ( 111 - 245 ) * intensity );
        const g = Math.round( 244 + ( 92 - 244 ) * intensity );
        const b = Math.round( 250 + ( 230 - 250 ) * intensity );
        const fg = intensity > 0.45 ? '#fff' : '#211d3f';
        return { background: `rgb(${ r },${ g },${ b })`, color: fg };
    };

    return (
        <div className="fundkit-cohort">
            <table className="fundkit-cohort__table">
                <thead>
                    <tr>
                        <th>{ __( 'Cohort', 'fundkit-fundraising-campaigns' ) }</th>
                        <th className="fundkit-num">{ __( 'Size', 'fundkit-fundraising-campaigns' ) }</th>
                        { cols.map( ( i ) => (
                            <th key={ i } className="fundkit-num">{ i === 0 ? __( 'M0', 'fundkit-fundraising-campaigns' ) : `+${ i }` }</th>
                        ) ) }
                    </tr>
                </thead>
                <tbody>
                    { cohorts.map( ( row ) => (
                        <tr key={ row.month }>
                            <td>{ row.month }</td>
                            <td className="fundkit-num">{ row.size }</td>
                            { cols.map( ( i ) => {
                                const cell = row.retention[ i ] || { pct: 0, count: 0 };
                                return (
                                    <td
                                        key={ i }
                                        className="fundkit-cohort__cell"
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
        <div className="fundkit-overview__metrics">
            <MetricCard
                label={ __( 'Active recurring', 'fundkit-fundraising-campaigns' ) }
                value={ String( recurring.active_count ) }
                sub={ __( 'plans currently billing', 'fundkit-fundraising-campaigns' ) }
                icon={ <IconHeart /> }
            />
            <MetricCard
                label={ __( 'MRR', 'fundkit-fundraising-campaigns' ) }
                value={ formatAmount( recurring.mrr_cents ) }
                sub={ __( 'monthly-equivalent revenue', 'fundkit-fundraising-campaigns' ) }
                icon={ <IconCoins /> }
            />
            <MetricCard
                label={ __( 'New this month', 'fundkit-fundraising-campaigns' ) }
                value={ String( recurring.new_this_month ) }
                sub={ __( 'plans started', 'fundkit-fundraising-campaigns' ) }
                icon={ <IconActivity /> }
            />
            <MetricCard
                label={ __( 'Churn this month', 'fundkit-fundraising-campaigns' ) }
                value={ `${ recurring.churn_pct }%` }
                sub={ sprintf( /* translators: %d: count */ __( '%d cancellations', 'fundkit-fundraising-campaigns' ), recurring.churned_this_month ) }
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
            path: `/fundkit/v1/admin/donors/at-risk?page=${ page }&per_page=${ perPage }`,
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
            .catch( ( e ) => { if ( ! aborted ) setError( e?.message || __( 'Could not load at-risk donors.', 'fundkit-fundraising-campaigns' ) ); } )
            .finally( () => { if ( ! aborted ) setLoading( false ); } );
        return () => { aborted = true; };
    }, [ page ] );

    const pageCount = Math.max( 1, Math.ceil( total / perPage ) );

    return (
        <div className="fundkit-at-risk">
            { total > 0 && (
                <div className="fundkit-at-risk__head">
                    <span className="fundkit-at-risk__count">
                        { total === 1
                            ? __( '1 at-risk donor', 'fundkit-fundraising-campaigns' )
                            : sprintf( /* translators: %s: count */ __( '%s at-risk donors', 'fundkit-fundraising-campaigns' ), total.toLocaleString() ) }
                    </span>
                </div>
            ) }
            { loading && ! data && <p className="fundkit-loading">{ __( 'Loading…', 'fundkit-fundraising-campaigns' ) }</p> }
            { error && ! loading && <p className="fundkit-error">{ error }</p> }
            { ! error && data && data.length === 0 && (
                <EmptyState
                    compact
                    icon={ <UsersIcon size={ 22 } strokeWidth={ 1.75 } /> }
                    title={ __( 'No donors are slipping', 'fundkit-fundraising-campaigns' ) }
                    body={ __( 'Donors appear here when they have gone quiet for longer than usual, so you can reach them before they lapse.', 'fundkit-fundraising-campaigns' ) }
                />
            ) }
            { data && data.length > 0 && (
                <>
                    <div className="fundkit-at-risk__scroll">
                        <DonorTable rows={ data } showReason />
                    </div>
                    { pageCount > 1 && (
                        <div className="fundkit-pagination">
                            <button type="button" disabled={ page <= 1 } onClick={ () => setPage( ( p ) => p - 1 ) }>
                                ← { __( 'Prev', 'fundkit-fundraising-campaigns' ) }
                            </button>
                            <span>{ sprintf( /* translators: 1: current page, 2: total pages */ __( 'Page %1$d of %2$d', 'fundkit-fundraising-campaigns' ), page, pageCount ) }</span>
                            <button type="button" disabled={ page >= pageCount } onClick={ () => setPage( ( p ) => p + 1 ) }>
                                { __( 'Next', 'fundkit-fundraising-campaigns' ) } →
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
        apiFetch( { path: '/fundkit/v1/admin/donors/insights' } )
            .then( ( d ) => { if ( ! aborted ) { setData( d ); setError( null ); } } )
            .catch( ( e ) => { if ( ! aborted ) setError( e?.message || 'Error' ); } )
            .finally( () => { if ( ! aborted ) setLoading( false ); } );
        return () => { aborted = true; };
    }, [] );

    if ( loading && ! data ) {
        return <p className="fundkit-loading">{ __( 'Loading insights…', 'fundkit-fundraising-campaigns' ) }</p>;
    }
    if ( error ) {
        return <p className="fundkit-error">{ error }</p>;
    }
    if ( ! data ) return null;

    return (
        <div className="fundkit-donor-insights" data-loading={ loading ? 'true' : undefined }>
            { toggleSlot && (
                <div className="fundkit-page-head">
                    <div className="fundkit-page-head__title-row">
                        <h1>{ __( 'Donors', 'fundkit-fundraising-campaigns' ) }</h1>
                    </div>
                    <div className="fundkit-page-head__right">{ toggleSlot }</div>
                </div>
            ) }
            { ( data.test?.test_only_donors || 0 ) > 0 && (
                <Notice status="info" isDismissible={ false }>
                    { sprintf(
                        /* translators: %d: donors whose donations are all test-mode. */
                        _n(
                            '%d donor has only test donations, so they are not in this analysis.',
                            '%d donors have only test donations, so they are not in this analysis.',
                            data.test.test_only_donors,
                            'fundkit-fundraising-campaigns'
                        ),
                        data.test.test_only_donors
                    ) }
                    { ' ' }
                    { __( 'Lifetime value, segments and retention are built from money actually taken, so there is no test version of them.', 'fundkit-fundraising-campaigns' ) }
                </Notice>
            ) }

            <LifecycleKpis kpi={ data.kpi } />

            <WidgetCard title={ __( 'Recurring revenue', 'fundkit-fundraising-campaigns' ) }>
                <RecurringStrip recurring={ data.recurring } />
            </WidgetCard>

            <div className="fundkit-overview__grid">
                <WidgetCard title={ __( 'Donor segments', 'fundkit-fundraising-campaigns' ) }>
                    <SegmentBreakdown segments={ data.segments } />
                </WidgetCard>
                <WidgetCard title={ __( 'Lifetime value distribution', 'fundkit-fundraising-campaigns' ) }>
                    <LtvHistogram buckets={ data.ltv_buckets } />
                </WidgetCard>
            </div>

            <WidgetCard title={ __( 'Cohort retention', 'fundkit-fundraising-campaigns' ) }>
                <CohortHeatmap retention={ data.retention } />
            </WidgetCard>

            <WidgetCard title={ __( 'Needs attention: at-risk donors', 'fundkit-fundraising-campaigns' ) }>
                <AtRiskTable />
            </WidgetCard>

            <WidgetCard title={ __( 'Top donors by lifetime value', 'fundkit-fundraising-campaigns' ) }>
                <TopDonorsLeaderboard rows={ data.top_donors } />
            </WidgetCard>
        </div>
    );
}
