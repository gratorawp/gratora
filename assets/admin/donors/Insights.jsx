import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Users as UsersIcon, History } from 'lucide-react';

import EmptyState from '../_shared/components/EmptyState';
import { backGlyph, forwardGlyph } from '../_shared/arrow';
import Notice from '../_shared/components/Notice';
import MetricCard from '../_shared/widgets/MetricCard';
import { WidgetCard } from '../_shared/widgets/Widget';
import { formatAmount, formatAmountCompact, formatDate } from '../_shared/format';
import { IconUsers, IconHeart, IconActivity, IconCoins } from '../_shared/widgets/icons';

const SEGMENT_META = {
    champions:   { label: __( 'Champions',    'gratora-donation-platform' ), color: 'var(--gratora-seg-champions, #6f5ce6)',   hint: __( 'Active, frequent, high LTV', 'gratora-donation-platform' ) },
    loyal:       { label: __( 'Loyal',        'gratora-donation-platform' ), color: 'var(--gratora-seg-loyal, #0a9bab)',       hint: __( 'Active, 2+ donations',        'gratora-donation-platform' ) },
    new:         { label: __( 'New',          'gratora-donation-platform' ), color: 'var(--gratora-seg-new, #1f7fb8)',         hint: __( 'Joined recently',             'gratora-donation-platform' ) },
    at_risk:     { label: __( 'At risk',      'gratora-donation-platform' ), color: 'var(--gratora-seg-at-risk, #b08a12)',     hint: __( 'Was active, slowing down',    'gratora-donation-platform' ) },
    hibernating: { label: __( 'Hibernating',  'gratora-donation-platform' ), color: 'var(--gratora-seg-hibernating, #9a3fb0)', hint: __( 'Long lapsed, low LTV',        'gratora-donation-platform' ) },
    lost:        { label: __( 'Lost',         'gratora-donation-platform' ), color: 'var(--gratora-seg-lost, #c25050)',        hint: __( '> 12 months silent',           'gratora-donation-platform' ) },
    other:       { label: __( 'Other',        'gratora-donation-platform' ), color: 'var(--gratora-seg-other, #6b7280)',       hint: __( 'Uncategorised',               'gratora-donation-platform' ) },
};

/**
 * A bucket is narrower than the widest range it has to label, so the label has
 * to break somewhere. Left to the browser it breaks inside a number, because
 * the hyphen between two amounts is not a break opportunity when digits sit on
 * both sides of it. The explicit one puts the break where a range reads.
 */
function BucketLabel( { min, max } ) {
    if ( max === null ) {
        return <>{ `> ${ formatAmountCompact( min - 1 ) }` }</>;
    }

    const from = formatAmountCompact( min === 1 ? 0 : min - 1 );

    return (
        <>
            { `${ from }-` }
            <wbr />
            { formatAmountCompact( max ) }
        </>
    );
}

function LifecycleKpis( { kpi } ) {
    return (
        <div className="gratora-overview__metrics">
            { /* Not "Total donors": the donors list header carries that name
                 over every row in the table, and this counts only the ones
                 with a donation on record, which is the population every
                 figure on this screen is cut from. Two numbers under one
                 name on two views of the same page read as a contradiction. */ }
            <MetricCard
                label={ __( 'Giving donors', 'gratora-donation-platform' ) }
                value={ String( kpi.total ) }
                sub={ kpi.new ? `+${ kpi.new } ${ __( 'new (30d)', 'gratora-donation-platform' ) }` : __( 'no new donors', 'gratora-donation-platform' ) }
                icon={ <IconUsers /> }
            />
            <MetricCard
                label={ __( 'Active', 'gratora-donation-platform' ) }
                value={ String( kpi.active ) }
                sub={ `${ kpi.active_pct }% ${ __( 'of base · gave in 90d', 'gratora-donation-platform' ) }` }
                icon={ <IconHeart /> }
            />
            <MetricCard
                label={ __( 'At risk', 'gratora-donation-platform' ) }
                value={ String( kpi.at_risk ) }
                sub={ `${ kpi.at_risk_pct }% ${ __( 'silent 90-180d', 'gratora-donation-platform' ) }` }
                icon={ <IconActivity /> }
            />
            <MetricCard
                label={ __( 'Lapsed', 'gratora-donation-platform' ) }
                value={ String( kpi.lapsed ) }
                sub={ `${ kpi.lapsed_pct }% ${ __( 'silent 180-365d', 'gratora-donation-platform' ) }` }
                icon={ <IconActivity /> }
            />
            <MetricCard
                label={ __( 'Lost', 'gratora-donation-platform' ) }
                value={ String( kpi.lost ) }
                sub={ `${ kpi.lost_pct }% ${ __( '> 365d silent', 'gratora-donation-platform' ) }` }
                icon={ <IconActivity /> }
            />
            <MetricCard
                label={ __( 'Median LTV', 'gratora-donation-platform' ) }
                value={ formatAmount( kpi.median_ltv_cents ) }
                sub={ `${ __( 'avg', 'gratora-donation-platform' ) } ${ formatAmount( kpi.avg_ltv_cents ) }` }
                icon={ <IconCoins /> }
            />
        </div>
    );
}

