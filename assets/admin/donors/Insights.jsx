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
    champions:   { label: __( 'Champions',    'fundraising-toolkit' ), color: 'var(--fundkit-seg-champions, #6f5ce6)',   hint: __( 'Active, frequent, high LTV', 'fundraising-toolkit' ) },
    loyal:       { label: __( 'Loyal',        'fundraising-toolkit' ), color: 'var(--fundkit-seg-loyal, #0a9bab)',       hint: __( 'Active, 2+ donations',        'fundraising-toolkit' ) },
    new:         { label: __( 'New',          'fundraising-toolkit' ), color: 'var(--fundkit-seg-new, #1f7fb8)',         hint: __( 'Joined recently',             'fundraising-toolkit' ) },
    at_risk:     { label: __( 'At risk',      'fundraising-toolkit' ), color: 'var(--fundkit-seg-at-risk, #b08a12)',     hint: __( 'Was active, slowing down',    'fundraising-toolkit' ) },
    hibernating: { label: __( 'Hibernating',  'fundraising-toolkit' ), color: 'var(--fundkit-seg-hibernating, #9a3fb0)', hint: __( 'Long lapsed, low LTV',        'fundraising-toolkit' ) },
    lost:        { label: __( 'Lost',         'fundraising-toolkit' ), color: 'var(--fundkit-seg-lost, #c25050)',        hint: __( '> 12 months silent',           'fundraising-toolkit' ) },
    other:       { label: __( 'Other',        'fundraising-toolkit' ), color: 'var(--fundkit-seg-other, #6b7280)',       hint: __( 'Uncategorised',               'fundraising-toolkit' ) },
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
                label={ __( 'Total donors', 'fundraising-toolkit' ) }
                value={ String( kpi.total ) }
                sub={ kpi.new ? `+${ kpi.new } ${ __( 'new (30d)', 'fundraising-toolkit' ) }` : __( 'no new donors', 'fundraising-toolkit' ) }
                icon={ <IconUsers /> }
            />
            <MetricCard
                label={ __( 'Active', 'fundraising-toolkit' ) }
                value={ String( kpi.active ) }
                sub={ `${ kpi.active_pct }% ${ __( 'of base · gave in 90d', 'fundraising-toolkit' ) }` }
                icon={ <IconHeart /> }
            />
            <MetricCard
                label={ __( 'At risk', 'fundraising-toolkit' ) }
                value={ String( kpi.at_risk ) }
                sub={ `${ kpi.at_risk_pct }% ${ __( 'silent 90-180d', 'fundraising-toolkit' ) }` }
                icon={ <IconActivity /> }
            />
            <MetricCard
                label={ __( 'Lapsed', 'fundraising-toolkit' ) }
                value={ String( kpi.lapsed ) }
                sub={ `${ kpi.lapsed_pct }% ${ __( 'silent 180-365d', 'fundraising-toolkit' ) }` }
                icon={ <IconActivity /> }
            />
            <MetricCard
                label={ __( 'Lost', 'fundraising-toolkit' ) }
                value={ String( kpi.lost ) }
                sub={ `${ kpi.lost_pct }% ${ __( '> 365d silent', 'fundraising-toolkit' ) }` }
                icon={ <IconActivity /> }
            />
            <MetricCard
                label={ __( 'Median LTV', 'fundraising-toolkit' ) }
                value={ formatAmount( kpi.median_ltv_cents ) }
                sub={ `${ __( 'avg', 'fundraising-toolkit' ) } ${ formatAmount( kpi.avg_ltv_cents ) }` }
                icon={ <IconCoins /> }
            />
        </div>
    );
}

