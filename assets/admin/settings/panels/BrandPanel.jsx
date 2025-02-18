
import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { Palette } from 'lucide-react';

import Card from '../../_shared/components/Card';
import Btn from '../../_shared/components/Btn';
import ConfirmDialog from '../../_shared/components/ConfirmDialog';
import EmptyState from '../../_shared/components/EmptyState';
import Icon from '../../_shared/components/Icon';
import TokenEditor from '../../_shared/styling/TokenEditor';
import StylePreview from '../../_shared/styling/StylePreview';

const PlusIcon  = () => <Icon name="plus"  size={ 16 } />;
const CloneIcon = () => <Icon name="copy"  size={ 14 } />;
const TrashIcon = () => <Icon name="trash" size={ 14 } />;

export default function BrandPanel( { s } ) {
    // Seed from window.dono.styling.presets when the option hasn't been saved yet.
    const saved      = Array.isArray( s.value( 'presets' ) ) ? s.value( 'presets' ) : [];
    const globalList = Array.isArray( window.dono?.styling?.presets ) ? window.dono.styling.presets : [];
    const presets    = saved.length > 0 ? saved : globalList;
    const defaultId  = String( s.value( 'default_id', '' ) || window.dono?.styling?.default_id || '' );

    const [ confirm, setConfirm ] = useState( null );
    const [ activeId, setActiveId ] = useState( () => {
        if ( defaultId && presets.find( ( p ) => p.id === defaultId ) ) return defaultId;
        return presets[ 0 ]?.id || '';
    } );

    const active = presets.find( ( p ) => p.id === activeId ) || presets[ 0 ] || null;

    const writePresets = ( next ) => s.replace( { presets: next } );

    const writePreset = ( id, patch ) => {
        const next = presets.map( ( p ) => ( p.id === id ? { ...p, ...patch } : p ) );
        writePresets( next );
    };

    const writeTokens = ( id, tokens ) => {
        const cleaned = {};
        for ( const k in tokens ) {
            if ( tokens[ k ] !== '' && tokens[ k ] != null ) cleaned[ k ] = tokens[ k ];
        }
        writePreset( id, { tokens: cleaned } );
    };

    const setName = ( id, name ) => writePreset( id, { name } );

    const setDefault = ( id ) => s.edit( { default_id: id } );

    const clonePreset = ( id ) => {
        const source = presets.find( ( p ) => p.id === id );
        if ( ! source ) return;
        const newId   = generateId( source.name, presets );
        const newName = `${ source.name } ${ __( '(copy)', 'dono' ) }`;
        const next    = [ ...presets, {
            id:      newId,
            name:    newName,
            tokens:  { ...( source.tokens || {} ) },
            builtin: false,
        } ];
        writePresets( next );
        setActiveId( newId );
    };

    const addPreset = () => {
        const newId = generateId( __( 'Custom', 'dono' ), presets );
        const next  = [ ...presets, {
            id:      newId,
            name:    __( 'New preset', 'dono' ),
            tokens:  {},
            builtin: false,
        } ];
        writePresets( next );
        setActiveId( newId );
    };

    const deletePreset = ( id ) => {
        const p = presets.find( ( x ) => x.id === id );
        if ( ! p || p.builtin ) return;
        setConfirm( {
            title:       __( 'Delete preset', 'dono' ),
            message:     __( 'Delete this brand preset? This cannot be undone.', 'dono' ),
            destructive: true,
            onConfirm:   () => {
                const next = presets.filter( ( x ) => x.id !== id );
                writePresets( next );
                if ( activeId === id ) setActiveId( next[ 0 ]?.id || '' );
                if ( defaultId === id ) s.edit( { default_id: next[ 0 ]?.id || '' } );
                setConfirm( null );
            },
        } );
    };

    return (
        <div className="dono-panel">
            <div className="dono-brand-layout">
                <div className="dono-brand-layout__main">
                    <Card
                        title={ __( 'Brand presets', 'dono' ) }
                        sub={ __( 'Named style presets. Campaigns and forms pick one as their look. Mark one as the org default for everything that hasn\'t chosen.', 'dono' ) }
                        edited={ s.isDirty }
                    >
                        <div className="dono-preset-mgr">
                            <div className="dono-preset-mgr__list">
                                { presets.map( ( p ) => {
                                    const accent = ( p.tokens && p.tokens[ 'dono-accent' ] )
                                        || ( window.dono?.styling?.defaults || {} )[ 'dono-accent' ]
                                        || '#1e8a4e';
                                    const isActive  = p.id === active?.id;
                                    const isDefault = p.id === defaultId;
                                    return (
                                        <button
                                            key={ p.id }
                                            type="button"
                                            className={ `dono-preset-mgr__row${ isActive ? ' is-active' : '' }` }
                                            onClick={ () => setActiveId( p.id ) }
                                            aria-pressed={ isActive }
                                        >
                                            <span
                                                className="dono-preset-mgr__swatch"
                                                style={ { background: accent } }
                                                aria-hidden="true"
                                            />
                                            <span className="dono-preset-mgr__meta">
                                                <strong className="dono-preset-mgr__name">{ p.name }</strong>
                                                { isDefault && (
                                                    <span className="dono-preset-mgr__default">
                                                        <Icon name="check" size={ 12 } />
                                                        { __( 'Default', 'dono' ) }
                                                    </span>
                                                ) }
                                            </span>
                                        </button>
                                    );
                                } ) }
                                <Button
                                    variant="secondary"
                                    className="dono-preset-mgr__add"
                                    onClick={ addPreset }
                                    icon={ PlusIcon }
                                >
                                    { __( 'Add preset', 'dono' ) }
                                </Button>
                            </div>

                            <div className="dono-preset-mgr__editor">
                                { active ? (
                                    <PresetEditor
                                        preset={ active }
                                        isDefault={ active.id === defaultId }
                                        onRename={ ( v ) => setName( active.id, v ) }
                                        onTokens={ ( v ) => writeTokens( active.id, v ) }
                                        onMakeDefault={ () => setDefault( active.id ) }
                                        onClone={ () => clonePreset( active.id ) }
                                        onDelete={ () => deletePreset( active.id ) }
                                    />
                                ) : (
                                    <EmptyState
                                        compact
                                        icon={ <Palette size={ 22 } strokeWidth={ 1.75 } /> }
                                        title={ __( 'No presets yet', 'dono' ) }
                                        body={ __( 'Brand presets give every campaign a consistent look. Create one to get started.', 'dono' ) }
                                        action={
                                            <Btn variant="secondary" onClick={ addPreset }>
                                                { __( 'Add preset', 'dono' ) }
                                            </Btn>
                                        }
                                    />
                                ) }
                            </div>
                        </div>
                    </Card>
                </div>

                <aside className="dono-brand-layout__rail">
                    <Card title={ __( 'Live preview', 'dono' ) }>
                        { active && (
                            <StylePreview
                                tokens={ active.tokens || {} }
                                layer="brand"
                                styling={ window.dono?.styling || {} }
                            />
                        ) }
                    </Card>
                </aside>
            </div>
            <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
        </div>
    );
}

