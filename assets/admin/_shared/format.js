/**
 * Re-exports @fundkit/ui's generic formatters so call sites importing '_shared/format'
 * stay stable; the FundKit-specific admin routing helpers stay local.
 */
import { __, sprintf } from '@wordpress/i18n';
import {
    currencyDecimals,
    groupDigits,
    formatDate,
    parseTimestamp,
    timeAgo as relativeTimeAgo,
} from '@fundkit/ui/utils/format';

export { currencyDecimals, groupDigits, formatDate };

/**
 * How long ago something happened, or the date when it has not happened yet.
 *
 * The shared helper clamps a negative age to zero, so anything dated in the
 * future comes back as "just now". A donation an admin recorded for later
 * today then sat at the top of the dashboard claiming to have arrived this
 * second, which is the most reassuring reading of the data and the wrong one.
 * There is no honest relative phrase for something that has not happened, so
 * it falls back to the date, which is what the helper already does for
 * anything older than a week.
 *
 * A minute of slack, because a server clock and a browser clock disagree by
 * seconds and a donation made this instant must not read as a date.
 *
 * Past a week the shared helper returns the date, and these columns already
 * print the date underneath: the row then said "Aug 25, 2026" twice and the
 * relative line stopped telling anyone anything. It keeps counting instead.
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
        return sprintf( __( '%dw ago', 'fundraising-toolkit' ), Math.floor( days / 7 ) );
    }

    if ( days < 365 ) {
        /* translators: %d: number of months */
        return sprintf( __( '%dmo ago', 'fundraising-toolkit' ), Math.max( 1, Math.floor( days / 30.44 ) ) );
    }

    /* translators: %d: number of years */
    return sprintf( __( '%dy ago', 'fundraising-toolkit' ), Math.max( 1, Math.floor( days / 365.25 ) ) );
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
    draft:     __( 'Draft', 'fundraising-toolkit' ),
    published: __( 'Active', 'fundraising-toolkit' ),
    archived:  __( 'Archived', 'fundraising-toolkit' ),
    // Derived on the server, not stored: a published campaign outside its
    // schedule or past a goal it closes on. Filterable because the list shows
    // them, and a badge you cannot filter by is a dead end.
    scheduled: __( 'Scheduled', 'fundraising-toolkit' ),
    ended:     __( 'Ended', 'fundraising-toolkit' ),
    goal_met:  __( 'Goal met', 'fundraising-toolkit' ),
};

export function listHref() {
    return `${ window.location.pathname }?page=fundkit-campaigns`;
}

export function detailHref( id, tab = 'overview' ) {
    const p = new URLSearchParams();
    p.set( 'page', 'fundkit-campaigns' );
    p.set( 'view', 'detail' );
    p.set( 'id', String( id ) );
    p.set( 'tab', tab );
    return `${ window.location.pathname }?${ p.toString() }`;
}

export function formEditorHref( formId ) {
    const p = new URLSearchParams();
    p.set( 'page', 'fundkit-forms' );
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
