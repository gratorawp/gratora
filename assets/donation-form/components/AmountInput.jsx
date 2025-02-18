/** @jsxImportSource preact */

import { useEffect, useState } from 'preact/hooks';
import { getActiveNumberFormat, groupDigits, parseAmount } from '../util/format';

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
        let n = parseAmount( raw );
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
