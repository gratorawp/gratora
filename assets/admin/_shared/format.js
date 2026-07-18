/**
 * Re-exports @dono/ui's generic formatters so call sites importing '_shared/format'
 * stay stable; the Dono-specific admin routing helpers stay local.
 */
import { __ } from '@wordpress/i18n';

export {
    defaultCurrency,
    numberFormat,
    formatAmount,
    formatAmountCompact,
    currencyDecimals,
    groupDigits,
    formatDate,
    timeAgo,
} from '@dono/ui/utils/format';
export { default as StatusBadge } from './components/StatusBadge';

// Campaign lifecycle labels (drive the status-filter options on the campaigns
// list + the detail header). The shared StatusBadge owns its own render map;
// this is just the campaign-scoped label set for filter dropdowns.
export const STATUS_LABEL = {
    draft:     __( 'Draft', 'dono' ),
    published: __( 'Active', 'dono' ),
    archived:  __( 'Archived', 'dono' ),
};

export function listHref() {
    return `${ window.location.pathname }?page=dono-campaigns`;
}

export function detailHref( id, tab = 'overview' ) {
    const p = new URLSearchParams();
    p.set( 'page', 'dono-campaigns' );
    p.set( 'view', 'detail' );
    p.set( 'id', String( id ) );
    p.set( 'tab', tab );
    return `${ window.location.pathname }?${ p.toString() }`;
}

export function formEditorHref( formId ) {
    const p = new URLSearchParams();
    p.set( 'page', 'dono-forms' );
    p.set( 'view', 'edit' );
    p.set( 'form', String( formId ) );
    return `${ window.location.pathname }?${ p.toString() }`;
}