function SegmentBreakdown( { segments } ) {
    const total = segments.reduce( ( s, r ) => s + r.donor_count, 0 ) || 1;
    return (
        <div className="gratora-segments">
            <div className="gratora-segments__bar" role="img" aria-label={ __( 'Donor segment distribution', 'gratora-donation-platform' ) }>
                { segments.map( ( s ) => {
                    const meta = SEGMENT_META[ s.segment ] || SEGMENT_META.other;
                    const pct  = ( s.donor_count / total ) * 100;
                    if ( pct < 0.5 ) return null;
                    return (
                        <span
                            key={ s.segment }
                            className="gratora-segments__bar-slice"
                            style={ { width: `${ pct }%`, background: meta.color } }
                            title={ `${ meta.label }: ${ s.donor_count } (${ pct.toFixed( 1 ) }%)` }
                        />
                    );
                } ) }
            </div>
            <table className="gratora-segments__table">
                <thead>
                    <tr>
                        <th>{ __( 'Segment', 'gratora-donation-platform' ) }</th>
                        <th className="gratora-num">{ __( 'Donors', 'gratora-donation-platform' ) }</th>
                        <th className="gratora-num">{ __( '% of base', 'gratora-donation-platform' ) }</th>
                        <th className="gratora-num">{ __( 'Avg LTV', 'gratora-donation-platform' ) }</th>
                        <th className="gratora-num">{ __( 'Total LTV', 'gratora-donation-platform' ) }</th>
                    </tr>
                </thead>
                <tbody>
                    { segments.map( ( s ) => {
                        const meta = SEGMENT_META[ s.segment ] || SEGMENT_META.other;
                        const pct  = ( s.donor_count / total ) * 100;
                        return (
                            <tr key={ s.segment }>
                                <td>
                                    <span className="gratora-seg-chip" style={ { background: meta.color } } />
                                    { meta.label }
                                    <span className="gratora-seg-hint"> · { meta.hint }</span>
                                </td>
                                <td className="gratora-num">{ s.donor_count }</td>
                                <td className="gratora-num">{ pct.toFixed( 1 ) }%</td>
                                <td className="gratora-num">{ formatAmount( s.avg_ltv_cents ) }</td>
                                <td className="gratora-num">{ formatAmount( s.total_ltv_cents ) }</td>
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
        <div className="gratora-ltv-hist">
            { buckets.map( ( b ) => {
                const h = ( b.donor_count / max ) * 100;
                return (
                    <div key={ b.min_cents } className="gratora-ltv-hist__col" title={ `${ b.donor_count } ${ __( 'donors', 'gratora-donation-platform' ) }` }>
                        <div className="gratora-ltv-hist__bar-wrap">
                            <div className="gratora-ltv-hist__bar" style={ { height: `${ Math.max( h, 2 ) }%` } } />
                        </div>
                        <div className="gratora-ltv-hist__count">{ b.donor_count }</div>
                        <div className="gratora-ltv-hist__label"><BucketLabel min={ b.min_cents } max={ b.max_cents } /></div>
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
export function ReasonPill( { row } ) {
    if ( ! row.risk_reason_label ) return '-';
    const title = row.avg_gap_days
        ? sprintf(
            /* translators: %d: number of days */
            _n(
                'About %d day between donations, on average.',
                'About %d days between donations, on average.',
                row.avg_gap_days,
                'gratora-donation-platform'
            ),
            row.avg_gap_days
        )
        : undefined;
    return (
        <span className={ `dp-pill ${ row.risk_reason_tone || 'is-muted' }` } title={ title }>
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
        <table className="gratora-table">
            <thead>
                <tr>
                    <th>{ __( 'Donor', 'gratora-donation-platform' ) }</th>
                    <th>{ __( 'Email', 'gratora-donation-platform' ) }</th>
                    <th>{ __( 'Country', 'gratora-donation-platform' ) }</th>
                    <th className="gratora-num">{ __( 'Donations', 'gratora-donation-platform' ) }</th>
                    <th className="gratora-num">{ __( 'Total', 'gratora-donation-platform' ) }</th>
                    <th className="gratora-date">{ __( 'Last donation', 'gratora-donation-platform' ) }</th>
                    { showReason && <th className="gratora-why">{ __( 'Why', 'gratora-donation-platform' ) }</th> }
                </tr>
            </thead>
            <tbody>
                { rows.map( ( r ) => (
                    <tr key={ r.id }>
                        <td>
                            <a className="gratora-row__link gratora-row__link--strong" href={ donorHref( r.id ) }>
                                { r.name }
                            </a>
                        </td>
                        <td>{ r.email || '-' }</td>
                        <td>{ r.country || '-' }</td>
                        <td className="gratora-num">{ r.donations_count ?? '-' }</td>
                        <td className="gratora-num">{ formatAmount( r.total_donated_cents ) }</td>
                        <td className="gratora-date">{ formatDate( r.last_donation_at ) }</td>
                        { showReason && <td className="gratora-why"><ReasonPill row={ r } /></td> }
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
                title={ __( 'No donors yet', 'gratora-donation-platform' ) }
                body={ __( 'The top donor leaderboard fills in as your first completed donations roll in.', 'gratora-donation-platform' ) }
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
                title={ __( 'Not enough history yet', 'gratora-donation-platform' ) }
                body={ __( 'Cohort retention needs at least one donation in a cohort month before the heatmap can render.', 'gratora-donation-platform' ) }
            />
        );
    }
    const cols = Array.from( { length: maxOffset + 1 }, ( _, i ) => i );

    const anyPartial = cohorts.some( ( row ) =>
        Object.values( row.retention || {} ).some( ( c ) => c && c.partial && c.pct > 0 )
    );

    // A month still running is not comparable with the months behind it, so it
    // is drawn out of the scale rather than as the palest cell in the row.
    const partialStyle = {
        background: 'var(--gratora-bg-soft, #f3f4f6)',
        color:      'var(--gratora-text-muted, #6b7280)',
        fontStyle:  'italic',
    };

    const cellStyle = ( pct ) => {
        if ( pct <= 0 ) return { background: 'var(--gratora-bg-soft, #f3f4f6)', color: 'var(--gratora-text-muted, #6b7280)' };
        // One hue, light to dark, so the cell reads as magnitude.
        const intensity = Math.min( 1, pct / 100 );
        const r = Math.round( 245 + ( 111 - 245 ) * intensity );
        const g = Math.round( 244 + ( 92 - 244 ) * intensity );
        const b = Math.round( 250 + ( 230 - 250 ) * intensity );
        const fg = intensity > 0.45 ? '#fff' : '#211d3f';
        return { background: `rgb(${ r },${ g },${ b })`, color: fg };
    };

    return (
        <div className="gratora-cohort">
            <table className="gratora-cohort__table">
                <thead>
                    <tr>
                        <th>{ __( 'Cohort', 'gratora-donation-platform' ) }</th>
                        <th className="gratora-num">{ __( 'Size', 'gratora-donation-platform' ) }</th>
                        { cols.map( ( i ) => (
                            <th key={ i } className="gratora-num">{ i === 0 ? __( 'M0', 'gratora-donation-platform' ) : `+${ i }` }</th>
                        ) ) }
                    </tr>
                </thead>
                <tbody>
                    { cohorts.map( ( row ) => (
                        <tr key={ row.month }>
                            <td>{ row.month }</td>
                            <td className="gratora-num">{ row.size }</td>
                            { cols.map( ( i ) => {
                                const cell = row.retention[ i ] || { pct: 0, count: 0 };
                                const soFar = !! cell.partial && cell.pct > 0;

                                return (
                                    <td
                                        key={ i }
                                        className="gratora-cohort__cell"
                                        style={ soFar ? partialStyle : cellStyle( cell.pct ) }
                                        title={ soFar
                                            ? sprintf(
                                                /* translators: 1: donors so far, 2: cohort size, 3: a percentage */
                                                __( '%1$s of %2$s so far (%3$s%%). This month is still running.', 'gratora-donation-platform' ),
                                                cell.count,
                                                row.size,
                                                cell.pct
                                            )
                                            : `${ cell.count } / ${ row.size } (${ cell.pct }%)` }
                                    >
                                        { cell.pct > 0
                                            ? ( soFar
                                                ? sprintf(
                                                    /* translators: %s: a percentage reached so far this month */
                                                    __( '%s%% so far', 'gratora-donation-platform' ),
                                                    cell.pct
                                                )
                                                : `${ cell.pct }%` )
                                            : '-' }
                                    </td>
                                );
                            } ) }
                        </tr>
                    ) ) }
                </tbody>
            </table>
            { anyPartial && (
                <p className="gratora-cohort__note">
                    { __( 'One cell per cohort falls in the month now running, and counts only the days so far. Those are marked and left out of the shading.', 'gratora-donation-platform' ) }
                </p>
            ) }
        </div>
    );
}

function RecurringStrip( { recurring } ) {
    return (
        <div className="gratora-overview__metrics">
            <MetricCard
                label={ __( 'Active recurring', 'gratora-donation-platform' ) }
                value={ String( recurring.active_count ) }
                sub={ __( 'plans currently billing', 'gratora-donation-platform' ) }
                icon={ <IconHeart /> }
            />
            <MetricCard
                label={ __( 'MRR', 'gratora-donation-platform' ) }
                value={ formatAmount( recurring.mrr_cents ) }
                sub={ __( 'monthly-equivalent revenue', 'gratora-donation-platform' ) }
                icon={ <IconCoins /> }
            />
            <MetricCard
                label={ __( 'New this month', 'gratora-donation-platform' ) }
                value={ String( recurring.new_this_month ) }
                sub={ __( 'plans started', 'gratora-donation-platform' ) }
                icon={ <IconActivity /> }
            />
            <MetricCard
                label={ __( 'Churn this month', 'gratora-donation-platform' ) }
                value={ `${ recurring.churn_pct }%` }
                sub={ sprintf( /* translators: %d: count */ __( '%d cancellations', 'gratora-donation-platform' ), recurring.churned_this_month ) }
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
            path: `/gratora/v1/admin/donors/at-risk?page=${ page }&per_page=${ perPage }`,
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
            .catch( ( e ) => { if ( ! aborted ) setError( e?.message || __( 'Could not load at-risk donors.', 'gratora-donation-platform' ) ); } )
            .finally( () => { if ( ! aborted ) setLoading( false ); } );
        return () => { aborted = true; };
    }, [ page ] );

    const pageCount = Math.max( 1, Math.ceil( total / perPage ) );

    return (
        <div className="gratora-at-risk">
            { total > 0 && (
                <div className="gratora-at-risk__head">
                    <span className="gratora-at-risk__count">
                        { sprintf(
                            /* translators: %s: count */
                            _n( '%s at-risk donor', '%s at-risk donors', total, 'gratora-donation-platform' ),
                            total.toLocaleString()
                        ) }
                    </span>
                </div>
            ) }
            { loading && ! data && <p className="gratora-loading">{ __( 'Loading…', 'gratora-donation-platform' ) }</p> }
            { error && ! loading && <p className="gratora-error">{ error }</p> }
            { ! error && data && data.length === 0 && (
                <EmptyState
                    compact
                    icon={ <UsersIcon size={ 22 } strokeWidth={ 1.75 } /> }
                    title={ __( 'No donors are slipping', 'gratora-donation-platform' ) }
                    body={ __( 'Donors appear here when they have gone quiet for longer than usual, so you can reach them before they lapse.', 'gratora-donation-platform' ) }
                />
            ) }
            { data && data.length > 0 && (
                <>
                    <div className="gratora-at-risk__scroll">
                        <DonorTable rows={ data } showReason />
                    </div>
                    { pageCount > 1 && (
                        <div className="gratora-pagination">
                            <button type="button" disabled={ page <= 1 } onClick={ () => setPage( ( p ) => p - 1 ) }>
                                { backGlyph() } { __( 'Prev', 'gratora-donation-platform' ) }
                            </button>
                            <span>{ sprintf( /* translators: 1: current page, 2: total pages */ __( 'Page %1$d of %2$d', 'gratora-donation-platform' ), page, pageCount ) }</span>
                            <button type="button" disabled={ page >= pageCount } onClick={ () => setPage( ( p ) => p + 1 ) }>
                                { __( 'Next', 'gratora-donation-platform' ) } { forwardGlyph() }
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
        apiFetch( { path: '/gratora/v1/admin/donors/insights' } )
            .then( ( d ) => { if ( ! aborted ) { setData( d ); setError( null ); } } )
            .catch( ( e ) => { if ( ! aborted ) setError( e?.message || 'Error' ); } )
            .finally( () => { if ( ! aborted ) setLoading( false ); } );
        return () => { aborted = true; };
    }, [] );

    if ( loading && ! data ) {
        return <p className="gratora-loading">{ __( 'Loading insights…', 'gratora-donation-platform' ) }</p>;
    }
    if ( error ) {
        return <p className="gratora-error">{ error }</p>;
    }
    if ( ! data ) return null;

    return (
        <div className="gratora-donor-insights" data-loading={ loading ? 'true' : undefined }>
            { toggleSlot && (
                <div className="gratora-page-head">
                    <div className="gratora-page-head__title-row">
                        <h1>{ __( 'Donors', 'gratora-donation-platform' ) }</h1>
                    </div>
                    <div className="gratora-page-head__right">{ toggleSlot }</div>
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
                            'gratora-donation-platform'
                        ),
                        data.test.test_only_donors
                    ) }
                    { ' ' }
                    { __( 'Lifetime value, segments and retention are built from money actually taken, so there is no test version of them.', 'gratora-donation-platform' ) }
                </Notice>
            ) }

            <LifecycleKpis kpi={ data.kpi } />

            <WidgetCard title={ __( 'Recurring revenue', 'gratora-donation-platform' ) }>
                <RecurringStrip recurring={ data.recurring } />
            </WidgetCard>

            <div className="gratora-overview__grid">
                <WidgetCard title={ __( 'Donor segments', 'gratora-donation-platform' ) }>
                    <SegmentBreakdown segments={ data.segments } />
                </WidgetCard>
                <WidgetCard title={ __( 'Lifetime value distribution', 'gratora-donation-platform' ) }>
                    <LtvHistogram buckets={ data.ltv_buckets } />
                </WidgetCard>
            </div>

            <WidgetCard title={ __( 'Cohort retention', 'gratora-donation-platform' ) }>
                <CohortHeatmap retention={ data.retention } />
            </WidgetCard>

            <WidgetCard title={ __( 'Needs attention: at-risk donors', 'gratora-donation-platform' ) }>
                <AtRiskTable />
            </WidgetCard>

            <WidgetCard title={ __( 'Top donors by lifetime value', 'gratora-donation-platform' ) }>
                <TopDonorsLeaderboard rows={ data.top_donors } />
            </WidgetCard>
        </div>
    );
}
