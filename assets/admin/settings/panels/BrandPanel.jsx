
import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { Palette } from 'lucide-react';

import Card from '../../_shared/components/Card';
import Btn from '../../_shared/components/Btn';
import ConfirmDialog from '../../_shared/components/ConfirmDialog';
import EmptyState from '../../_shared/components/EmptyState';
import Icon from '../../_shared/components/Icon';
import TokenEditor from '../../_shared/styling/TokenEditor';
import StylePreview from '../../_shared/styling/StylePreview';
import UnshownNotice from '../../_shared/styling/UnshownNotice';
import { presetsForPanel, presetLabel } from './brandPresets';
import { bestOn } from './contrast';

const PlusIcon  = () => <Icon name="plus"  size={ 16 } />;
const CloneIcon = () => <Icon name="copy"  size={ 14 } />;
const TrashIcon = () => <Icon name="trash" size={ 14 } />;

export default function BrandPanel( { s } ) {
    const saved   = Array.isArray( s.value( 'presets' ) ) ? s.value( 'presets' ) : [];
    const presets = presetsForPanel( saved );
    const defaultId  = String( s.value( 'default_id', '' ) || window.gratora?.styling?.default_id || '' );

    const [ confirm, setConfirm ] = useState( null );
    const [ activeId, setActiveId ] = useState( () => {
        if ( defaultId && presets.find( ( p ) => p.id === defaultId ) ) return defaultId;
        return presets[ 0 ]?.id || '';
    } );

    const active = presets.find( ( p ) => p.id === activeId ) || presets[ 0 ] || null;

    // Reset to the selected preset’s shipped value from styling.builtins.
    const catalogueDefaults = window.gratora?.styling?.defaults || {};
    const builtinTokens = ( id ) => {
        const list = Array.isArray( window.gratora?.styling?.builtins ) ? window.gratora.styling.builtins : [];
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
        const label   = presetLabel( source );
        const newId   = generateId( label, presets );
        const newName = `${ label } ${ __( '(copy)', 'gratora' ) }`;
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
        const newId = generateId( __( 'Custom', 'gratora' ), presets );
        const next  = [ ...presets, {
            id:      newId,
            name:    __( 'New preset', 'gratora' ),
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
            title:       __( 'Delete preset', 'gratora' ),
            message:     __( 'Delete this brand preset? This cannot be undone.', 'gratora' ),
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
        <div className="gratora-panel">
            <div className="gratora-brand-layout">
                <div className="gratora-brand-layout__main">
                    <Card
                        title={ __( 'Brand presets', 'gratora' ) }
                        sub={ __( 'Named style presets. Campaigns and forms pick one as their look.', 'gratora' ) }
                        edited={ s.isDirty }
                    >
                        <div className="gratora-preset-mgr">
                            <div className="gratora-preset-mgr__list">
                                { presets.map( ( p ) => {
                                    const accent = p.tokens?.[ 'gratora-accent' ]
                                        || builtinTokens( p.id )[ 'gratora-accent' ]
                                        || catalogueDefaults[ 'gratora-accent' ]
                                        || '#211d3f';
                                    const isActive  = p.id === active?.id;
                                    const isDefault = p.id === defaultId;
                                    return (
                                        <button
                                            key={ p.id }
                                            type="button"
                                            className={ `gratora-preset-mgr__row${ isActive ? ' is-active' : '' }` }
                                            onClick={ () => setActiveId( p.id ) }
                                            aria-pressed={ isActive }
                                        >
                                            <span
                                                className="gratora-preset-mgr__swatch"
                                                style={ { background: accent } }
                                                aria-hidden="true"
                                            />
                                            <span className="gratora-preset-mgr__meta">
                                                <strong className="gratora-preset-mgr__name">{ presetLabel( p ) }</strong>
                                                { isDefault && (
                                                    <span className="gratora-preset-mgr__default">
                                                        <Icon name="check" size={ 12 } />
                                                        { __( 'Default', 'gratora' ) }
                                                    </span>
                                                ) }
                                            </span>
                                        </button>
                                    );
                                } ) }
                                <Button
                                    variant="secondary"
                                    className="gratora-preset-mgr__add"
                                    onClick={ addPreset }
                                    icon={ PlusIcon }
                                >
                                    { __( 'Add preset', 'gratora' ) }
                                </Button>
                            </div>

                            <div className="gratora-preset-mgr__editor">
                                { active ? (
                                    <PresetEditor
                                        preset={ active }
                                        resetDefaults={ resetDefaults }
                                        base={ builtinTokens( active.id ) }
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
                                        title={ __( 'No presets yet', 'gratora' ) }
                                        body={ __( 'Brand presets give every campaign a consistent look. Create one to get started.', 'gratora' ) }
                                        action={
                                            <Btn variant="secondary" onClick={ addPreset }>
                                                { __( 'Add preset', 'gratora' ) }
                                            </Btn>
                                        }
                                    />
                                ) }
                            </div>
                        </div>
                    </Card>
                </div>

                <aside className="gratora-brand-layout__rail">
                    <Card title={ __( 'Live preview', 'gratora' ) }>
                        { active && (
                            <StylePreview
                                // Floor the preview with the built-in baseline so a
                                // reset token falls back to the preset's own value,
                                // not the catalogue default the resolver would use.
                                // On save the same fallback returns through
                                // StylePresets::all()'s built-in merge.
                                tokens={ { ...builtinTokens( active.id ), ...( active.tokens || {} ) } }
                                presetId={ active.id }
                                layer="brand"
                                styling={ window.gratora?.styling || {} }
                            />
                        ) }
                    </Card>
                </aside>
            </div>
            <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
        </div>
    );
}

export function PresetEditor( { preset, resetDefaults, base, isDefault, onRename, onTokens, onMakeDefault, onClone, onDelete } ) {
    return (
        <div className="gratora-preset-editor">
            <div className="gratora-preset-editor__head">
                <input
                    type="text"
                    className="gratora-input gratora-preset-editor__name"
                    value={ preset.name }
                    onChange={ ( e ) => onRename( e.target.value ) }
                    placeholder={ __( 'Preset name', 'gratora' ) }
                />
                <div className="gratora-preset-editor__actions">
                    { ! isDefault && (
                        <Button variant="secondary" size="small" onClick={ onMakeDefault }>
                            { __( 'Make default', 'gratora' ) }
                        </Button>
                    ) }
                    <Button variant="tertiary" size="small" icon={ CloneIcon } onClick={ onClone }>
                        { __( 'Clone', 'gratora' ) }
                    </Button>
                    { ! preset.builtin && (
                        <Button
                            variant="tertiary"
                            size="small"
                            icon={ TrashIcon }
                            isDestructive
                            onClick={ onDelete }
                        >
                            { __( 'Delete', 'gratora' ) }
                        </Button>
                    ) }
                </div>
            </div>

            { preset.description && (
                <p className="gratora-preset-editor__desc">{ preset.description }</p>
            ) }

            <ContrastNotice tokens={ { ...( resetDefaults || {} ), ...( preset.tokens || {} ) } } />

            <UnshownNotice
                tokens={ { ...( resetDefaults || {} ), ...( preset.tokens || {} ) } }
                catalogue={ window.gratora?.styling?.catalogue || {} }
            />

            <TokenEditor
                value={ preset.tokens || {} }
                base={ base || {} }
                onChange={ onTokens }
                catalogue={ window.gratora?.styling?.catalogue || {} }
                groups={ window.gratora?.styling?.groups || {} }
                defaults={ resetDefaults || window.gratora?.styling?.defaults || {} }
            />
        </div>
    );
}

/**
 * A ground between light and dark carries no body text whichever ink is drawn
 * on it, and a colour picker cannot show that. The text colours follow the
 * ground on their own, so this is the one choice the org has to make itself.
 */
const GROUNDS = [
    [ 'gratora-bg',      () => __( 'Background', 'gratora' ) ],
    [ 'gratora-bg-soft', () => __( 'Soft background', 'gratora' ) ],
    [ 'gratora-field-bg', () => __( 'Field background', 'gratora' ) ],
    [ 'gratora-accent',  () => __( 'Accent', 'gratora' ) ],
];

function ContrastNotice( { tokens } ) {
    const failing = GROUNDS
        .map( ( [ key, label ] ) => ( { label: label(), value: tokens[ key ], best: bestOn( tokens[ key ] ) } ) )
        .filter( ( g ) => g.best !== null && g.best < 4.5 );

    if ( ! failing.length ) return null;

    return (
        <ul className="gratora-preset-editor__contrast">
            { failing.map( ( g ) => (
                <li key={ g.label }>
                    { sprintf(
                        /* translators: 1: colour name, e.g. Background, 2: the hex the admin picked, 3: the contrast it reaches, e.g. 4.0 */
                        __( '%1$s (%2$s) reaches %3$s:1, under the 4.5:1 that text needs. Take it lighter or darker.', 'gratora' ),
                        g.label,
                        String( g.value ).toUpperCase(),
                        g.best.toFixed( 1 )
                    ) }
                </li>
            ) ) }
        </ul>
    );
}

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
