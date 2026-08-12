import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

import Btn from '../_shared/components/Btn';
import { notify } from '../_shared/notify';
import { timeAgo } from '../_shared/format';

export default function CoverImageCard( { id, url, onChange } ) {
    const pick   = () => openMediaFrame( { currentId: id, onSelect: onChange } );
    const remove = () => onChange( null );

    if ( ! url ) {
        return (
            <div className="dono-cover-card" style={ { gridTemplateColumns: '1fr' } }>
                <div>
                    <Btn variant="primary" onClick={ pick }>{ __( 'Select an image', 'dono-fundraising-platform' ) }</Btn>
                    <div style={ { marginTop: 8, fontSize: 12, color: '#6b7280' } }>
                        { __( '1600 × 900 (16:9) recommended.', 'dono-fundraising-platform' ) }
                    </div>
                </div>
            </div>
        );
    }

    return (
        <div className="dono-cover-card">
            <div className="dono-cover-card__thumb" style={ { backgroundImage: `url(${ url })` } } />
            <div className="dono-cover-card__meta">
                <AttachmentMeta id={ id } url={ url } />
                <div className="dono-cover-card__actions">
                    <Btn variant="secondary" size="sm" onClick={ pick }>{ __( 'Replace', 'dono-fundraising-platform' ) }</Btn>
                    <Btn variant="ghost" size="sm" onClick={ remove }>{ __( 'Remove', 'dono-fundraising-platform' ) }</Btn>
                </div>
                <span className="dono-cover-card__chip">{ __( 'Cropped on cards · 16:9 expected', 'dono-fundraising-platform' ) }</span>
            </div>
        </div>
    );
}

function AttachmentMeta( { id, url } ) {
    const [ meta, setMeta ] = useState( null );

    useEffect( () => {
        if ( ! id ) { setMeta( null ); return; }
        let cancelled = false;
        apiFetch( { path: `/wp/v2/media/${ id }` } )
            .then( ( a ) => {
                if ( cancelled ) return;
                const sizes  = a.media_details?.sizes || {};
                const original = a.media_details || {};
                setMeta( {
                    filename:  ( a.media_details?.file || a.slug || '' ).split( '/' ).pop(),
                    width:     original.width  || sizes.full?.width  || null,
                    height:    original.height || sizes.full?.height || null,
                    filesize:  original.filesize || a.media_details?.filesize || null,
                    mime:      a.mime_type || null,
                    uploaded:  a.date_gmt ? `${ a.date_gmt }Z` : null,
                } );
            } )
            .catch( () => { if ( ! cancelled ) setMeta( { filename: url.split( '/' ).pop() } ); } );
        return () => { cancelled = true; };
    }, [ id, url ] );

    if ( ! meta ) {
        return <strong>{ url.split( '/' ).pop() }</strong>;
    }

    const dims  = meta.width && meta.height ? `${ meta.width } × ${ meta.height }` : null;
    const size  = formatBytes( meta.filesize );
    const mime  = meta.mime ? meta.mime.split( '/' ).pop().toUpperCase() : null;
    const parts = [ dims, size, mime ].filter( Boolean ).join( ' · ' );

    return (
        <>
            <strong>{ meta.filename || __( 'Cover image', 'dono-fundraising-platform' ) }</strong>
            { parts && <>{ parts }<br /></> }
            { meta.uploaded && sprintf(
                /* translators: %s: relative time, e.g. "12d ago" */
                __( 'Uploaded %s', 'dono-fundraising-platform' ),
                timeAgo( meta.uploaded ),
            ) }
        </>
    );
}

function formatBytes( bytes ) {
    if ( ! bytes && bytes !== 0 ) return null;
    if ( bytes < 1024 ) return `${ bytes } B`;
    if ( bytes < 1024 * 1024 ) return `${ ( bytes / 1024 ).toFixed( 0 ) } KB`;
    return `${ ( bytes / ( 1024 * 1024 ) ).toFixed( 1 ) } MB`;
}

function openMediaFrame( { onSelect, currentId } ) {
    if ( ! window.wp?.media ) {
        notify.error( __( 'Media library not loaded.', 'dono-fundraising-platform' ) );
        return;
    }
    const frame = window.wp.media( {
        title:    __( 'Select campaign cover image', 'dono-fundraising-platform' ),
        button:   { text: __( 'Use this image', 'dono-fundraising-platform' ) },
        library:  { type: 'image' },
        multiple: false,
    } );
    if ( currentId ) {
        frame.on( 'open', () => {
            const selection  = frame.state().get( 'selection' );
            const attachment = window.wp.media.attachment( currentId );
            attachment.fetch();
            selection.reset( [ attachment ] );
        } );
    }
    frame.on( 'select', () => {
        const a = frame.state().get( 'selection' ).first().toJSON();
        const url = a.sizes?.large?.url || a.sizes?.full?.url || a.url;
        onSelect( { id: a.id, url } );
    } );
    frame.open();
}
