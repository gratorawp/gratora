import {
    setActiveNumberFormat as setNumberFormat,
    getActiveNumberFormat,
    defaultCurrency,
    groupDigits,
} from '@dono/ui/utils/format';
import { minorUnitsFor } from '../../_shared/money';

// parseAmount is deliberately not re-exported: it reads a typed figure by
// stripping the org's configured thousands separator, which is what
// typedAmountToNumber exists to avoid.
export { getActiveNumberFormat, groupDigits };

// ISO 4217 to symbol, kept in step with Money::SYMBOLS so the form and the
// receipt it triggers name the same currency. A code that is not here renders
// as itself, which is honest; borrowing another currency's symbol is not.
const SYMBOLS = {
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

// The currency the form was authored in: the one the server derived
// numberFormat.symbol from, and the only one the org's display preferences
// describe. A donor who switches currency is charged in theirs, so nothing of
// this form's own currency may travel with the figure.
let formCurrency = '';

/**
 * The public form has no window.dono, so bootstrap calls this once before
 * render with the form's config and the currency that config was built for.
 */
export function setActiveNumberFormat( fmt, currency = '' ) {
    setNumberFormat( fmt );
    formCurrency = String( currency || '' ).trim().toUpperCase();
}

/**
 * A cents amount in `currency`, with the org's separators and symbol position.
 * Pass { compact: true } to drop the decimals on a whole amount.
 */
export function formatAmount( cents, currency = '', opts = {} ) {
    const fmt   = getActiveNumberFormat();
    const n     = Number( cents ) || 0;
    const code  = ( String( currency || '' ).trim() || formCurrency || defaultCurrency() ).toUpperCase();
    const own   = code === ( formCurrency || defaultCurrency() );
    const whole = ( n % 100 ) === 0;

    // "Decimal places" is a display preference, and it may only drop minor
    // units an amount does not have: rendering $26 for the $26.54 about to be
    // charged asks the donor to agree to a figure nobody takes. Nor may it add
    // places the currency lacks, or a yen form prints hundredths of a yen. It
    // describes the currency this form was authored in, the one fmt was
    // resolved for, where Money::decimalsFor scopes the same rule to the org
    // base currency.
    const places = ( own && whole )
        ? Math.min( minorUnitsFor( code ), Math.max( 0, Number( fmt.decimalPlaces ) || 0 ) )
        : minorUnitsFor( code );

    const dp = ( opts.compact && whole ) ? 0 : places;
    // Rounded here, half away from zero, because groupDigits truncates at zero
    // places where PHP's number_format rounds. The confirm step's total is the
    // figure the donor agrees to, so it has to be the one the gateway takes.
    const scale  = 10 ** dp;
    const major  = ( n < 0 ? -1 : 1 ) * Math.round( ( Math.abs( n ) * scale ) / 100 ) / scale;
    const number = groupDigits( major, fmt.thousandSep, fmt.decimalSep, dp );
    // The injected symbol belongs to the form's own currency alone. Letting
    // any other code inherit it prints a currency the donor is not paying in.
    const symbol = SYMBOLS[ code ] || ( own ? fmt.symbol : '' ) || code;

    return fmt.symbolPosition === 'after' ? `${ number } ${ symbol }` : `${ symbol }${ number }`;
}

export function formatAmountCompact( cents, currency = '' ) {
    return formatAmount( cents, currency, { compact: true } );
}

const FREQUENCY_DEFAULTS = {
    'weekly':    'Weekly',
    'biweekly':  'Every 2 weeks',
    'monthly':   'Monthly',
    'quarterly': 'Quarterly',
    'yearly':    'Yearly',
};

const FREQUENCY_I18N_KEYS = {
    'weekly':    'freqWeekly',
    'biweekly':  'freqBiweekly',
    'monthly':   'freqMonthly',
    'quarterly': 'freqQuarterly',
    'yearly':    'freqYearly',
};

export function frequencyLabel( frequency, i18n = {} ) {
    const freq = String( frequency || '' );
    if ( ! freq || freq === 'one-time' || freq === 'one_time' ) return '';

    return i18n[ FREQUENCY_I18N_KEYS[ freq ] ] || FREQUENCY_DEFAULTS[ freq ] || freq;
}
