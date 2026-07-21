// Client-side currency conversion for the donation form. Rates come from
// config.fx = { base, rates: { CCY: unitsPerBase } } (FxRates effective
// rates). If a rate is missing we leave the amount unconverted rather than
// guess - the donor still gives a valid amount, just not FX-equivalent.

// Currencies charged in whole major units (the standard Stripe zero-decimal
// set). Superset of the server's enforcement list: ISK and UGX are stored
// two-decimal on the wire but their decimals must always be 00, so whole-unit
// amounts are the only safe values for them too.
const ZERO_DECIMAL = [
    'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'MGA',
    'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
];

/** True when `currency` does not support fractional major units. */
export function isZeroDecimal( currency ) {
    return ZERO_DECIMAL.includes( String( currency || '' ).toUpperCase() );
}

/** Convert integer minor units between currencies. Safe no-op when unknown. */
export function convertCents( fx, cents, from, to ) {
    const n = Number( cents ) || 0;
    if ( ! from || ! to || from === to || ! fx || ! fx.rates ) return n;
    const rf = Number( fx.rates[ from ] );
    const rt = Number( fx.rates[ to ] );
    if ( ! ( rf > 0 ) || ! ( rt > 0 ) ) return n;
    return Math.round( ( n * rt ) / rf );
}

/**
 * Round a converted preset to a clean, human figure so tiles never read
 * "$10.84". Step scales with magnitude (whole-unit math, then back to cents).
 */
export function nicePreset( cents ) {
    const n = Number( cents ) || 0;
    if ( n <= 0 ) return n;
    const units = n / 100;
    let step; // in major units
    if ( units >= 100 ) step = 10;
    else if ( units >= 20 ) step = 5;
    else step = 1;
    const rounded = Math.max( step, Math.round( units / step ) * step );
    return rounded * 100;
}

/**
 * Preset value to show/charge in `currency`. Identity (authored value) when
 * it equals the preset's own currency; otherwise convert + nice-round.
 */
export function displayPreset( fx, cents, presetCurrency, currency ) {
    if ( ! currency || currency === presetCurrency ) return Number( cents ) || 0;
    return nicePreset( convertCents( fx, cents, presetCurrency, currency ) );
}
