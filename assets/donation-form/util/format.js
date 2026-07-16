// Intentional fork of @dono/ui's formatAmount/groupDigits: the public form has no
// window.dono, so the number format comes from the server form config. Bootstrap must
// call setActiveNumberFormat(config.numberFormat) once before render; call sites stay zero-arg.

let activeFormat = {
    decimalPlaces:  2,
    decimalSep:     '.',
    thousandSep:    ',',
    symbolPosition: 'before',
    symbol:         '',
};

const FALLBACK_SYMBOLS = {
    USD: '$', EUR: '€', GBP: '£', AUD: 'A$', CAD: 'C$', CHF: 'CHF',
    JPY: '¥', CNY: '¥', SEK: 'kr', NOK: 'kr', DKK: 'kr', PLN: 'zł',
    CZK: 'Kč', HUF: 'Ft', BRL: 'R$', MXN: 'Mex$', INR: '₹', NZD: 'NZ$',
    ZAR: 'R', SGD: 'S$', HKD: 'HK$',
};

export function setActiveNumberFormat( fmt ) {
    if ( fmt && typeof fmt === 'object' ) {
        activeFormat = {
            decimalPlaces:  Number.isFinite( fmt.decimalPlaces ) ? Number( fmt.decimalPlaces ) : activeFormat.decimalPlaces,
            decimalSep:     typeof fmt.decimalSep === 'string' && fmt.decimalSep ? fmt.decimalSep : activeFormat.decimalSep,
            thousandSep:    typeof fmt.thousandSep === 'string' ? fmt.thousandSep : activeFormat.thousandSep,
            symbolPosition: fmt.symbolPosition === 'after' ? 'after' : 'before',
            symbol:         typeof fmt.symbol === 'string' ? fmt.symbol : activeFormat.symbol,
        };
    }
}

export function getActiveNumberFormat() {
    return activeFormat;
}

export function formatAmount( cents, currency = 'USD', opts = {} ) {
    const fmt           = activeFormat;
    const amount        = Number( cents || 0 ) / 100;
    const code          = ( currency || 'USD' ).toUpperCase();
    const isWhole       = amount % 1 === 0;
    const decimalPlaces = opts.compact && isWhole ? 0 : fmt.decimalPlaces;

    const number = groupDigits( amount, fmt.thousandSep, fmt.decimalSep, decimalPlaces );
    // Prefer the requested currency's symbol so it tracks currency switches.
    const symbol = FALLBACK_SYMBOLS[ code ] || fmt.symbol || code;

    return fmt.symbolPosition === 'after'
        ? `${ number } ${ symbol }`
        : `${ symbol }${ number }`;
}

export function formatAmountCompact( cents, currency = 'USD' ) {
    return formatAmount( cents, currency, { compact: true } );
}

export function groupDigits( amount, thousandSep, decimalSep, decimalPlaces ) {
    if ( amount === '' || amount === null || amount === undefined ) return '';
    const n = Number( amount );
    if ( ! Number.isFinite( n ) ) return '';

    const dp    = Math.max( 0, Number( decimalPlaces ) || 0 );
    const fixed = dp > 0 ? Math.abs( n ).toFixed( dp ) : String( Math.trunc( Math.abs( n ) ) );
    const [ whole, frac ] = fixed.split( '.' );

    const grouped = thousandSep
        ? whole.replace( /\B(?=(\d{3})+(?!\d))/g, thousandSep )
        : whole;
    const sign = n < 0 ? '-' : '';
    return dp > 0 && frac
        ? `${ sign }${ grouped }${ decimalSep }${ frac }`
        : `${ sign }${ grouped }`;
}

// Parses using the form's configured separators (set via setActiveNumberFormat),
// so "1,000" in a US-format form is 1000 - not 1.00. Strip the thousands
// separator, normalise the decimal separator to '.', then read the number.
export function parseAmount( raw ) {
    if ( typeof raw !== 'string' || raw === '' ) return 0;

    const thou = activeFormat.thousandSep;
    const dec  = activeFormat.decimalSep || '.';

    let cleaned = raw;
    if ( thou ) cleaned = cleaned.split( thou ).join( '' );
    if ( dec !== '.' ) cleaned = cleaned.split( dec ).join( '.' );
    // Keep only digits, the (now normalised) decimal point, and a minus sign.
    cleaned = cleaned.replace( /[^\d.\-]/g, '' );
    if ( cleaned === '' ) return 0;

    const n = Number( cleaned );
    return Number.isFinite( n ) ? n : 0;
}
