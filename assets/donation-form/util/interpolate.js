// Supported label tokens: {amount} (formatted currency),
// {frequency} (recurring frequency, empty when not set).

import { formatAmount } from './format';
import { decodeEntities } from './entities';
import { coveredFeeCents } from '../state/store';

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
    const text = decodeEntities( label );
    if ( ! /\{amount\}|\{frequency\}/.test( text ) ) return text;

    // Match the summary Total: include the processing fee only when the donor
    // covers it AND the cover-fees field is not condition-hidden, so the
    // button never promises a different figure than the payload charges.
    const baseCents = state?.values?.amount_cents || 0;
    const feeCents  = state ? coveredFeeCents( state ) : 0;
    const amount = formatAmount( baseCents + feeCents, state?.currency );
    const freqKey = state?.values?.frequency || '';
    const freq = freqKey
        ? ( config?.i18n?.[ FREQ_I18N[ freqKey ] ] || FALLBACK_FREQ[ freqKey ] || '' )
        : '';

    return text
        .replace( /\{amount\}/g, amount )
        .replace( /\{frequency\}/g, freq )
        .replace( /\s+/g, ' ' )
        .trim();
}
