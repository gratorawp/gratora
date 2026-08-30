import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { StickyNote } from 'lucide-react';

import EmptyState from '../../../_shared/components/EmptyState';
import ConfirmDialog from '../../../_shared/components/ConfirmDialog';
import { formatDateTime, timeAgo, initials } from '../helpers';
import { IconTrash } from '../icons';

export default function NotesTab( { donorId, notes: initialNotes, total, onChanged } ) {
    const [ notes, setNotes ] = useState( initialNotes || [] );
    const [ body, setBody ]   = useState( '' );
    const [ saving, setSaving ] = useState( false );
    const [ error, setError ]   = useState( null );
    const [ confirm, setConfirm ] = useState( null );

    const submit = async ( e ) => {
        e.preventDefault();
        if ( ! body.trim() ) return;
        setSaving( true );
        setError( null );
        try {
            const note = await apiFetch( {
                path:   `/fundkit/v1/admin/donors/${ donorId }/notes`,
                method: 'POST',
                data:   { body: body.trim() },
            } );
            setNotes( ( ns ) => [ note, ...ns ] );
            setBody( '' );
            onChanged?.();
        } catch ( err ) {
            setError( err?.message || 'Could not save' );
        } finally {
            setSaving( false );
        }
    };

    const remove = ( noteId ) => {
        setConfirm( {
            title:        __( 'Delete note', 'fundkit-fundraising-campaigns' ),
            message:      __( 'Delete this note?', 'fundkit-fundraising-campaigns' ),
            confirmLabel: __( 'Delete', 'fundkit-fundraising-campaigns' ),
            destructive:  true,
            onConfirm: async () => {
                try {
                    await apiFetch( {
                        path:   `/fundkit/v1/admin/donors/notes/${ noteId }`,
                        method: 'DELETE',
                    } );
                    setNotes( ( ns ) => ns.filter( ( n ) => n.id !== noteId ) );
                    onChanged?.();
                } catch ( err ) {
                    setError( err?.message || 'Could not delete' );
                }
            },
        } );
    };

    // listForDonor is capped. Without this the list simply ends, and the older
    // notes are reachable only through the donor data export.
    const withheld = Math.max( 0, ( total ?? notes.length ) - notes.length );

    return (
        <div>
            { withheld > 0 && (
                <p className="dp-tab-note">
                    { sprintf(
                        /* translators: 1: notes shown, 2: notes in total */
                        __( 'Showing the %1$d most recent of %2$d notes.', 'fundkit-fundraising-campaigns' ),
                        notes.length,
                        total
                    ) }
                </p>
            ) }
            <div className="dp-card">
                <div className="dp-card__body">
                    { notes.length === 0
                        ? (
                            <EmptyState
                                compact
                                icon={ <StickyNote size={ 22 } strokeWidth={ 1.75 } /> }
                                title={ __( 'No notes yet', 'fundkit-fundraising-campaigns' ) }
                                body={ __( 'Add a note to capture context about this donor (preferred contact, stewardship plan, etc.).', 'fundkit-fundraising-campaigns' ) }
                            />
                        )
                        : (
                            <div className="dp-notes-list">
                                { notes.map( ( n ) => {
                                    const author = n.author_display_name || ( n.author_user_id ? __( 'Unknown user', 'fundkit-fundraising-campaigns' ) : __( 'System', 'fundkit-fundraising-campaigns' ) );
                                    return (
                                        <div key={ n.id } className="dp-note">
                                            <span className="dp-note__avatar" aria-hidden="true">{ initials( author ) }</span>
                                            <div className="dp-note__body">
                                                <div className="dp-note__head">
                                                    <strong>{ author }</strong>
                                                    { n.author_role && (
                                                        <span className="dp-note__role">{ n.author_role.replace( /_/g, ' ' ) }</span>
                                                    ) }
                                                    <span title={ formatDateTime( n.created_at ) }>{ timeAgo( n.created_at ) }</span>
                                                </div>
                                                <div className="dp-note__text">{ n.body }</div>
                                            </div>
                                            { n.can_delete && (
                                                <button
                                                    type="button"
                                                    className="dp-note__delete"
                                                    aria-label={ __( 'Delete note', 'fundkit-fundraising-campaigns' ) }
                                                    onClick={ () => remove( n.id ) }
                                                >
                                                    <IconTrash width="14" height="14" />
                                                </button>
                                            ) }
                                        </div>
                                    );
                                } ) }
                            </div>
                        ) }

                    <form className="dp-note-form" onSubmit={ submit }>
                        <textarea className="fundkit-textarea"
                            value={ body }
                            onChange={ ( e ) => setBody( e.target.value ) }
                            placeholder={ __( 'Add a note about this donor…', 'fundkit-fundraising-campaigns' ) }
                            rows={ 3 }
                        />
                        { error && <div className="dp-note-form__error">{ error }</div> }
                        <div className="dp-note-form__actions">
                            <button
                                type="submit"
                                className="btn btn--primary"
                                disabled={ saving || ! body.trim() }
                            >
                                { saving ? __( 'Saving…', 'fundkit-fundraising-campaigns' ) : __( 'Add note', 'fundkit-fundraising-campaigns' ) }
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
        </div>
    );
}
