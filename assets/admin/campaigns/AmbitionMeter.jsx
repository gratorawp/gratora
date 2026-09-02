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
                <div className="fundkit-ambition__title">{ __( 'Your first campaign', 'fundraising-toolkit' ) }</div>
                <div className="fundkit-ambition__desc">
                    { __( "We'll show you how this target compares to your other campaigns once you have a few finished ones.", 'fundraising-toolkit' ) }
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
                        __( '%s× avg', 'fundraising-toolkit' ),
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
                    { __( 'Your target', 'fundraising-toolkit' ) }: <strong>{ formatAmount( cur, cy ) }</strong>
                </span>
                <span>
                    <span className="fundkit-ambition__legend-dot fundkit-ambition__legend-dot--avg" />
                    { __( 'Past average', 'fundraising-toolkit' ) }: <strong>{ formatAmount( avg, cy ) }</strong>
                </span>
                { max > 0 && max !== avg && (
                    <span>
                        <span className="fundkit-ambition__legend-dot fundkit-ambition__legend-dot--max" />
                        { __( 'Past best', 'fundraising-toolkit' ) }: <strong>{ formatAmount( max, cy ) }</strong>
                    </span>
                ) }
            </div>
        </div>
    );
}

function localVerdict( cur, avg, count ) {
    if ( count === 0 ) return { tone: 'info', title: __( 'No historical data', 'fundraising-toolkit' ), desc: '' };
    if ( cur <= 0 )    return { tone: 'info', title: __( 'No target set', 'fundraising-toolkit' ), desc: __( 'Add a target above to see how it compares.', 'fundraising-toolkit' ) };
    if ( avg <= 0 )    return { tone: 'info', title: __( 'Limited history', 'fundraising-toolkit' ), desc: '' };

    const r = cur / avg;
    if ( r < 0.5 )  return { tone: 'modest',         title: __( 'Modest target', 'fundraising-toolkit' ),         desc: __( "You've raised more than this in past campaigns. Consider aiming higher.", 'fundraising-toolkit' ) };
    if ( r < 1.5 )  return { tone: 'in-line',        title: __( 'In line with past campaigns', 'fundraising-toolkit' ), desc: __( 'Right around your historical average.', 'fundraising-toolkit' ) };
    if ( r < 3.0 )  return { tone: 'ambitious',      title: __( 'Ambitious target', 'fundraising-toolkit' ),       desc: __( 'About double your average. Realistic for a strong campaign.', 'fundraising-toolkit' ) };
    return                  { tone: 'very-ambitious', title: __( 'Very ambitious', 'fundraising-toolkit' ),         desc: __( "Substantially above what you've raised before. Make sure outreach plans match.", 'fundraising-toolkit' ) };
}

function clamp( n, lo = 0, hi = 100 ) {
    return Math.max( lo, Math.min( hi, n ) );
}
