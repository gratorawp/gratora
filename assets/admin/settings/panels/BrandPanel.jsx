
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
import { presetsForPanel } from './brandPresets';
import { bestOn } from './contrast';

const PlusIcon  = () => <Icon name="plus"  size={ 16 } />;
const CloneIcon = () => <Icon name="copy"  size={ 14 } />;
const TrashIcon = () => <Icon name="trash" size={ 14 } />;

export default function BrandPanel( { s } ) {
    const saved   = Array.isArray( s.value( 'presets' ) ) ? s.value( 'presets' ) : [];
    const presets = presetsForPanel( saved );
    const defaultId  = String( s.value( 'default_id', '' ) || window.fundkit?.styling?.default_id || '' );

    const [ confirm, setConfirm ] = useState( null );
    const [ activeId, setActiveId ] = useState( () => {
        if ( defaultId && presets.find( ( p ) => p.id === defaultId ) ) return defaultId;
        return presets[ 0 ]?.id || '';
    } );

    const active = presets.find( ( p ) => p.id === activeId ) || presets[ 0 ] || null;

    // Reset to the selected preset’s shipped value from styling.builtins.
    const catalogueDefaults = window.fundkit?.styling?.defaults || {};
    const builtinTokens = ( id ) => {
        const list = Array.isArray( window.fundkit?.styling?.builtins ) ? window.fundkit.styling.builtins : [];
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
        const newName = `${ source.name } ${ __( '(copy)', 'fundraising-toolkit' ) }`;
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
        const newId = generateId( __( 'Custom', 'fundraising-toolkit' ), presets );
        const next  = [ ...presets, {
            id:      newId,
            name:    __( 'New preset', 'fundraising-toolkit' ),
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
            title:       __( 'Delete preset', 'fundraising-toolkit' ),
            message:     __( 'Delete this brand preset? This cannot be undone.', 'fundraising-toolkit' ),
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
        <div className="fundkit-panel">
            <div className="fundkit-brand-layout">
                <div className="fundkit-brand-layout__main">
                    <Card
                        title={ __( 'Brand presets', 'fundraising-toolkit' ) }
                        sub={ __( 'Named style presets. Campaigns and forms pick one as their look.', 'fundraising-toolkit' ) }
                        edited={ s.isDirty }
                    >
                        <div className="fundkit-preset-mgr">
                            <div className="fundkit-preset-mgr__list">
                                { presets.map( ( p ) => {
                                    const accent = p.tokens?.[ 'fundkit-accent' ]
                                        || builtinTokens( p.id )[ 'fundkit-accent' ]
                                        || catalogueDefaults[ 'fundkit-accent' ]
                                        || '#211d3f';
                                    const isActive  = p.id === active?.id;
                                    const isDefault = p.id === defaultId;
                                    return (
                                        <button
                                            key={ p.id }
                                            type="button"
                                            className={ `fundkit-preset-mgr__row${ isActive ? ' is-active' : '' }` }
                                            onClick={ () => setActiveId( p.id ) }
                                            aria-pressed={ isActive }
                                        >
                                            <span
                                                className="fundkit-preset-mgr__swatch"
                                                style={ { background: accent } }
                                                aria-hidden="true"
                                            />
                                            <span className="fundkit-preset-mgr__meta">
                                                <strong className="fundkit-preset-mgr__name">{ p.name }</strong>
                                                { isDefault && (
                                                    <span className="fundkit-preset-mgr__default">
                                                        <Icon name="check" size={ 12 } />
                                                        { __( 'Default', 'fundraising-toolkit' ) }
                                                    </span>
                                                ) }
                                            </span>
                                        </button>
                                    );
                                } ) }
                                <Button
                                    variant="secondary"
                                    className="fundkit-preset-mgr__add"
                                    onClick={ addPreset }
                                    icon={ PlusIcon }
                                >
                                    { __( 'Add preset', 'fundraising-toolkit' ) }
                                </Button>
                            </div>

                            <div className="fundkit-preset-mgr__editor">
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
                                        title={ __( 'No presets yet', 'fundraising-toolkit' ) }
                                        body={ __( 'Brand presets give every campaign a consistent look. Create one to get started.', 'fundraising-toolkit' ) }
                                        action={
                                            <Btn variant="secondary" onClick={ addPreset }>
                                                { __( 'Add preset', 'fundraising-toolkit' ) }
                                            </Btn>
                                        }
                                    />
                                ) }
                            </div>
                        </div>
                    </Card>
                </div>

                <aside className="fundkit-brand-layout__rail">
                    <Card title={ __( 'Live preview', 'fundraising-toolkit' ) }>
                        { active && (
                            <StylePreview
                                // Floor the preview with the built-in baseline so a
                                // reset token falls back to the preset's own value,
                                // not the catalogue default the resolver would use.
                                // On save the same fallback returns through
                                // StylePresets::all()'s built-in merge.
                                tokens={ { ...builtinTokens( active.id ), ...( active.tokens || {} ) } }
                                layer="brand"
                                styling={ window.fundkit?.styling || {} }
                            />
                        ) }
                    </Card>
                </aside>
            </div>
            <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
        </div>
    );
}

