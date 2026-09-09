/** @jsxImportSource preact */

import { visibleGateways, emptyMessage } from '../util/gateways';

/**
 * Test-mode notice + payment-gateway selector. The selector hides when one
 * gateway resolves (it is auto-selected); the notice shows in test mode.
 */
export default function GatewaySelect( { state, dispatch, config } ) {
    const testMode = !! ( config && config.testMode );
    const opts     = visibleGateways( config, state );
    const current  = state.gateway;
    const style    = ( config && config.gateways && config.gateways.style ) === 'list' ? 'list' : 'cards';

    // Explain why no gateways are available before submission; the selector cannot repair an
    // empty set.
    if ( ! opts.length ) {
        return (
            <div class="gratora-form__payment">
                <div class="gratora-form__gateways-empty" role="alert">{ emptyMessage( config, state ) }</div>
            </div>
        );
    }

    if ( ! testMode && opts.length <= 1 ) return null;

    return (
        <div class="gratora-form__payment">
            { testMode && (
                <div class="gratora-form__test-banner" role="status">
                    { config.i18n.testModeNotice }
                </div>
            ) }

            { opts.length > 1 && (
                <fieldset class={ `gratora-form__gateways gratora-form__gateways--${ style }` }>
                    <legend class="gratora-form__gateways-legend">{ config.i18n.paymentMethod }</legend>
                    <div class="gratora-form__gateways-list" role="radiogroup" aria-label={ config.i18n.paymentMethod || 'Payment method' }>
                        { opts.map( ( o ) => {
                            const selected = o.id === current;
                            return (
                                <label
                                    key={ o.id }
                                    class={ `gratora-form__gateway${ selected ? ' is-selected' : '' }` }
                                >
                                    <input
                                        type="radio"
                                        name="gratora-gateway"
                                        value={ o.id }
                                        checked={ selected }
                                        onChange={ () => dispatch( { type: 'SET_GATEWAY', gateway: o.id } ) }
                                    />
                                    <span class="gratora-form__gateway-body">
                                        <span class="gratora-form__gateway-label">{ o.label }</span>
                                        { o.description && (
                                            <span class="gratora-form__gateway-desc">{ o.description }</span>
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
