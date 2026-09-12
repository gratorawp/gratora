/**
 * Re-exports @gratora/ui's generic formatters so call sites importing '_shared/format'
 * stay stable; the Gratora-specific admin routing helpers stay local.
 */
import { __, sprintf } from '@wordpress/i18n';
import {
    currencyDecimals,
    groupDigits,
    formatDate,
    parseTimestamp,
    timeAgo as relativeTimeAgo,
} from '@gratora/ui/utils/format';

export { currencyDecimals, groupDigits, formatDate };

/**
 * Show dates for future timestamps with one minute of clock-skew tolerance. Keep older
 * timestamps relative because the row already displays their date.
 */
export function timeAgo( iso ) {
    if ( ! iso ) {
        return relativeTimeAgo( iso );
    }

    const at = parseTimestamp( iso );
    if ( Number.isNaN( at.getTime() ) ) {
        return relativeTimeAgo( iso );
    }

    if ( at.getTime() > Date.now() + 60000 ) {
        return formatDate( iso );
    }

    const days = ( Date.now() - at.getTime() ) / 86400000;

    // Below a week the shared helper already answers in minutes, hours and days.
    if ( days < 7 ) {
        return relativeTimeAgo( iso );
    }

    if ( days < 30 ) {
        /* translators: %d: number of weeks */
        return sprintf( __( '%dw ago', 'gratora-donation-platform' ), Math.floor( days / 7 ) );
    }

    if ( days < 365 ) {
        /* translators: %d: number of months */
        return sprintf( __( '%dmo ago', 'gratora-donation-platform' ), Math.max( 1, Math.floor( days / 30.44 ) ) );
    }

    /* translators: %d: number of years */
    return sprintf( __( '%dy ago', 'gratora-donation-platform' ), Math.max( 1, Math.floor( days / 365.25 ) ) );
}
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
    draft:     __( 'Draft', 'gratora-donation-platform' ),
    published: __( 'Active', 'gratora-donation-platform' ),
    archived:  __( 'Archived', 'gratora-donation-platform' ),
    // Derived on the server, not stored: a published campaign outside its
    // schedule or past a goal it closes on. Filterable because the list shows
    // them, and a badge you cannot filter by is a dead end.
    scheduled: __( 'Scheduled', 'gratora-donation-platform' ),
    ended:     __( 'Ended', 'gratora-donation-platform' ),
    goal_met:  __( 'Goal met', 'gratora-donation-platform' ),
};

export function listHref() {
    return `${ window.location.pathname }?page=gratora-campaigns`;
}

export function detailHref( id, tab = 'overview' ) {
    const p = new URLSearchParams();
    p.set( 'page', 'gratora-campaigns' );
    p.set( 'view', 'detail' );
    p.set( 'id', String( id ) );
    p.set( 'tab', tab );
    return `${ window.location.pathname }?${ p.toString() }`;
}

export function formEditorHref( formId ) {
    const p = new URLSearchParams();
    p.set( 'page', 'gratora-forms' );
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
