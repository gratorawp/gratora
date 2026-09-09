import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { StickyNote } from 'lucide-react';

import EmptyState from '../../../_shared/components/EmptyState';
import ConfirmDialog from '../../../_shared/components/ConfirmDialog';
import { formatDateTime, timeAgo, initials } from '../helpers';
import { IconTrash } from '../icons';
import { userCan } from '../../../_shared/caps';

export default function NotesCard( { donationRef, notes: initial, onChanged } ) {
    const [ notes, setNotes ] = useState( initial || [] );
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
                path:   `/gratora/v1/admin/donations/${ donationRef }/notes`,
                method: 'POST',
                data:   { body: body.trim() },
            } );
            setNotes( ( ns ) => [ note, ...ns ] );
            setBody( '' );
            onChanged?.();
        } catch ( err ) {
            setError( err?.message || __( 'Could not save', 'gratora' ) );
        } finally {
            setSaving( false );
        }
    };

    const remove = ( noteId ) => {
        setConfirm( {
            title:        __( 'Delete note', 'gratora' ),
            message:      __( 'Delete this note?', 'gratora' ),
            confirmLabel: __( 'Delete', 'gratora' ),
            destructive:  true,
            onConfirm: async () => {
                try {
                    await apiFetch( {
                        path:   `/gratora/v1/admin/donations/notes/${ noteId }`,
                        method: 'DELETE',
                    } );
                    setNotes( ( ns ) => ns.filter( ( n ) => n.id !== noteId ) );
                    onChanged?.();
                } catch ( err ) {
                    setError( err?.message || __( 'Could not delete', 'gratora' ) );
                }
            },
        } );
    };

    return (
        <div className="dd-card">
            <div className="dd-card__body">
                { notes.length === 0
                    ? (
                        <EmptyState
                            compact
                            icon={ <StickyNote size={ 22 } strokeWidth={ 1.75 } /> }
                            title={ __( 'No notes yet', 'gratora' ) }
                            body={ __( 'Add notes to keep context attached to this donation: refund reasons, follow-ups, special handling.', 'gratora' ) }
                        />
                    )
                    : (
                        <div className="dd-notes-list">
                            { notes.map( ( n ) => {
                                const author = n.author_display_name || ( n.author_user_id ? __( 'Unknown user', 'gratora' ) : __( 'System', 'gratora' ) );
                                return (
                                    <div key={ n.id } className="dd-note">
                                        <span className="dd-avatar dd-avatar--md">{ initials( author ) }</span>
                                        <div className="dd-note__body">
                                            <div className="dd-note__head">
                                                <strong>{ author }</strong>
                                                { n.author_role && (
                                                    <span className="dd-note__role">{ n.author_role.replace( /_/g, ' ' ) }</span>
                                                ) }
                                                <span title={ formatDateTime( n.created_at ) }>{ timeAgo( n.created_at ) }</span>
                                            </div>
                                            <div className="dd-note__text">{ n.body }</div>
                                        </div>
                                        { userCan( 'edit_donations' ) && (
                                        <button
                                            type="button"
                                            className="dd-note__delete"
                                            aria-label={ __( 'Delete note', 'gratora' ) }
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

                { userCan( 'edit_donations' ) && (
                <form className="dd-note-form" onSubmit={ submit }>
                    <textarea
                        value={ body }
                        onChange={ ( e ) => setBody( e.target.value ) }
                        placeholder={ __( 'Write a note about this donation. Notes are visible to admins only.', 'gratora' ) }
                        rows={ 3 }
                    />
                    { error && <div className="dd-note-form__error">{ error }</div> }
                    <div className="dd-note-form__actions">
                        <button
                            type="submit"
                            className="btn btn--primary"
                            disabled={ saving || ! body.trim() }
                        >
                            { saving ? __( 'Saving…', 'gratora' ) : __( 'Add note', 'gratora' ) }
                        </button>
                    </div>
                </form>
                ) }
            </div>

            <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
        </div>
    );
}