function PresetEditor( { preset, isDefault, onRename, onTokens, onMakeDefault, onClone, onDelete } ) {
    return (
        <div className="dono-preset-editor">
            <div className="dono-preset-editor__head">
                <input
                    type="text"
                    className="dono-input dono-preset-editor__name"
                    value={ preset.name }
                    onChange={ ( e ) => onRename( e.target.value ) }
                    placeholder={ __( 'Preset name', 'dono' ) }
                />
                <div className="dono-preset-editor__actions">
                    { ! isDefault && (
                        <Button variant="secondary" size="small" onClick={ onMakeDefault }>
                            { __( 'Make default', 'dono' ) }
                        </Button>
                    ) }
                    <Button variant="tertiary" size="small" icon={ CloneIcon } onClick={ onClone }>
                        { __( 'Clone', 'dono' ) }
                    </Button>
                    { ! preset.builtin && (
                        <Button
                            variant="tertiary"
                            size="small"
                            icon={ TrashIcon }
                            isDestructive
                            onClick={ onDelete }
                        >
                            { __( 'Delete', 'dono' ) }
                        </Button>
                    ) }
                </div>
            </div>

            { preset.description && (
                <p className="dono-preset-editor__desc">{ preset.description }</p>
            ) }

            <TokenEditor
                value={ preset.tokens || {} }
                onChange={ onTokens }
                catalogue={ window.dono?.styling?.catalogue || {} }
                groups={ window.dono?.styling?.groups || {} }
                defaults={ window.dono?.styling?.defaults || {} }
            />
        </div>
    );
}

/** Unique slug id, no collision with existing presets. */
function generateId( base, existing ) {
    const slug = String( base || 'preset' )
        .toLowerCase()
        .replace( /[^a-z0-9]+/g, '-' )
        .replace( /^-+|-+$/g, '' ) || 'preset';
    const taken = new Set( existing.map( ( p ) => p.id ) );
    if ( ! taken.has( slug ) ) return slug;
    let i = 2;
    while ( taken.has( `${ slug }-${ i }` ) ) i++;
    return `${ slug }-${ i }`;
}
