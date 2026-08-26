/**
 * Re-exports @giveflow/ui's generic formatters so call sites importing '_shared/format'
 * stay stable; the GiveFlow-specific admin routing helpers stay local.
 */
import { __ } from '@wordpress/i18n';
import { currencyDecimals } from '@giveflow/ui/utils/format';

export {
    currencyDecimals,
    groupDigits,
    formatDate,
    timeAgo,
} from '@giveflow/ui/utils/format';
// Amounts and the org bridge they read come from the local formatter: the org's
// "decimal places" preference belongs to the base currency and may only drop
// places an amount does not use, which is the rule Money::format applies to the
// same figure server-side.
export {
    defaultCurrency,
    numberFormat,
    formatAmount,
    formatAmountCompact,
} from '../../_shared/money';
export { default as StatusBadge } from './components/StatusBadge';

// Campaign lifecycle labels (drive the status-filter options on the campaigns
// list + the detail header). The shared StatusBadge owns its own render map;
// this is just the campaign-scoped label set for filter dropdowns.
export const STATUS_LABEL = {
    draft:     __( 'Draft', 'giveflow-fundraising-campaigns' ),
    published: __( 'Active', 'giveflow-fundraising-campaigns' ),
    archived:  __( 'Archived', 'giveflow-fundraising-campaigns' ),
};

export function listHref() {
    return `${ window.location.pathname }?page=giveflow-campaigns`;
}

export function detailHref( id, tab = 'overview' ) {
    const p = new URLSearchParams();
    p.set( 'page', 'giveflow-campaigns' );
    p.set( 'view', 'detail' );
    p.set( 'id', String( id ) );
    p.set( 'tab', tab );
    return `${ window.location.pathname }?${ p.toString() }`;
}

export function formEditorHref( formId ) {
    const p = new URLSearchParams();
    p.set( 'page', 'giveflow-forms' );
    p.set( 'form', String( formId ) );
    return `${ window.location.pathname }?${ p.toString() }`;
}

/**
 * How finely an operator may type an amount in a given currency.
 *
 * Display decimals follow the currency (JPY none, BHD three), but storage is
 * major x 100 for every one of them, so anything past the second decimal is
 * rounded away before it reaches a gateway. An input that offers a third would
 * let someone type 50.125 and move 50.13 without saying so.
 */
export function amountEntry( currency ) {
    const dp = Math.min( currencyDecimals( currency ), 2 );

    return { dp, step: dp > 0 ? '0.' + '0'.repeat( dp - 1 ) + '1' : '1' };
}
