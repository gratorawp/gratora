/**
 * Money rendering for the surfaces that run inside a FundKit page: the admin
 * screens and the donor portal. Figure for figure the same as Money::format on
 * the PHP side, because a donor reads one donation in their portal and again on
 * the receipt in their inbox, and the two are the same money.
 */

import { groupDigits } from '@fundkit/ui/utils/format';

const DEFAULT_NUMBER_FORMAT = {
    decimalPlaces:  2,
    decimalSep:     '.',
    thousandSep:    ',',
    symbolPosition: 'before',
    symbol:         '',
};

/**
 * The org bridge these surfaces run against, read at call time so a bundle that
 * loads before the inline script still picks it up. A surface without it renders
 * in the neutral default rather than guessing at the org's preferences.
 *
 * @since 1.0.0
 */
export function defaultCurrency() {
    const code = typeof window !== 'undefined' ? window.fundkit?.default_currency : '';

    return code ? String( code ).toUpperCase() : 'USD';
}

/** @since 1.0.0 */
export function numberFormat() {
    const fmt = typeof window !== 'undefined' ? window.fundkit?.number_format : null;

    return fmt ? { ...DEFAULT_NUMBER_FORMAT, ...fmt } : DEFAULT_NUMBER_FORMAT;
}

/** Match PHP currency exponents, not the shared UI table; MinorUnitParityTest checks parity. */
const ZERO_DECIMAL = [
    'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
    'PYG', 'RWF', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
];

const THREE_DECIMAL = [ 'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' ];

/**
 * The ISO 4217 minor-unit exponent for a currency code.
 *
 * @since 1.0.0
 */
export function minorUnitsFor( currency ) {
    const code = String( currency || '' ).trim().toUpperCase();

    if ( ZERO_DECIMAL.includes( code ) ) {
        return 0;
    }
    if ( THREE_DECIMAL.includes( code ) ) {
        return 3;
    }

    return 2;
}

// ISO 4217 to symbol, kept in step with Money::SYMBOLS so a screen and the
// receipt it links to name the same currency. A code that is not here renders
// as itself, which is honest; borrowing another currency's symbol is not.
export const CURRENCY_SYMBOLS = {
    USD: '$',
    EUR: '€',
    GBP: '£',
    AUD: 'A$',
    CAD: 'C$',
    CHF: 'CHF',
    JPY: '¥',
    CNY: '¥',
    SEK: 'kr',
    NOK: 'kr',
    DKK: 'kr',
    PLN: 'zł',
    CZK: 'Kč',
    HUF: 'Ft',
    BRL: 'R$',
    MXN: 'Mex$',
    INR: '₹',
    NZD: 'NZ$',
    ZAR: 'R',
    SGD: 'S$',
    HKD: 'HK$',
};

/**
 * Decimal places to render `cents` of `currency` in. The currency's own minor
 * units decide; the org's "decimal places" preference speaks for the base
 * currency alone, and only to drop places an amount does not use. It can never
 * round away cents that were charged, nor add places to a currency that has
 * none. Mirrors Money::decimalsFor.
 *
 * @since 1.0.0
 */
export function decimalsFor( cents, currency ) {
    const code  = String( currency || '' ).trim().toUpperCase() || defaultCurrency();
    const units = minorUnitsFor( code );
    const whole = ( ( Number( cents ) || 0 ) % 100 ) === 0;

    if ( code === defaultCurrency() && whole ) {
        return Math.min( units, Math.max( 0, Number( numberFormat().decimalPlaces ) || 0 ) );
    }
    return units;
}

/**
 * A cents amount in `currency`, with the org's separators and symbol position.
 * Pass { compact: true } to drop the decimals on a whole amount.
 *
 * @since 1.0.0
 */
export function formatAmount( cents, currency = '', opts = {} ) {
    const fmt   = numberFormat();
    const n     = Number( cents ) || 0;
    const code  = String( currency || '' ).trim().toUpperCase() || defaultCurrency();
    const whole = ( n % 100 ) === 0;

    const dp = ( opts.compact && whole ) ? 0 : decimalsFor( n, code );
    // Rounded here, half away from zero, because groupDigits truncates at zero
    // places where PHP's number_format rounds: 1234.56 yen is 1,235 on the
    // receipt, and a screen that says 1,234 is a second answer to one question.
    const scale  = 10 ** dp;
    const major  = ( n < 0 ? -1 : 1 ) * Math.round( ( Math.abs( n ) * scale ) / 100 ) / scale;
    const number = groupDigits( major, fmt.thousandSep, fmt.decimalSep, dp );
    // The injected symbol is the base currency's, so every other code takes its
    // own from the table rather than inheriting one it is not paid in.
    const symbol = ( code === defaultCurrency() && fmt.symbol )
        ? fmt.symbol
        : ( CURRENCY_SYMBOLS[ code ] || code );

    return fmt.symbolPosition === 'after' ? `${ number } ${ symbol }` : `${ symbol }${ number }`;
}

/** @since 1.0.0 */
export function formatAmountCompact( cents, currency = '' ) {
    return formatAmount( cents, currency, { compact: true } );
}
