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
    champions:   { label: __( 'Champions',    'dono-fundraising-platform' ), color: 'var(--dono-seg-champions, #16a34a)',   hint: __( 'Active, frequent, high LTV', 'dono-fundraising-platform' ) },
    loyal:       { label: __( 'Loyal',        'dono-fundraising-platform' ), color: 'var(--dono-seg-loyal, #0891b2)',       hint: __( 'Active, 2+ donations',        'dono-fundraising-platform' ) },
    new:         { label: __( 'New',          'dono-fundraising-platform' ), color: 'var(--dono-seg-new, #7c3aed)',         hint: __( 'Joined recently',             'dono-fundraising-platform' ) },
    at_risk:     { label: __( 'At risk',      'dono-fundraising-platform' ), color: 'var(--dono-seg-at-risk, #f59e0b)',     hint: __( 'Was active, slowing down',    'dono-fundraising-platform' ) },
    hibernating: { label: __( 'Hibernating',  'dono-fundraising-platform' ), color: 'var(--dono-seg-hibernating, #a16207)', hint: __( 'Long lapsed, low LTV',        'dono-fundraising-platform' ) },
    lost:        { label: __( 'Lost',         'dono-fundraising-platform' ), color: 'var(--dono-seg-lost, #dc2626)',        hint: __( '> 12 months silent',           'dono-fundraising-platform' ) },
    other:       { label: __( 'Other',        'dono-fundraising-platform' ), color: 'var(--dono-seg-other, #6b7280)',       hint: __( 'Uncategorised',               'dono-fundraising-platform' ) },
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
                label={ __( 'Total donors', 'dono-fundraising-platform' ) }
                value={ String( kpi.total ) }
                sub={ kpi.new ? `+${ kpi.new } ${ __( 'new (30d)', 'dono-fundraising-platform' ) }` : __( 'no new donors', 'dono-fundraising-platform' ) }
                icon={ <IconUsers /> }
            />
            <MetricCard
                label={ __( 'Active', 'dono-fundraising-platform' ) }
                value={ String( kpi.active ) }
                sub={ `${ kpi.active_pct }% ${ __( 'of base · gave in 90d', 'dono-fundraising-platform' ) }` }
                icon={ <IconHeart /> }
            />
            <MetricCard
                label={ __( 'At risk', 'dono-fundraising-platform' ) }
                value={ String( kpi.at_risk ) }
                sub={ `${ kpi.at_risk_pct }% ${ __( 'silent 90-180d', 'dono-fundraising-platform' ) }` }
                icon={ <IconActivity /> }
            />
            <MetricCard
                label={ __( 'Lapsed', 'dono-fundraising-platform' ) }
                value={ String( kpi.lapsed ) }
                sub={ `${ kpi.lapsed_pct }% ${ __( 'silent 180-365d', 'dono-fundraising-platform' ) }` }
                icon={ <IconActivity /> }
            />
            <MetricCard
                label={ __( 'Lost', 'dono-fundraising-platform' ) }
                value={ String( kpi.lost ) }
                sub={ `${ kpi.lost_pct }% ${ __( '> 365d silent', 'dono-fundraising-platform' ) }` }
                icon={ <IconActivity /> }
            />
            <MetricCard
                label={ __( 'Median LTV', 'dono-fundraising-platform' ) }
                value={ formatAmount( kpi.median_ltv_cents ) }
                sub={ `${ __( 'avg', 'dono-fundraising-platform' ) } ${ formatAmount( kpi.avg_ltv_cents ) }` }
                icon={ <IconCoins /> }
            />
        </div>
    );
}

