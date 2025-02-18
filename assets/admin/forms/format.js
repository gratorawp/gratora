import { __ } from '@wordpress/i18n';

export const STATUS_LABEL = {
    draft:     __( 'Draft', 'dono' ),
    published: __( 'Published', 'dono' ),
    archived:  __( 'Archived', 'dono' ),
};

export function formatDate( iso, opts = {} ) {
    if ( ! iso ) return '-';
    const d = new Date( String( iso ).replace( ' ', 'T' ) );
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
    params.set( 'page', 'dono-forms' );
    params.set( 'view', 'edit' );
    params.set( 'form', String( id ) );
    return `${ window.location.pathname }?${ params.toString() }`;
}

/** Back-link from form editor → campaign detail (Forms tab). */
export function campaignHref( campaignId ) {
    const p = new URLSearchParams();
    p.set( 'page', 'dono-campaigns' );
    p.set( 'view', 'detail' );
    p.set( 'id', String( campaignId ) );
    p.set( 'tab', 'forms' );
    return `${ window.location.pathname }?${ p.toString() }`;
}
