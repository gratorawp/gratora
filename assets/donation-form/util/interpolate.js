// Supported label tokens: {amount} (formatted currency),
// {frequency} (recurring frequency, empty when not set).

import { formatAmount } from './format';
import { computeFees } from '../state/store';

const FALLBACK_FREQ = {
    'one-time':  'once',
    'weekly':    'weekly',
    'biweekly':  'biweekly',
    'monthly':   'monthly',
    'quarterly': 'quarterly',
    'yearly':    'yearly',
};

const FREQ_I18N = {
    'one-time':  'freqOneTime',
    'weekly':    'freqWeekly',
    'biweekly':  'freqBiweekly',
    'monthly':   'freqMonthly',
    'quarterly': 'freqQuarterly',
    'yearly':    'freqYearly',
};

export function interpolateLabel( label, state, config ) {
    if ( ! label ) return config?.i18n?.donateNow || 'Donate';
    if ( ! /\{amount\}|\{frequency\}/.test( label ) ) return label;

    // Match the summary Total: include the processing fee when the donor opted
    // to cover it, so the button never promises less than what is charged.
    const baseCents = state?.values?.amount_cents || 0;
    const feeCents  = state?.values?.cover_fees ? computeFees( state, baseCents ) : 0;
    const amount = formatAmount( baseCents + feeCents, state?.currency );
    const freqKey = state?.values?.frequency || '';
    const freq = freqKey
        ? ( config?.i18n?.[ FREQ_I18N[ freqKey ] ] || FALLBACK_FREQ[ freqKey ] || '' )
        : '';

    return label
        .replace( /\{amount\}/g, amount )
        .replace( /\{frequency\}/g, freq )
        .replace( /\s+/g, ' ' )
        .trim();
}
