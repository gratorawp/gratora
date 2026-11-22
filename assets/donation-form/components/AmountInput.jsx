/** @jsxImportSource preact */

import { useEffect, useState } from 'preact/hooks';
import { numberFormat, groupDigits } from '../util/format';
import { isZeroDecimal } from '../util/fx';

// Where the site's own decimal separator is the point: written once, with no separator after it.
function sitePoint( text, decimalSep ) {
    const at = text.indexOf( decimalSep );
    return at !== -1 && at === text.lastIndexOf( decimalSep ) && ! /[.,]/.test( text.slice( at + 1 ) ) ? at : -1;
}

/**
 * Accept either decimal separator. The site's own, written once and last, is the point, so 20.000 on a site that
 * writes 20.00 is twenty. Otherwise one or two trailing digits indicate decimals and more indicate grouping.
 */
export function typedAmountToNumber( raw, dp, decimalSep = '.' ) {
    const cleaned = String( raw ?? '' ).replace( /[^\d.,]/g, '' );
    if ( cleaned === '' ) return 0;

    const ungrouped = () => Number( cleaned.replace( /[.,]/g, '' ) ) || 0;

    // A zero-decimal currency has no fractional part to protect.
    if ( ! dp ) return ungrouped();

    const point   = sitePoint( cleaned, decimalSep );
    const lastSep = point !== -1 ? point : Math.max( cleaned.lastIndexOf( '.' ), cleaned.lastIndexOf( ',' ) );
    if ( lastSep === -1 ) return Number( cleaned ) || 0;

    const decimals = cleaned.length - lastSep - 1;
    if ( point === -1 && ( decimals < 1 || decimals > 2 ) ) return ungrouped();

    const whole = cleaned.slice( 0, lastSep ).replace( /[.,]/g, '' );
    return Number( `${ whole || '0' }.${ cleaned.slice( lastSep + 1, lastSep + 1 + dp ) }` ) || 0;
}

function capDecimals( text, dp, decimalSep ) {
    const point = sitePoint( text, decimalSep );
    return dp && point !== -1 ? text.slice( 0, point + 1 + dp ) : text;
}

// `value` is in major units (50 = €50.00). Separators come from the runtime's
// active number format, seeded once at boot from `config.numberFormat`; how
// many decimals an amount may carry comes from the currency being charged,
// since the org's display preference says nothing about what a donor in
// another currency can type.
export default function AmountInput( {
    value,
    onChange,
    currency       = 'USD',
    decimalPlaces,
    min,
    placeholder    = '0',
    autoFocus      = false,
    ariaInvalid    = false,
    className      = '',
    inputProps     = {},
} ) {
    const fmt = numberFormat();
    const dp  = typeof decimalPlaces === 'number'
        ? decimalPlaces
        : ( isZeroDecimal( currency ) ? 0 : 2 );

    const format = ( n ) => {
        if ( n === '' || n === null || n === undefined || Number( n ) === 0 ) return '';
        return groupDigits( n, fmt.thousandSep, fmt.decimalSep, dp );
    };

    const [ text, setText ]       = useState( () => format( value ) );
    const [ focused, setFocused ] = useState( false );

    useEffect( () => {
        if ( ! focused ) setText( format( value ) );
    }, [ value, focused, dp, fmt.thousandSep, fmt.decimalSep ] );

    // Only the lower bound is enforced here. An upper one would rewrite a
    // typed figure to the ceiling while the box still showed what the donor
    // entered, and the amount step has a message for that.
    const emit = ( raw ) => {
        let n = typedAmountToNumber( raw, dp, fmt.decimalSep );
        if ( typeof min === 'number' && n < min ) n = min;
        onChange && onChange( n );
    };

    const handleInput = ( e ) => {
        // Allow both separators since donors may type either by habit.
        const allowedSeps = dp > 0 ? ',.' : '';
        const allowed = new RegExp( `[^\\d${ allowedSeps.replace( /[.\-]/g, '\\$&' ) }]`, 'g' );
        const cleaned = capDecimals( e.target.value.replace( allowed, '' ), dp, fmt.decimalSep );
        // Preact leaves the field alone when the text in state has not changed, so a refused key would stay on screen.
        if ( e.target.value !== cleaned ) e.target.value = cleaned;
        setText( cleaned );
        emit( cleaned );
    };

    const handleBlur = () => {
        setFocused( false );
        setText( format( value ) );
    };

    return (
        <div class={ `gratora-amount${ className ? ' ' + className : '' }` }>
            <span class="gratora-amount__prefix" aria-hidden="true">
                <span class="gratora-amount__code">{ currency }</span>
            </span>
            <input
                type="text"
                inputmode={ dp > 0 ? 'decimal' : 'numeric' }
                class="gratora-amount__input"
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
