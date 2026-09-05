/**
 * Convenience wrapper over @wordpress/core-data's useEntityRecord for fundkit/v1
 * entities (campaign, form, ...). Returns the merged record (saved + pending
 * edits) plus bind/bindNumber/setValue helpers for plain inputs.
 */

import { useEntityRecord, store as coreDataStore } from '@wordpress/core-data';
import { useSelect, useDispatch } from '@wordpress/data';

const KIND = 'fundkit/v1';

export function useFundKitRecord( name, id ) {
    const {
        record,
        editedRecord,
        edit: editEntity,
        save,
        hasEdits,
        hasResolved,
        isResolving,
    } = useEntityRecord( KIND, name, id );

    const isSaving = useSelect(
        ( select ) =>
            !! select( 'core' ).isSavingEntityRecord( KIND, name, id ),
        [ name, id ]
    );

    const edits = useSelect(
        ( select ) =>
            select( coreDataStore ).getEntityRecordEdits( KIND, name, id ) || {},
        [ name, id ]
    );

    // Why the resolution ended, not just that it ended: a 500 or a dropped
    // connection resolves the same way a missing record does, and telling a
    // reader their form does not exist is a different sentence.
    const loadError = useSelect(
        ( select ) =>
            select( coreDataStore ).getResolutionError( 'getEntityRecord', [ KIND, name, id ] ),
        [ name, id ]
    );

    const { editEntityRecord, saveEntityRecord, invalidateResolution } = useDispatch( coreDataStore );

    const discard = () => {
        if ( ! record || ! edits ) return;
        const reverted = {};
        for ( const k of Object.keys( edits ) ) {
            reverted[ k ] = record[ k ] ?? null;
        }
        if ( Object.keys( reverted ).length ) {
            editEntityRecord( KIND, name, id, reverted );
        }
    };

    const merged = editedRecord && Object.keys( editedRecord ).length > 0
        ? editedRecord
        : ( record || {} );

    const edit  = ( patch ) => editEntity( patch );
    const value = ( key, fallback = '' ) =>
        merged[ key ] === undefined || merged[ key ] === null ? fallback : merged[ key ];

    return {
        record:  merged,
        savedRecord: record,
        edits,
        isEdited: ( key ) => edits && edits[ key ] !== undefined,
        edit,
        save,
        // saveEntity bypasses core-data's store path; use when edit() and save() fire
        // in the same tick (saveEditedEntityRecord silently no-ops before the edit settles).
        saveEntity: ( patch ) =>
            saveEntityRecord( KIND, name, { id, ...patch }, { throwOnError: true } ),
        discard,
        isDirty:   hasEdits,
        isSaving,
        isLoading: ! hasResolved && isResolving,
        loadError,
        notFound:  hasResolved && ! record && ! loadError,
        reload:    () => invalidateResolution( 'getEntityRecord', [ KIND, name, id ] ),
        value,
        bind: ( key, fallback = '' ) => ( {
            value:    value( key, fallback ),
            onChange: ( e ) => edit( { [ key ]: e.target.value } ),
        } ),
        // bindNumber coerces empty string to null, non-empty to Number.
        bindNumber: ( key ) => ( {
            value: merged[ key ] ?? '',
            onChange: ( e ) =>
                edit( {
                    [ key ]: e.target.value === '' ? null : Number( e.target.value ),
                } ),
        } ),
        setValue: ( key ) => ( v ) => edit( { [ key ]: v } ),
    };
}
