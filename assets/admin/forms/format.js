import { __ } from '@wordpress/i18n';
// MySQL strings arrive in UTC with no zone marker, which a browser reads as
// local time. parseTimestamp marks them.
import { parseTimestamp } from '@fundkit/ui/utils/format';

export const STATUS_LABEL = {
    draft:     __( 'Draft', 'fundkit-fundraising-campaigns' ),
    published: __( 'Published', 'fundkit-fundraising-campaigns' ),
    archived:  __( 'Archived', 'fundkit-fundraising-campaigns' ),
};

export function formatDate( iso, opts = {} ) {
    if ( ! iso ) return '-';
    const d = parseTimestamp( iso );
    if ( Number.isNaN( d.getTime() ) ) return iso;
    return d.toLocaleString( undefined, {
        year:   'numeric',
        month:  'short',
        day:    '2-digit',
        hour:   '2-digit',
        minute: '2-digit',
        ...opts,
    } );
}

export function editorHref( id ) {
    const params = new URLSearchParams();
    params.set( 'page', 'fundkit-forms' );
    params.set( 'form', String( id ) );
    return `${ window.location.pathname }?${ params.toString() }`;
}

/** Back-link from form editor → campaign detail (Forms tab). */
export function campaignHref( campaignId ) {
    const p = new URLSearchParams();
    p.set( 'page', 'fundkit-campaigns' );
    p.set( 'view', 'detail' );
    p.set( 'id', String( campaignId ) );
    p.set( 'tab', 'forms' );
    return `${ window.location.pathname }?${ p.toString() }`;
}