export function PresetEditor( { preset, resetDefaults, isDefault, onRename, onTokens, onMakeDefault, onClone, onDelete } ) {
    return (
        <div className="fundkit-preset-editor">
            <div className="fundkit-preset-editor__head">
                <input
                    type="text"
                    className="fundkit-input fundkit-preset-editor__name"
                    value={ preset.name }
                    onChange={ ( e ) => onRename( e.target.value ) }
                    placeholder={ __( 'Preset name', 'fundraising-toolkit' ) }
                />
                <div className="fundkit-preset-editor__actions">
                    { ! isDefault && (
                        <Button variant="secondary" size="small" onClick={ onMakeDefault }>
                            { __( 'Make default', 'fundraising-toolkit' ) }
                        </Button>
                    ) }
                    <Button variant="tertiary" size="small" icon={ CloneIcon } onClick={ onClone }>
                        { __( 'Clone', 'fundraising-toolkit' ) }
                    </Button>
                    { ! preset.builtin && (
                        <Button
                            variant="tertiary"
                            size="small"
                            icon={ TrashIcon }
                            isDestructive
                            onClick={ onDelete }
                        >
                            { __( 'Delete', 'fundraising-toolkit' ) }
                        </Button>
                    ) }
                </div>
            </div>

            { preset.description && (
                <p className="fundkit-preset-editor__desc">{ preset.description }</p>
            ) }

            <ContrastNotice tokens={ { ...( resetDefaults || {} ), ...( preset.tokens || {} ) } } />

            <TokenEditor
                value={ preset.tokens || {} }
                onChange={ onTokens }
                catalogue={ window.fundkit?.styling?.catalogue || {} }
                groups={ window.fundkit?.styling?.groups || {} }
                defaults={ resetDefaults || window.fundkit?.styling?.defaults || {} }
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
    [ 'fundkit-bg',      () => __( 'Background', 'fundraising-toolkit' ) ],
    [ 'fundkit-bg-soft', () => __( 'Soft background', 'fundraising-toolkit' ) ],
    [ 'fundkit-accent',  () => __( 'Accent', 'fundraising-toolkit' ) ],
];

function ContrastNotice( { tokens } ) {
    const failing = GROUNDS
        .map( ( [ key, label ] ) => ( { label: label(), value: tokens[ key ], best: bestOn( tokens[ key ] ) } ) )
        .filter( ( g ) => g.best !== null && g.best < 4.5 );

    if ( ! failing.length ) return null;

    return (
        <ul className="fundkit-preset-editor__contrast">
            { failing.map( ( g ) => (
                <li key={ g.label }>
                    { sprintf(
                        /* translators: 1: colour name, e.g. Background, 2: the hex the admin picked, 3: the contrast it reaches, e.g. 4.0 */
                        __( '%1$s (%2$s) reaches %3$s:1, under the 4.5:1 that text needs. Take it lighter or darker.', 'fundraising-toolkit' ),
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
