// The public form has no window.dono, so bootstrap must call
// setActiveNumberFormat(config.numberFormat) once before render.
export {
    setActiveNumberFormat,
    getActiveNumberFormat,
    formatAmount,
    formatAmountCompact,
    groupDigits,
    parseAmount,
} from '@dono/ui/utils/format';

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
