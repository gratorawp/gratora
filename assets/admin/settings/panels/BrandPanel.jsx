
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
    // Seed from window.giveflow.styling.presets when the option hasn't been saved yet.
    const saved      = Array.isArray( s.value( 'presets' ) ) ? s.value( 'presets' ) : [];
    const globalList = Array.isArray( window.giveflow?.styling?.presets ) ? window.giveflow.styling.presets : [];
    const presets    = saved.length > 0 ? saved : globalList;
    const defaultId  = String( s.value( 'default_id', '' ) || window.giveflow?.styling?.default_id || '' );

    const [ confirm, setConfirm ] = useState( null );
    const [ activeId, setActiveId ] = useState( () => {
        if ( defaultId && presets.find( ( p ) => p.id === defaultId ) ) return defaultId;
        return presets[ 0 ]?.id || '';
    } );

    const active = presets.find( ( p ) => p.id === activeId ) || presets[ 0 ] || null;

    // What Reset restores a token to. For a built-in, that is the preset's own
    // shipped value (Bold's navy, the Site theme's theme.json accent), which
    // lives in styling.builtins before any user edit was merged in. Falling
    // straight through to the catalogue default, as this used to, reset every
    // preset's colours to the same green.
    const catalogueDefaults = window.giveflow?.styling?.defaults || {};
    const builtinTokens = ( id ) => {
        const list = Array.isArray( window.giveflow?.styling?.builtins ) ? window.giveflow.styling.builtins : [];
        return list.find( ( b ) => b.id === id )?.tokens || {};
    };
    const resetDefaults = active
        ? { ...catalogueDefaults, ...builtinTokens( active.id ) }
        : catalogueDefaults;

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
        const newName = `${ source.name } ${ __( '(copy)', 'giveflow-fundraising-campaigns' ) }`;
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
        const newId = generateId( __( 'Custom', 'giveflow-fundraising-campaigns' ), presets );
        const next  = [ ...presets, {
            id:      newId,
            name:    __( 'New preset', 'giveflow-fundraising-campaigns' ),
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
            title:       __( 'Delete preset', 'giveflow-fundraising-campaigns' ),
            message:     __( 'Delete this brand preset? This cannot be undone.', 'giveflow-fundraising-campaigns' ),
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
        <div className="giveflow-panel">
            <div className="giveflow-brand-layout">
                <div className="giveflow-brand-layout__main">
                    <Card
                        title={ __( 'Brand presets', 'giveflow-fundraising-campaigns' ) }
                        sub={ __( 'Named style presets. Campaigns and forms pick one as their look.', 'giveflow-fundraising-campaigns' ) }
                        edited={ s.isDirty }
                    >
                        <div className="giveflow-preset-mgr">
                            <div className="giveflow-preset-mgr__list">
                                { presets.map( ( p ) => {
                                    const accent = p.tokens?.[ 'giveflow-accent' ]
                                        || builtinTokens( p.id )[ 'giveflow-accent' ]
                                        || catalogueDefaults[ 'giveflow-accent' ]
                                        || '#211d3f';
                                    const isActive  = p.id === active?.id;
                                    const isDefault = p.id === defaultId;
                                    return (
                                        <button
                                            key={ p.id }
                                            type="button"
                                            className={ `giveflow-preset-mgr__row${ isActive ? ' is-active' : '' }` }
                                            onClick={ () => setActiveId( p.id ) }
                                            aria-pressed={ isActive }
                                        >
                                            <span
                                                className="giveflow-preset-mgr__swatch"
                                                style={ { background: accent } }
                                                aria-hidden="true"
                                            />
                                            <span className="giveflow-preset-mgr__meta">
                                                <strong className="giveflow-preset-mgr__name">{ p.name }</strong>
                                                { isDefault && (
                                                    <span className="giveflow-preset-mgr__default">
                                                        <Icon name="check" size={ 12 } />
                                                        { __( 'Default', 'giveflow-fundraising-campaigns' ) }
                                                    </span>
                                                ) }
                                            </span>
                                        </button>
                                    );
                                } ) }
                                <Button
                                    variant="secondary"
                                    className="giveflow-preset-mgr__add"
                                    onClick={ addPreset }
                                    icon={ PlusIcon }
                                >
                                    { __( 'Add preset', 'giveflow-fundraising-campaigns' ) }
                                </Button>
                            </div>

                            <div className="giveflow-preset-mgr__editor">
                                { active ? (
                                    <PresetEditor
                                        preset={ active }
                                        resetDefaults={ resetDefaults }
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
                                        title={ __( 'No presets yet', 'giveflow-fundraising-campaigns' ) }
                                        body={ __( 'Brand presets give every campaign a consistent look. Create one to get started.', 'giveflow-fundraising-campaigns' ) }
                                        action={
                                            <Btn variant="secondary" onClick={ addPreset }>
                                                { __( 'Add preset', 'giveflow-fundraising-campaigns' ) }
                                            </Btn>
                                        }
                                    />
                                ) }
                            </div>
                        </div>
                    </Card>
                </div>

                <aside className="giveflow-brand-layout__rail">
                    <Card title={ __( 'Live preview', 'giveflow-fundraising-campaigns' ) }>
                        { active && (
                            <StylePreview
                                // Floor the preview with the built-in baseline so a
                                // reset token falls back to the preset's own value,
                                // not the catalogue default the resolver would use.
                                // On save the same fallback returns through
                                // StylePresets::all()'s built-in merge.
                                tokens={ { ...builtinTokens( active.id ), ...( active.tokens || {} ) } }
                                layer="brand"
                                styling={ window.giveflow?.styling || {} }
                            />
                        ) }
                    </Card>
                </aside>
            </div>
            <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
        </div>
    );
}

function PresetEditor( { preset, resetDefaults, isDefault, onRename, onTokens, onMakeDefault, onClone, onDelete } ) {
    return (
        <div className="giveflow-preset-editor">
            <div className="giveflow-preset-editor__head">
                <input
                    type="text"
                    className="giveflow-input giveflow-preset-editor__name"
                    value={ preset.name }
                    onChange={ ( e ) => onRename( e.target.value ) }
                    placeholder={ __( 'Preset name', 'giveflow-fundraising-campaigns' ) }
                />
                <div className="giveflow-preset-editor__actions">
                    { ! isDefault && (
                        <Button variant="secondary" size="small" onClick={ onMakeDefault }>
                            { __( 'Make default', 'giveflow-fundraising-campaigns' ) }
                        </Button>
                    ) }
                    <Button variant="tertiary" size="small" icon={ CloneIcon } onClick={ onClone }>
                        { __( 'Clone', 'giveflow-fundraising-campaigns' ) }
                    </Button>
                    { ! preset.builtin && (
                        <Button
                            variant="tertiary"
                            size="small"
                            icon={ TrashIcon }
                            isDestructive
                            onClick={ onDelete }
                        >
                            { __( 'Delete', 'giveflow-fundraising-campaigns' ) }
                        </Button>
                    ) }
                </div>
            </div>

            { preset.description && (
                <p className="giveflow-preset-editor__desc">{ preset.description }</p>
            ) }

            <TokenEditor
                value={ preset.tokens || {} }
                onChange={ onTokens }
                catalogue={ window.giveflow?.styling?.catalogue || {} }
                groups={ window.giveflow?.styling?.groups || {} }
                defaults={ resetDefaults || window.giveflow?.styling?.defaults || {} }
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
