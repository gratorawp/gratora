/** @jsxImportSource preact */

import { useEffect } from 'preact/hooks';
import { visibleGateways } from '../util/gateways';

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