function SegmentBreakdown( { segments } ) {
    const total = segments.reduce( ( s, r ) => s + r.donor_count, 0 ) || 1;
    return (
        <div className="fundkit-segments">
            <div className="fundkit-segments__bar" role="img" aria-label={ __( 'Donor segment distribution', 'fundraising-toolkit' ) }>
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
                        <th>{ __( 'Segment', 'fundraising-toolkit' ) }</th>
                        <th className="fundkit-num">{ __( 'Donors', 'fundraising-toolkit' ) }</th>
                        <th className="fundkit-num">{ __( '% of base', 'fundraising-toolkit' ) }</th>
                        <th className="fundkit-num">{ __( 'Avg LTV', 'fundraising-toolkit' ) }</th>
                        <th className="fundkit-num">{ __( 'Total LTV', 'fundraising-toolkit' ) }</th>
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
                    <div key={ b.min_cents } className="fundkit-ltv-hist__col" title={ `${ b.donor_count } ${ __( 'donors', 'fundraising-toolkit' ) }` }>
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
                'fundraising-toolkit'
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
                    <th>{ __( 'Donor', 'fundraising-toolkit' ) }</th>
                    <th>{ __( 'Email', 'fundraising-toolkit' ) }</th>
                    <th>{ __( 'Country', 'fundraising-toolkit' ) }</th>
                    <th className="fundkit-num">{ __( 'Donations', 'fundraising-toolkit' ) }</th>
                    <th className="fundkit-num">{ __( 'Total', 'fundraising-toolkit' ) }</th>
                    <th className="fundkit-date">{ __( 'Last donation', 'fundraising-toolkit' ) }</th>
                    { showReason && <th className="fundkit-why">{ __( 'Why', 'fundraising-toolkit' ) }</th> }
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
                title={ __( 'No donors yet', 'fundraising-toolkit' ) }
                body={ __( 'The top donor leaderboard fills in as your first completed donations roll in.', 'fundraising-toolkit' ) }
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
                title={ __( 'Not enough history yet', 'fundraising-toolkit' ) }
                body={ __( 'Cohort retention needs at least one donation in a cohort month before the heatmap can render.', 'fundraising-toolkit' ) }
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
                        <th>{ __( 'Cohort', 'fundraising-toolkit' ) }</th>
                        <th className="fundkit-num">{ __( 'Size', 'fundraising-toolkit' ) }</th>
                        { cols.map( ( i ) => (
                            <th key={ i } className="fundkit-num">{ i === 0 ? __( 'M0', 'fundraising-toolkit' ) : `+${ i }` }</th>
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
                label={ __( 'Active recurring', 'fundraising-toolkit' ) }
                value={ String( recurring.active_count ) }
                sub={ __( 'plans currently billing', 'fundraising-toolkit' ) }
                icon={ <IconHeart /> }
            />
            <MetricCard
                label={ __( 'MRR', 'fundraising-toolkit' ) }
                value={ formatAmount( recurring.mrr_cents ) }
                sub={ __( 'monthly-equivalent revenue', 'fundraising-toolkit' ) }
                icon={ <IconCoins /> }
            />
            <MetricCard
                label={ __( 'New this month', 'fundraising-toolkit' ) }
                value={ String( recurring.new_this_month ) }
                sub={ __( 'plans started', 'fundraising-toolkit' ) }
                icon={ <IconActivity /> }
            />
            <MetricCard
                label={ __( 'Churn this month', 'fundraising-toolkit' ) }
                value={ `${ recurring.churn_pct }%` }
                sub={ sprintf( /* translators: %d: count */ __( '%d cancellations', 'fundraising-toolkit' ), recurring.churned_this_month ) }
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
            .catch( ( e ) => { if ( ! aborted ) setError( e?.message || __( 'Could not load at-risk donors.', 'fundraising-toolkit' ) ); } )
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
                            ? __( '1 at-risk donor', 'fundraising-toolkit' )
                            : sprintf( /* translators: %s: count */ __( '%s at-risk donors', 'fundraising-toolkit' ), total.toLocaleString() ) }
                    </span>
                </div>
            ) }
            { loading && ! data && <p className="fundkit-loading">{ __( 'Loading…', 'fundraising-toolkit' ) }</p> }
            { error && ! loading && <p className="fundkit-error">{ error }</p> }
            { ! error && data && data.length === 0 && (
                <EmptyState
                    compact
                    icon={ <UsersIcon size={ 22 } strokeWidth={ 1.75 } /> }
                    title={ __( 'No donors are slipping', 'fundraising-toolkit' ) }
                    body={ __( 'Donors appear here when they have gone quiet for longer than usual, so you can reach them before they lapse.', 'fundraising-toolkit' ) }
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
                                ← { __( 'Prev', 'fundraising-toolkit' ) }
                            </button>
                            <span>{ sprintf( /* translators: 1: current page, 2: total pages */ __( 'Page %1$d of %2$d', 'fundraising-toolkit' ), page, pageCount ) }</span>
                            <button type="button" disabled={ page >= pageCount } onClick={ () => setPage( ( p ) => p + 1 ) }>
                                { __( 'Next', 'fundraising-toolkit' ) } →
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
        return <p className="fundkit-loading">{ __( 'Loading insights…', 'fundraising-toolkit' ) }</p>;
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
                        <h1>{ __( 'Donors', 'fundraising-toolkit' ) }</h1>
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
                            'fundraising-toolkit'
                        ),
                        data.test.test_only_donors
                    ) }
                    { ' ' }
                    { __( 'Lifetime value, segments and retention are built from money actually taken, so there is no test version of them.', 'fundraising-toolkit' ) }
                </Notice>
            ) }

            <LifecycleKpis kpi={ data.kpi } />

            <WidgetCard title={ __( 'Recurring revenue', 'fundraising-toolkit' ) }>
                <RecurringStrip recurring={ data.recurring } />
            </WidgetCard>

            <div className="fundkit-overview__grid">
                <WidgetCard title={ __( 'Donor segments', 'fundraising-toolkit' ) }>
                    <SegmentBreakdown segments={ data.segments } />
                </WidgetCard>
                <WidgetCard title={ __( 'Lifetime value distribution', 'fundraising-toolkit' ) }>
                    <LtvHistogram buckets={ data.ltv_buckets } />
                </WidgetCard>
            </div>

            <WidgetCard title={ __( 'Cohort retention', 'fundraising-toolkit' ) }>
                <CohortHeatmap retention={ data.retention } />
            </WidgetCard>

            <WidgetCard title={ __( 'Needs attention: at-risk donors', 'fundraising-toolkit' ) }>
                <AtRiskTable />
            </WidgetCard>

            <WidgetCard title={ __( 'Top donors by lifetime value', 'fundraising-toolkit' ) }>
                <TopDonorsLeaderboard rows={ data.top_donors } />
            </WidgetCard>
        </div>
    );
}
