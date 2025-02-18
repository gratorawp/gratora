/** @jsxImportSource preact */

export default function CurrencySwitcher( { currencies, currency, onChange, variant, align, label, ariaLabel } ) {
    if ( ! Array.isArray( currencies ) || currencies.length < 2 ) return null;

    const swVariant = variant === 'pills' ? 'pills' : 'dropdown';
    const swAlign   = align === 'right' ? 'right' : 'left';
    const swLabel   = ( label && String( label ).trim() ) || '';
    const aria      = swLabel || ariaLabel || 'Currency';

    return (
        <div class={ `dono-form__currency-switcher dono-form__currency-switcher--${ swVariant } dono-form__currency-switcher--${ swAlign }` }>
            { swLabel && (
                <span class="dono-form__currency-switcher-label">{ swLabel }</span>
            ) }
            { swVariant === 'pills' ? (
                <div class="dono-form__currency-pills" role="radiogroup" aria-label={ aria }>
                    { currencies.map( ( c ) => (
                        <button
                            type="button"
                            key={ c }
                            role="radio"
                            class={ `dono-form__currency-pill${ currency === c ? ' is-selected' : '' }` }
                            aria-checked={ currency === c }
                            onClick={ () => onChange( c ) }
                        >
                            { c }
                        </button>
                    ) ) }
                </div>
            ) : (
                <select
                    value={ currency }
                    aria-label={ aria }
                    onChange={ ( e ) => onChange( e.target.value ) }
                >
                    { currencies.map( ( c ) => (
                        <option key={ c } value={ c }>{ c }</option>
                    ) ) }
                </select>
            ) }
        </div>
    );
}
