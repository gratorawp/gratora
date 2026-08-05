/** @jsxImportSource preact */

import { useEffect, useState } from 'preact/hooks';
import { getActiveNumberFormat, groupDigits } from '../util/format';

/**
 * A typed amount, read the way the donor meant it.
 *
 * The box accepts both separators because donors type whichever one they are
 * used to. parseAmount then removed whichever one the org had configured as
 * its thousands separator, so a site formatting in en-US read "25,50" as 2550
 * and charged a hundred times the intended gift, and a site formatting in
 * de-DE did exactly the same to "25.50".
 *
 * Read positionally instead, which holds in both conventions: the last
 * separator is a decimal point when one or two digits follow it, and grouping
 * otherwise. Nobody groups thousands two digits at a time, and no currency
 * here has more than two decimal places.
 */
export function typedAmountToNumber( raw, dp ) {
    const cleaned = String( raw ?? '' ).replace( /[^\d.,]/g, '' );
    if ( cleaned === '' ) return 0;

    const ungrouped = () => Number( cleaned.replace( /[.,]/g, '' ) ) || 0;

    // A zero-decimal currency has no fractional part to protect.
    if ( ! dp ) return ungrouped();

    const lastSep = Math.max( cleaned.lastIndexOf( '.' ), cleaned.lastIndexOf( ',' ) );
    if ( lastSep === -1 ) return Number( cleaned ) || 0;

    const decimals = cleaned.length - lastSep - 1;
    if ( decimals < 1 || decimals > 2 ) return ungrouped();

    const whole = cleaned.slice( 0, lastSep ).replace( /[.,]/g, '' );
    return Number( `${ whole || '0' }.${ cleaned.slice( lastSep + 1 ) }` ) || 0;
}

// `value` is in major units (50 = €50.00). Number format comes from the
// runtime's active format, seeded once at boot from `config.numberFormat`.
export default function AmountInput( {
    value,
    onChange,
    currency       = 'USD',
    decimalPlaces,
    min,
    max,
    placeholder    = '0',
    autoFocus      = false,
    ariaInvalid    = false,
    className      = '',
    inputProps     = {},
} ) {
    const fmt = getActiveNumberFormat();
    const dp  = typeof decimalPlaces === 'number' ? decimalPlaces : fmt.decimalPlaces;

    const format = ( n ) => {
        if ( n === '' || n === null || n === undefined || Number( n ) === 0 ) return '';
        return groupDigits( n, fmt.thousandSep, fmt.decimalSep, dp );
    };

    const [ text, setText ]       = useState( () => format( value ) );
    const [ focused, setFocused ] = useState( false );

    useEffect( () => {
        if ( ! focused ) setText( format( value ) );
    }, [ value, focused, dp, fmt.thousandSep, fmt.decimalSep ] );

    const emit = ( raw ) => {
        let n = typedAmountToNumber( raw, dp );
        if ( typeof min === 'number' && n < min ) n = min;
        if ( typeof max === 'number' && n > max ) n = max;
        onChange && onChange( n );
    };

    const handleInput = ( e ) => {
        // Allow both separators since donors may type either by habit.
        const allowedSeps = dp > 0 ? ',.' : '';
        const allowed = new RegExp( `[^\\d${ allowedSeps.replace( /[.\-]/g, '\\$&' ) }]`, 'g' );
        const cleaned = e.target.value.replace( allowed, '' );
        setText( cleaned );
        emit( cleaned );
    };

    const handleBlur = () => {
        setFocused( false );
        setText( format( value ) );
    };

    return (
        <div class={ `dono-amount${ className ? ' ' + className : '' }` }>
            <span class="dono-amount__prefix" aria-hidden="true">
                <span class="dono-amount__code">{ currency }</span>
            </span>
            <input
                type="text"
                inputmode={ dp > 0 ? 'decimal' : 'numeric' }
                class="dono-amount__input"
                value={ text }
                onInput={ handleInput }
                onFocus={ () => setFocused( true ) }
                onBlur={ handleBlur }
                placeholder={ placeholder }
                autoFocus={ autoFocus }
                aria-invalid={ ariaInvalid || undefined }
                { ...inputProps }
            />
        </div>
    );
}