function SegmentBreakdown( { segments } ) {
    const total = segments.reduce( ( s, r ) => s + r.donor_count, 0 ) || 1;
    return (
        <div className="dono-segments">
            <div className="dono-segments__bar" role="img" aria-label={ __( 'Donor segment distribution', 'dono-fundraising-platform' ) }>
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
                        <th>{ __( 'Segment', 'dono-fundraising-platform' ) }</th>
                        <th className="dono-num">{ __( 'Donors', 'dono-fundraising-platform' ) }</th>
                        <th className="dono-num">{ __( '% of base', 'dono-fundraising-platform' ) }</th>
                        <th className="dono-num">{ __( 'Avg LTV', 'dono-fundraising-platform' ) }</th>
                        <th className="dono-num">{ __( 'Total LTV', 'dono-fundraising-platform' ) }</th>
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
            { buckets.map( ( b ) => {
                const h = ( b.donor_count / max ) * 100;
                return (
                    <div key={ b.min_cents } className="dono-ltv-hist__col" title={ `${ b.donor_count } ${ __( 'donors', 'dono-fundraising-platform' ) }` }>
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

// Deliberately not SEGMENT_META's colours: every row here is already the
// at-risk segment, so segment colours on this pill would argue with the
// segment chart on the same screen. These tones are about urgency.
const REASON_TONE = {
    plan_failing:    'is-error',
    plan_paused:     'is-info',
    plan_cancelled:  'is-warn',
    plan_active:     'is-ok',
    first_gift_only: 'is-violet',
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
                'About %d day between gifts, on average.',
                'About %d days between gifts, on average.',
                row.avg_gap_days,
                'dono-fundraising-platform'
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
        <table className="dono-table">
            <thead>
                <tr>
                    <th>{ __( 'Donor', 'dono-fundraising-platform' ) }</th>
                    <th>{ __( 'Email', 'dono-fundraising-platform' ) }</th>
                    <th>{ __( 'Country', 'dono-fundraising-platform' ) }</th>
                    <th className="dono-num">{ __( 'Donations', 'dono-fundraising-platform' ) }</th>
                    <th className="dono-num">{ __( 'Total', 'dono-fundraising-platform' ) }</th>
                    <th className="dono-date">{ __( 'Last donation', 'dono-fundraising-platform' ) }</th>
                    { showReason && <th className="dono-why">{ __( 'Why', 'dono-fundraising-platform' ) }</th> }
                </tr>
            </thead>
            <tbody>
                { rows.map( ( r ) => (
                    <tr key={ r.id }>
                        <td>
                            <a className="dono-row__link dono-row__link--strong" href={ donorHref( r.id ) }>
                                { r.name }
                            </a>
                        </td>
                        <td>{ r.email || '-' }</td>
                        <td>{ r.country || '-' }</td>
                        <td className="dono-num">{ r.donations_count ?? '-' }</td>
                        <td className="dono-num">{ formatAmount( r.total_donated_cents ) }</td>
                        <td className="dono-date">{ formatDate( r.last_donation_at ) }</td>
                        { showReason && <td className="dono-why"><ReasonPill row={ r } /></td> }
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
                title={ __( 'No donors yet', 'dono-fundraising-platform' ) }
                body={ __( 'The top donor leaderboard fills in as your first completed donations roll in.', 'dono-fundraising-platform' ) }
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
                title={ __( 'Not enough history yet', 'dono-fundraising-platform' ) }
                body={ __( 'Cohort retention needs at least one donation in a cohort month before the heatmap can render.', 'dono-fundraising-platform' ) }
            />
        );
    }
    const cols = Array.from( { length: maxOffset + 1 }, ( _, i ) => i );

    const cellStyle = ( pct ) => {
        if ( pct <= 0 ) return { background: 'var(--dono-bg-soft, #f3f4f6)', color: 'var(--dono-text-muted, #6b7280)' };
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
                        <th>{ __( 'Cohort', 'dono-fundraising-platform' ) }</th>
                        <th className="dono-num">{ __( 'Size', 'dono-fundraising-platform' ) }</th>
                        { cols.map( ( i ) => (
                            <th key={ i } className="dono-num">{ i === 0 ? __( 'M0', 'dono-fundraising-platform' ) : `+${ i }` }</th>
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
                label={ __( 'Active recurring', 'dono-fundraising-platform' ) }
                value={ String( recurring.active_count ) }
                sub={ __( 'plans currently billing', 'dono-fundraising-platform' ) }
                icon={ <IconHeart /> }
            />
            <MetricCard
                label={ __( 'MRR', 'dono-fundraising-platform' ) }
                value={ formatAmount( recurring.mrr_cents ) }
                sub={ __( 'monthly-equivalent revenue', 'dono-fundraising-platform' ) }
                icon={ <IconCoins /> }
            />
            <MetricCard
                label={ __( 'New this month', 'dono-fundraising-platform' ) }
                value={ String( recurring.new_this_month ) }
                sub={ __( 'plans started', 'dono-fundraising-platform' ) }
                icon={ <IconActivity /> }
            />
            <MetricCard
                label={ __( 'Churn this month', 'dono-fundraising-platform' ) }
                value={ `${ recurring.churn_pct }%` }
                sub={ sprintf( /* translators: %d: count */ __( '%d cancellations', 'dono-fundraising-platform' ), recurring.churned_this_month ) }
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
            .catch( ( e ) => { if ( ! aborted ) setError( e?.message || __( 'Could not load at-risk donors.', 'dono-fundraising-platform' ) ); } )
            .finally( () => { if ( ! aborted ) setLoading( false ); } );
        return () => { aborted = true; };
    }, [ page ] );

    const pageCount = Math.max( 1, Math.ceil( total / perPage ) );

    return (
        <div className="dono-at-risk">
            { total > 0 && (
                <div className="dono-at-risk__head">
                    <span className="dono-at-risk__count">
                        { total === 1
                            ? __( '1 at-risk donor', 'dono-fundraising-platform' )
                            : sprintf( /* translators: %s: count */ __( '%s at-risk donors', 'dono-fundraising-platform' ), total.toLocaleString() ) }
                    </span>
                </div>
            ) }
            { loading && ! data && <p className="dono-loading">{ __( 'Loading…', 'dono-fundraising-platform' ) }</p> }
            { error && ! loading && <p className="dono-error">{ error }</p> }
            { ! error && data && data.length === 0 && (
                <EmptyState
                    compact
                    icon={ <UsersIcon size={ 22 } strokeWidth={ 1.75 } /> }
                    title={ __( 'No donors are slipping', 'dono-fundraising-platform' ) }
                    body={ __( 'Donors appear here when they have gone quiet for longer than usual, so you can reach them before they lapse.', 'dono-fundraising-platform' ) }
                />
            ) }
            { data && data.length > 0 && (
                <>
                    <div className="dono-at-risk__scroll">
                        <DonorTable rows={ data } showReason />
                    </div>
                    { pageCount > 1 && (
                        <div className="dono-pagination">
                            <button type="button" disabled={ page <= 1 } onClick={ () => setPage( ( p ) => p - 1 ) }>
                                ← { __( 'Prev', 'dono-fundraising-platform' ) }
                            </button>
                            <span>{ sprintf( /* translators: 1: current page, 2: total pages */ __( 'Page %1$d of %2$d', 'dono-fundraising-platform' ), page, pageCount ) }</span>
                            <button type="button" disabled={ page >= pageCount } onClick={ () => setPage( ( p ) => p + 1 ) }>
                                { __( 'Next', 'dono-fundraising-platform' ) } →
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
        return <p className="dono-loading">{ __( 'Loading insights…', 'dono-fundraising-platform' ) }</p>;
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
                        <h1>{ __( 'Donors', 'dono-fundraising-platform' ) }</h1>
                    </div>
                    <div className="dono-page-head__right">{ toggleSlot }</div>
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
                            'dono-fundraising-platform'
                        ),
                        data.test.test_only_donors
                    ) }
                    { ' ' }
                    { __( 'Lifetime value, segments and retention are built from money actually taken, so there is no test version of them.', 'dono-fundraising-platform' ) }
                </Notice>
            ) }

            <LifecycleKpis kpi={ data.kpi } />

            <WidgetCard title={ __( 'Recurring revenue', 'dono-fundraising-platform' ) }>
                <RecurringStrip recurring={ data.recurring } />
            </WidgetCard>

            <div className="dono-overview__grid">
                <WidgetCard title={ __( 'Donor segments', 'dono-fundraising-platform' ) }>
                    <SegmentBreakdown segments={ data.segments } />
                </WidgetCard>
                <WidgetCard title={ __( 'Lifetime value distribution', 'dono-fundraising-platform' ) }>
                    <LtvHistogram buckets={ data.ltv_buckets } />
                </WidgetCard>
            </div>

            <WidgetCard title={ __( 'Cohort retention', 'dono-fundraising-platform' ) }>
                <CohortHeatmap retention={ data.retention } />
            </WidgetCard>

            <WidgetCard title={ __( 'Needs attention: at-risk donors', 'dono-fundraising-platform' ) }>
                <AtRiskTable />
            </WidgetCard>

            <WidgetCard title={ __( 'Top donors by lifetime value', 'dono-fundraising-platform' ) }>
                <TopDonorsLeaderboard rows={ data.top_donors } />
            </WidgetCard>
        </div>
    );
}
