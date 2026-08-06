/** @jsxImportSource preact */

import { useEffect } from 'preact/hooks';
import { visibleGateways, emptyReason } from '../util/gateways';

/**
 * Test-mode notice + payment-gateway selector. The selector hides when one
 * gateway resolves (it is auto-selected); the notice shows in test mode.
 */
export default function GatewaySelect( { state, dispatch, config } ) {
    const testMode = !! ( config && config.testMode );
    const opts     = visibleGateways( config, state );
    const ids      = opts.map( ( o ) => o.id );
    const current  = state.gateway;
    const idsKey   = ids.join( ',' );
    const style    = ( config && config.gateways && config.gateways.style ) === 'list' ? 'list' : 'cards';

    // Keep the selected gateway valid: currency/frequency changes can drop
    // the current option, and a hidden single option still auto-selects.
    useEffect( () => {
        if ( ids.length && ! ids.includes( current ) ) {
            dispatch( { type: 'SET_GATEWAY', gateway: ids[ 0 ] } );
        }
    }, [ idsKey, current ] );

    // Nothing to offer. The section used to render nothing at all, which read
    // as "no payment step", and the stale gateway stayed selected because the
    // effect above only runs when there is something to select -- the donor
    // found out on submit. Which of the three reasons it is matters: blaming
    // the currency when every gateway is switched off sends the donor looking
    // for a fix that was never theirs to make.
    if ( ! opts.length ) {
        const reason  = emptyReason( config, state );
        const i18n    = config.i18n || {};
        const template = reason === 'currency'  ? ( i18n.noGatewayForCurrency || '' )
            : reason === 'frequency' ? ( i18n.noGatewayForFrequency || '' )
            : ( i18n.noGatewayAvailable || '' );
        const message = template.replace( '%s', String( state.currency || '' ).toUpperCase() );
        return (
            <div class="dono-form__payment">
                <div class="dono-form__gateways-empty" role="alert">{ message }</div>
            </div>
        );
    }

    if ( ! testMode && opts.length <= 1 ) return null;

    return (
        <div class="dono-form__payment">
            { testMode && (
                <div class="dono-form__test-banner" role="status">
                    { config.i18n.testModeNotice }
                </div>
            ) }

            { opts.length > 1 && (
                <fieldset class={ `dono-form__gateways dono-form__gateways--${ style }` }>
                    <legend class="dono-form__gateways-legend">{ config.i18n.paymentMethod }</legend>
                    <div class="dono-form__gateways-list" role="radiogroup" aria-label={ config.i18n.paymentMethod || 'Payment method' }>
                        { opts.map( ( o ) => {
                            const selected = o.id === current;
                            return (
                                <label
                                    key={ o.id }
                                    class={ `dono-form__gateway${ selected ? ' is-selected' : '' }` }
                                >
                                    <input
                                        type="radio"
                                        name="dono-gateway"
                                        value={ o.id }
                                        checked={ selected }
                                        onChange={ () => dispatch( { type: 'SET_GATEWAY', gateway: o.id } ) }
                                    />
                                    <span class="dono-form__gateway-body">
                                        <span class="dono-form__gateway-label">{ o.label }</span>
                                        { o.description && (
                                            <span class="dono-form__gateway-desc">{ o.description }</span>
                                        ) }
                                    </span>
                                </label>
                            );
                        } ) }
                    </div>
                </fieldset>
            ) }
        </div>
    );
}
