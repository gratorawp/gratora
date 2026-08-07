// One money source: @dono/ui. The public form has no window.dono, so its
// number format arrives through the explicit override channel - bootstrap
// calls setActiveNumberFormat(config.numberFormat) once before render and
// every call site stays zero-arg.
export {
    setActiveNumberFormat,
    getActiveNumberFormat,
    formatAmount,
    formatAmountCompact,
    groupDigits,
    parseAmount,
} from '@dono/ui/utils/format';

/**
 * How often this donation repeats, in the donor's language. Lives here rather
 * than in the step that first needed it: the confirm summary and the thank-you
 * card both say it, and two copies drift.
 */
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

/** Empty for a one-off, which needs no label of its own. */
export function frequencyLabel( frequency, i18n = {} ) {
    const freq = String( frequency || '' );
    if ( ! freq || freq === 'one-time' || freq === 'one_time' ) return '';

    return i18n[ FREQUENCY_I18N_KEYS[ freq ] ] || FREQUENCY_DEFAULTS[ freq ] || freq;
}
