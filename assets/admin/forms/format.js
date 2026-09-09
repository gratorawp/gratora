import { __ } from '@wordpress/i18n';
// MySQL strings arrive in UTC with no zone marker, which a browser reads as
// local time. parseTimestamp marks them.
import { parseTimestamp } from '@gratora/ui/utils/format';

export const STATUS_LABEL = {
    draft:     __( 'Draft', 'gratora' ),
    published: __( 'Published', 'gratora' ),
    archived:  __( 'Archived', 'gratora' ),
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
    params.set( 'page', 'gratora-forms' );
    params.set( 'form', String( id ) );
    return `${ window.location.pathname }?${ params.toString() }`;
}

/** Back-link from form editor → campaign detail (Forms tab). */
export function campaignHref( campaignId ) {
    const p = new URLSearchParams();
    p.set( 'page', 'gratora-campaigns' );
    p.set( 'view', 'detail' );
    p.set( 'id', String( campaignId ) );
    p.set( 'tab', 'forms' );
    return `${ window.location.pathname }?${ p.toString() }`;
}

/**
 * Where the editor's back link goes. A form belongs to a campaign only
 * optionally, and without one there is no campaign detail page to return to,
 * so the link falls back to the forms list. Both destinations are a list of
 * forms, which is what the link says.
 */
export function formsBackHref( campaignId ) {
    const id = Number( campaignId ) || 0;
    if ( id > 0 ) return campaignHref( id );

    const p = new URLSearchParams();
    p.set( 'page', 'gratora-forms' );
    return `${ window.location.pathname }?${ p.toString() }`;
}
