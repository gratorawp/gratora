import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

import { formatAmount } from '../_shared/format';

export default function AmbitionMeter( { campaignId, goalType, goalCents, currency } ) {
    const [ ctx, setCtx ] = useState( null );

    useEffect( () => {
        if ( ! campaignId ) return undefined;
        let cancelled = false;
        apiFetch( { path: `/fundkit/v1/admin/campaigns/${ campaignId }/goal-context` } )
            .then( ( res ) => { if ( ! cancelled ) setCtx( res ); } )
            .catch( () => { if ( ! cancelled ) setCtx( null ); } );
        return () => { cancelled = true; };
        // Endpoint returns the saved target; live verdict is recomputed locally from goalCents.
    }, [ campaignId ] );

    if ( goalType !== 'amount' ) return null;
    if ( ! ctx ) return null;
    if ( ctx.historical_count === 0 ) {
        return (
            <div className="fundkit-ambition fundkit-ambition--info">
                <div className="fundkit-ambition__title">{ __( 'Your first campaign', 'fundkit-fundraising-campaigns' ) }</div>
                <div className="fundkit-ambition__desc">
                    { __( "We'll show you how this target compares to your other campaigns once you have a few finished ones.", 'fundkit-fundraising-campaigns' ) }
                </div>
            </div>
        );
    }

    const cur  = goalCents != null ? Number( goalCents ) : ctx.current_target_cents;
    const avg  = ctx.historical_avg_cents;
    const max  = ctx.historical_max_cents;
    const cy   = ctx.currency || currency || 'USD';
    const verdict = localVerdict( cur, avg, ctx.historical_count );

    // Scale the bar to max(target, max). Each marker positioned by ratio.
    const upper = Math.max( cur, max, avg ) || 1;
    const curPct = clamp( ( cur / upper ) * 100 );
    const avgPct = clamp( ( avg / upper ) * 100 );
    const maxPct = clamp( ( max / upper ) * 100 );

    return (
        <div className={ `fundkit-ambition fundkit-ambition--${ verdict.tone }` }>
            <div className="fundkit-ambition__head">
                <div>
                    <div className="fundkit-ambition__title">{ verdict.title }</div>
                    <div className="fundkit-ambition__desc">{ verdict.desc }</div>
                </div>
                <div className="fundkit-ambition__ratio num">
                    { avg > 0 && cur > 0 && sprintf(
                        /* translators: %s: multiplier of historical average, e.g. "1.4×" */
                        __( '%s× avg', 'fundkit-fundraising-campaigns' ),
                        ( cur / avg ).toFixed( cur / avg < 10 ? 1 : 0 ),
                    ) }
                </div>
            </div>

            <div className="fundkit-ambition__bar">
                <div className="fundkit-ambition__bar-fill" style={ { width: `${ curPct }%` } } />
                { avg > 0 && (
                    <div className="fundkit-ambition__bar-mark fundkit-ambition__bar-mark--avg" style={ { left: `${ avgPct }%` } } />
                ) }
                { max > 0 && max !== avg && (
                    <div className="fundkit-ambition__bar-mark fundkit-ambition__bar-mark--max" style={ { left: `${ maxPct }%` } } />
                ) }
            </div>

            <div className="fundkit-ambition__legend">
                <span>
                    <span className="fundkit-ambition__legend-dot fundkit-ambition__legend-dot--current" />
                    { __( 'Your target', 'fundkit-fundraising-campaigns' ) }: <strong>{ formatAmount( cur, cy ) }</strong>
                </span>
                <span>
                    <span className="fundkit-ambition__legend-dot fundkit-ambition__legend-dot--avg" />
                    { __( 'Past average', 'fundkit-fundraising-campaigns' ) }: <strong>{ formatAmount( avg, cy ) }</strong>
                </span>
                { max > 0 && max !== avg && (
                    <span>
                        <span className="fundkit-ambition__legend-dot fundkit-ambition__legend-dot--max" />
                        { __( 'Past best', 'fundkit-fundraising-campaigns' ) }: <strong>{ formatAmount( max, cy ) }</strong>
                    </span>
                ) }
            </div>
        </div>
    );
}

function localVerdict( cur, avg, count ) {
    if ( count === 0 ) return { tone: 'info', title: __( 'No historical data', 'fundkit-fundraising-campaigns' ), desc: '' };
    if ( cur <= 0 )    return { tone: 'info', title: __( 'No target set', 'fundkit-fundraising-campaigns' ), desc: __( 'Add a target above to see how it compares.', 'fundkit-fundraising-campaigns' ) };
    if ( avg <= 0 )    return { tone: 'info', title: __( 'Limited history', 'fundkit-fundraising-campaigns' ), desc: '' };

    const r = cur / avg;
    if ( r < 0.5 )  return { tone: 'modest',         title: __( 'Modest target', 'fundkit-fundraising-campaigns' ),         desc: __( "You've raised more than this in past campaigns. Consider aiming higher.", 'fundkit-fundraising-campaigns' ) };
    if ( r < 1.5 )  return { tone: 'in-line',        title: __( 'In line with past campaigns', 'fundkit-fundraising-campaigns' ), desc: __( 'Right around your historical average.', 'fundkit-fundraising-campaigns' ) };
    if ( r < 3.0 )  return { tone: 'ambitious',      title: __( 'Ambitious target', 'fundkit-fundraising-campaigns' ),       desc: __( 'About double your average. Realistic for a strong campaign.', 'fundkit-fundraising-campaigns' ) };
    return                  { tone: 'very-ambitious', title: __( 'Very ambitious', 'fundkit-fundraising-campaigns' ),         desc: __( "Substantially above what you've raised before. Make sure outreach plans match.", 'fundkit-fundraising-campaigns' ) };
}

function clamp( n, lo = 0, hi = 100 ) {
    return Math.max( lo, Math.min( hi, n ) );
}
