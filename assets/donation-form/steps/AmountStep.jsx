/** @jsxImportSource preact */

import { formatAmount } from '../util/format';
import { displayPreset } from '../util/fx';
import AmountInput from '../components/AmountInput';
import CurrencySwitcher from '../components/CurrencySwitcher';

export default function AmountStep( { step, state, dispatch, config } ) {
    const cents       = state.values.amount_cents;
    // Presets are authored in state.presetCurrency; show + charge them in the
    // currency the donor has selected (converted, nice-rounded).
    const presets     = ( step.presets || [] ).map( normalizePreset ).map( ( p ) => ( {
        ...p,
        cents: displayPreset( state.fx, p.cents, state.presetCurrency, state.currency ),
    } ) );
    const allowCustom = step.allowCustom !== false;
    const isCustom    = ! presets.find( ( p ) => p.cents === cents );
    const error       = state.errors[ 'amount_cents' ];
    const currencies  = config.currencies || [];

    const setCents = ( c ) => dispatch( { type: 'SET_AMOUNT', cents: c } );
    const setCurrency = ( c ) => dispatch( { type: 'SET_CURRENCY', currency: c } );

    return (
        <div class="dono-form__amount">
            { ! config.currencySwitcherPositioned && (
                <CurrencySwitcher
                    currencies={ currencies }
                    currency={ state.currency }
                    onChange={ setCurrency }
                    variant={ ( config.currencySwitcher || {} ).style }
                    align={ ( config.currencySwitcher || {} ).align }
                    label={ ( config.currencySwitcher || {} ).label }
                    ariaLabel={ config.i18n.currency }
                />
            ) }

            { presets.length > 0 && (
            <div class="dono-form__presets" role="radiogroup" aria-label={ config.i18n.amount || 'Donation amount' }>
                { presets.map( ( p, i ) => (
                    <button
                        type="button"
                        key={ i }
                        role="radio"
                        aria-checked={ cents === p.cents }
                        class={ `dono-form__preset${ cents === p.cents ? ' is-selected' : '' }${ p.impact ? ' has-impact' : '' }` }
                        onClick={ () => setCents( p.cents ) }
                    >
                        <span class="dono-form__preset-amount">{ formatAmount( p.cents, state.currency ) }</span>
                        { p.impact && <span class="dono-form__preset-impact">{ p.impact }</span> }
                    </button>
                ) ) }
            </div>
            ) }

            { allowCustom && (
                <div class="dono-form__custom">
                    <AmountInput
                        value={ isCustom && cents > 0 ? cents / 100 : 0 }
                        onChange={ ( n ) => {
                            const nextCents = Math.max( 0, Math.round( Number( n || 0 ) * 100 ) );
                            setCents( nextCents );
                        } }
                        currency={ state.currency }
                        min={ 0 }
                        ariaInvalid={ !! error }
                        placeholder={ config.i18n.customAmount }
                        inputProps={ { 'aria-label': config.i18n.customAmount } }
                    />
                </div>
            ) }

            { error && <p class="dono-form__field-error" role="alert">{ error }</p> }
        </div>
    );
}

function normalizePreset( p ) {
    if ( typeof p === 'number' ) return { cents: p, impact: '', preselected: false };
    return {
        cents:   Number( p?.cents ) || 0,
        impact:  String( p?.impact || '' ),
        preselected: !! p?.preselected,
    };
}
