/**
 * Form editor on @wordpress/block-editor directly (no isolated-block-editor): we own
 * the chrome, and block data lives in a useReducer history so undo/redo stay local.
 */

import { useEffect, useCallback, useMemo, useReducer, useRef, useState } from '@wordpress/element';
import {
    BaseControl,
    Button,
    Modal,
    Popover,
    SelectControl,
    SlotFillProvider,
    Spinner,
    TextControl,
    TextareaControl,
} from '@wordpress/components';
import Notice from '../_shared/components/Notice';
import { notify } from '../_shared/notify';
import {
    BlockEditorProvider,
    BlockInspector,
    BlockList,
    BlockTools,
    Inserter,
    ObserveTyping,
    WritingFlow,
    __experimentalLibrary as BlockLibrary,
    __experimentalListView as BlockListView,
} from '@wordpress/block-editor';
import { InterfaceSkeleton } from '@wordpress/interface';
import { ShortcutProvider } from '@wordpress/keyboard-shortcuts';
import { createBlock, parse, serialize } from '@wordpress/blocks';
import { useRegistry, useSelect } from '@wordpress/data';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import { useDonoRecord } from '../_shared/useDonoRecord';
import Btn from '../_shared/components/Btn';
import LocalIcon from '../_shared/components/Icon';
import Slider    from '../_shared/components/Slider';
import Segmented from '../_shared/components/Segmented';
import AmountInput from '../_shared/components/AmountInput';
import FormTemplatePicker from '../_shared/components/FormTemplatePicker';
import { STATUS_LABEL, campaignHref } from './format';
import { defaultCurrency } from '../_shared/format';
import blockRegistry, { runBlockRegistration } from './registry';
import './blocks';
import './editor.scss';

const PlusIcon       = () => <LocalIcon name="plus"          size={ 18 } />;
const CloseIcon      = () => <LocalIcon name="close"         size={ 18 } />;
const ListViewIcon   = () => <LocalIcon name="list-view"     size={ 18 } />;
const UndoIcon       = () => <LocalIcon name="undo"          size={ 18 } />;
const RedoIcon       = () => <LocalIcon name="redo"          size={ 18 } />;
const PanelRightIcon = () => <LocalIcon name="panel-right"   size={ 18 } />;
const DesktopIcon    = () => <LocalIcon name="desktop"       size={ 18 } />;
const TabletIcon     = () => <LocalIcon name="tablet"        size={ 18 } />;
const MobileIcon     = () => <LocalIcon name="mobile"        size={ 18 } />;

function defaultFormSettings() {
    return {
        layout:    'inline',
        style:     { preset_id: '' },
        container: { width: 540, style: 'plain' },
        gateways:  { allowed: [] },
        goal:      { type: 'none', amount_cents: 0, count: 0 },
        thank_you_message: '',
        redirect_url: '',
        test_mode: false,
    };
}

// Multi-page navigation is driven by the Steps block inside the form, not a
// form-level toggle. Layout is just the embed style.
const LAYOUT_OPTIONS = [
    { value: 'inline', label: __( 'Inline (in-page)', 'dono' ) },
    { value: 'modal',  label: __( 'Modal (button opens form)', 'dono' ) },
];

function mergeFormSettings( stored ) {
    const def = defaultFormSettings();
    if ( ! stored || typeof stored !== 'object' ) return def;
    return {
        ...def,
        ...stored,
        style:     { ...def.style,     ...( stored.style     || {} ) },
        container: { ...def.container, ...( stored.container || {} ) },
        gateways:  { ...def.gateways,  ...( stored.gateways  || {} ) },
        goal:      { ...def.goal,      ...( stored.goal      || {} ) },
    };
}

// Walk the live block tree for a block by name (handles nested inner blocks).
function blocksInclude( list, name ) {
    for ( const b of Array.isArray( list ) ? list : [] ) {
        if ( b?.name === name ) return true;
        if ( Array.isArray( b?.innerBlocks ) && b.innerBlocks.length && blocksInclude( b.innerBlocks, name ) ) return true;
    }
    return false;
}

let blocksReady = false;
function ensureBlocksRegistered() {
    if ( blocksReady ) return;
    runBlockRegistration();
    blocksReady = true;
}

// Stub template list. Surfaced via window.dono.forms.templates later; for now
// inline a couple of canonical shapes so the picker has something to pick.
// Block history reducer (undo/redo).
const initialHistory = { past: [], present: [], future: [] };

function historyReducer( state, action ) {
    switch ( action.type ) {
        case 'RESET':
            return { past: [], present: action.blocks, future: [] };
        case 'INPUT':
            // Transient: replaces the present without touching past/future.
            return { ...state, present: action.blocks };
        case 'CHANGE':
            // Commit: push current onto past, clear future.
            if ( state.present === action.blocks ) return state;
            return {
                past:    [ ...state.past, state.present ],
                present: action.blocks,
                future:  [],
            };
        case 'UNDO':
            if ( state.past.length === 0 ) return state;
            return {
                past:    state.past.slice( 0, -1 ),
                present: state.past[ state.past.length - 1 ],
                future:  [ state.present, ...state.future ],
            };
        case 'REDO':
            if ( state.future.length === 0 ) return state;
            return {
                past:    [ ...state.past, state.present ],
                present: state.future[ 0 ],
                future:  state.future.slice( 1 ),
            };
        default:
            return state;
    }
}

export default function Editor( { formId } ) {
    const c = useDonoRecord( 'form', formId );

    const [ campaigns, setCampaigns ] = useState( [] );
    const [ gateways, setGateways ]   = useState( [] );
    const [ funds, setFunds ]         = useState( [] );
    const [ error, setError ]         = useState( null );

    const [ history, dispatchHistory ] = useReducer( historyReducer, initialHistory );
    const blocks = history.present;
    const [ lastSavedSerialized, setLastSavedSerialized ] = useState( '' );
    const seededRef = useRef( false );
    const [ templatePickerOpen, setTemplatePickerOpen ] = useState( false );
    // Template awaiting replace confirmation (picker chose it over existing content).
    const [ pendingTemplate, setPendingTemplate ] = useState( null );
    // Which header action is in flight, so only its button shows the spinner.
    const [ savingAction, setSavingAction ] = useState( null );

    const [ view, setView ] = useState( 'develop' );
    const [ device, setDevice ] = useState( 'desktop' );
    const [ previewHtml, setPreviewHtml ] = useState( '' );
    const [ previewLoading, setPreviewLoading ] = useState( false );

    const [ sidebarOpen, setSidebarOpen ] = useState( true );
    const [ secondaryView, setSecondaryView ] = useState( 'inserter' );

    const toggleSecondaryView = useCallback( ( v ) => {
        setSecondaryView( ( cur ) => ( cur === v ? null : v ) );
    }, [] );

    const [ selectedBlockId, setSelectedBlockId ] = useState( null );

    useEffect( () => {
        if ( selectedBlockId ) {
            setSidebarOpen( true );
        }
    }, [ selectedBlockId ] );

    // Authoring secondary views (inserter / list view) only make sense in
    // Develop. Close them when leaving and restore the same state - open
    // panel or deliberately closed - on the way back.
    const lastSecondaryView = useRef( secondaryView );
    useEffect( () => {
        if ( view !== 'develop' ) {
            setSecondaryView( ( cur ) => {
                lastSecondaryView.current = cur;
                return null;
            } );
        } else {
            setSecondaryView( lastSecondaryView.current );
        }
    }, [ view ] );

    ensureBlocksRegistered();

    // Campaigns + gateways are dropdown sources, not entities; fetch once.
    // Surface a per-source failure instead of leaving the select silently empty.
    useEffect( () => {
        apiFetch( { path: '/dono/v1/admin/forms/campaigns' } )
            .then( setCampaigns )
            .catch( ( err ) => setError( err?.message || __( 'Could not load campaigns.', 'dono' ) ) );
        apiFetch( { path: '/dono/v1/admin/forms/gateways' } )
            .then( setGateways )
            .catch( ( err ) => setError( err?.message || __( 'Could not load payment gateways.', 'dono' ) ) );
        apiFetch( { path: '/dono/v1/admin/forms/funds' } )
            .then( setFunds )
            .catch( ( err ) => setError( err?.message || __( 'Could not load funds.', 'dono' ) ) );
    }, [] );

    // Expose form context to block edit components (Goal needs campaign progress).
    useEffect( () => {
        window.donoFormEditor = {
            formId,
            formCampaignId: Number( c.value( 'campaign_id', 0 ) ) || 0,
            formGoal: mergeFormSettings( c.record.settings ).goal,
            campaigns,
        };
        return () => { delete window.donoFormEditor; };
    }, [ formId, c.record.campaign_id, c.record.settings, campaigns ] );

    // Seed the local block-history reducer once when the entity first resolves.
    // A form created by onboarding ships with empty `blocks`; surface a
    // template picker on first open so the user picks a starting shape.
    useEffect( () => {
        if ( seededRef.current || ! c.savedRecord ) return;
        seededRef.current = true;
        const saved  = c.savedRecord.blocks || '';
        const parsed = saved ? parse( saved ) : [];
        dispatchHistory( { type: 'RESET', blocks: parsed } );
        // Baseline against the re-serialized form (what the dirty check compares),
        // not the raw stored markup; serialize(parse(x)) !== x for non-canonical
        // markup (default attrs dropped), which would flag a pristine form dirty.
        setLastSavedSerialized( serialize( parsed ) );
        if ( parsed.length === 0 ) {
            setTemplatePickerOpen( true );
        }
    }, [ c.savedRecord ] );

    const performApplyTemplate = useCallback( ( template, hasContent ) => {
        const blocksMarkup = ( template?.blocks ?? '' ).trim();
        const parsed = blocksMarkup ? parse( blocksMarkup ) : [];
        // CHANGE keeps it in history (undoable) when replacing existing content;
        // RESET sets a fresh baseline for the first-open empty-form seeding.
        dispatchHistory( { type: hasContent ? 'CHANGE' : 'RESET', blocks: parsed } );
        // Templates ship their own form settings (goal, recurring defaults); apply
        // them alongside the blocks so the picked shape is complete, not blocks-only.
        if ( template?.settings && typeof template.settings === 'object' ) {
            c.edit( { settings: mergeFormSettings( template.settings ) } );
        }
        setPendingTemplate( null );
        setTemplatePickerOpen( false );
    }, [ c ] );

    const applyTemplate = useCallback( ( template ) => {
        if ( history.present.length > 0 ) {
            // Replacing real content needs an explicit confirm; the dialog's
            // primary action runs performApplyTemplate with hasContent true.
            setPendingTemplate( template );
            return;
        }
        performApplyTemplate( template, false );
    }, [ history.present.length, performApplyTemplate ] );

    const onBlocksInput  = useCallback( ( next ) => dispatchHistory( { type: 'INPUT',  blocks: next } ), [] );
    const onBlocksChange = useCallback( ( next ) => dispatchHistory( { type: 'CHANGE', blocks: next } ), [] );
    const undo = useCallback( () => dispatchHistory( { type: 'UNDO' } ), [] );
    const redo = useCallback( () => dispatchHistory( { type: 'REDO' } ), [] );

    const fetchPreview = useCallback( async () => {
        setPreviewLoading( true );
        try {
            const res = await apiFetch( {
                path:   '/dono/v1/admin/forms/preview',
                method: 'POST',
                data:   {
                    blocks:      serialize( blocks ),
                    settings:    mergeFormSettings( c.record.settings ),
                    campaign_id: Number( c.value( 'campaign_id', 0 ) ) || null,
                },
            } );
            setPreviewHtml( res.html || '' );
        } catch ( err ) {
            setError( err?.message || __( 'Preview failed.', 'dono' ) );
        } finally {
            setPreviewLoading( false );
        }
    }, [ blocks, c.record.settings, c.record.campaign_id ] );

    useEffect( () => {
        if ( view === 'preview' ) fetchPreview();
    }, [ view, fetchPreview ] );

    const persist = useCallback( async ( extra = {} ) => {
        setError( null );
        const serialized = serialize( blocks );
        try {
            // Persist any pending field edits (title, status, campaign, ...)
            // plus the current block content and a complete settings object.
            // Sent as one explicit PUT so it can't race the edits store.
            await c.saveEntity( {
                ...c.edits,
                blocks:   serialized,
                settings: mergeFormSettings( c.record.settings ),
                ...extra,
            } );
            setLastSavedSerialized( serialized );
            return true;
        } catch ( err ) {
            setError( err?.message || __( 'Save failed.', 'dono' ) );
            return false;
        }
    }, [ c, blocks ] );

    const onSave = useCallback( async () => {
        setSavingAction( 'save' );
        const ok = await persist();
        setSavingAction( null );
        if ( ok ) {
            notify.success(
                c.record.status === 'published'
                    ? __( 'Form saved.', 'dono' )
                    : __( 'Draft saved.', 'dono' )
            );
        }
    }, [ persist, c.record.status ] );

    const editorSettings = useMemo( () => ( {
        allowedBlockTypes: blockRegistry.allowed,
        hasFixedToolbar:   false,
        focusMode:         false,
        // Mirror theme.json's `settings` shape so block-supports panels
        // (color / border / spacing / shadow / typography) actually render
        // in our isolated block-editor host. Without this the supports are
        // declared on each block but the inspector ignores them.
        __experimentalFeatures: {
            appearanceTools: true,
            useRootPaddingAwareAlignments: false,
            color: {
                background:     true,
                text:           true,
                link:           true,
                button:         true,
                heading:        true,
                // `custom` enables the free-form picker. Without it, only
                // palette swatches would render (and our palette is empty
                // until we wire theme tokens in, so the panel would be blank).
                custom:         true,
                customGradient: true,
                customDuotone:  true,
                defaultPalette: true,
            },
            border: {
                color:  true,
                radius: true,
                style:  true,
                width:  true,
            },
            spacing: {
                padding:  true,
                margin:   true,
                blockGap: true,
                units:    [ 'px', 'em', 'rem', '%', 'vh', 'vw' ],
                customSpacingSize: true,
            },
            shadow: {
                defaultPresets: true,
                // Lets authors write a custom box-shadow CSS string.
                custom:         true,
            },
            typography: {
                fontSize:           true,
                lineHeight:         true,
                fontStyle:          true,
                fontWeight:         true,
                letterSpacing:      true,
                textTransform:      true,
                textDecoration:     true,
                customFontSize:     true,
                dropCap:            false,
            },
            dimensions: {
                minHeight: true,
            },
        },
    } ), [] );

    const missingRequired = useMemo( () => {
        const required = window.dono?.forms?.required_blocks || [];
        if ( ! required.length ) return [];
        const present = new Set();
        const walk = ( list ) => {
            for ( const b of list ) {
                if ( b?.name ) present.add( b.name );
                if ( Array.isArray( b?.innerBlocks ) && b.innerBlocks.length ) walk( b.innerBlocks );
            }
        };
        walk( blocks );
        return required.filter( ( r ) => ! present.has( r.block ) );
    }, [ blocks ] );

    const onPublish = useCallback( async () => {
        if ( missingRequired.length > 0 ) return;
        setSavingAction( 'publish' );
        const ok = await persist( { status: 'published' } );
        setSavingAction( null );
        if ( ok ) notify.success( __( 'Form published.', 'dono' ) );
    }, [ persist, missingRequired ] );

    const onUnpublish = useCallback( async () => {
        setSavingAction( 'unpublish' );
        const ok = await persist( { status: 'draft' } );
        setSavingAction( null );
        if ( ok ) notify.success( __( 'Form moved to draft.', 'dono' ) );
    }, [ persist ] );

    // Warn before leaving with unsaved block or field edits, mirroring the
    // campaign editor; the back link is a real navigation so this catches it.
    const dirtyForUnload = c.isDirty || serialize( blocks ) !== lastSavedSerialized;
    useEffect( () => {
        if ( ! dirtyForUnload ) return undefined;
        const handler = ( e ) => { e.preventDefault(); e.returnValue = ''; return ''; };
        window.addEventListener( 'beforeunload', handler );
        return () => window.removeEventListener( 'beforeunload', handler );
    }, [ dirtyForUnload ] );

    // Editor-level shortcuts. Cmd/Ctrl+S saves from anywhere; undo/redo only
    // fire outside text fields so native text undo keeps working inside blocks.
    useEffect( () => {
        const onKey = ( e ) => {
            if ( ! ( e.metaKey || e.ctrlKey ) ) return;
            const key = ( e.key || '' ).toLowerCase();
            if ( key === 's' ) {
                e.preventDefault();
                if ( dirtyForUnload && ! c.isSaving ) onSave();
                return;
            }
            const t = e.target;
            const editable = t && ( /^(input|textarea|select)$/i.test( t.tagName ) || t.isContentEditable );
            if ( editable ) return;
            if ( key === 'z' && ! e.shiftKey ) {
                e.preventDefault();
                if ( history.past.length ) undo();
            } else if ( ( key === 'z' && e.shiftKey ) || key === 'y' ) {
                e.preventDefault();
                if ( history.future.length ) redo();
            }
        };
        window.addEventListener( 'keydown', onKey );
        return () => window.removeEventListener( 'keydown', onKey );
    }, [ dirtyForUnload, c.isSaving, onSave, undo, redo, history.past.length, history.future.length ] );

    if ( c.isLoading || ( ! c.savedRecord && ! c.notFound ) ) {
        return <div className="dono-form-editor__loading"><Spinner /></div>;
    }
    if ( c.notFound ) {
        return (
            <Notice status="error" isDismissible={ false }>
                { __( 'Form not found.', 'dono' ) }
            </Notice>
        );
    }

    const blocksDirty = serialize( blocks ) !== lastSavedSerialized;
    const isDirty     = c.isDirty || blocksDirty;

    const header = (
        <EditorHeader
            backHref={ campaignHref( c.value( 'campaign_id', 0 ) ) }
            title={ c.value( 'title' ) }
            onTitleChange={ ( v ) => c.edit( { title: v } ) }
            view={ view }
            onViewChange={ setView }
            canUndo={ history.past.length > 0 }
            canRedo={ history.future.length > 0 }
            onUndo={ undo }
            onRedo={ redo }
            onOpenTemplates={ () => setTemplatePickerOpen( true ) }
            saving={ c.isSaving }
            savingAction={ savingAction }
            isDirty={ isDirty }
            onSave={ onSave }
            status={ c.value( 'status', 'draft' ) }
            missingRequiredLabels={ missingRequired.map( ( r ) => r.label ) }
            onPublish={ onPublish }
            onUnpublish={ onUnpublish }
            sidebarOpen={ sidebarOpen }
            onToggleSidebar={ () => setSidebarOpen( ( v ) => ! v ) }
            secondaryView={ secondaryView }
            onToggleSecondaryView={ toggleSecondaryView }
        />
    );

    const notices = ( error || missingRequired.length > 0 ) && (
        <div className="dono-form-editor__notices">
            { missingRequired.length > 0 && (
                <Notice status="warning" isDismissible={ false }>
                    { sprintf(
                        /* translators: %s: comma-separated list of missing block labels (Name, Email). */
                        __( 'Add these blocks before publishing: %s.', 'dono' ),
                        missingRequired.map( ( r ) => r.label ).join( ', ' )
                    ) }
                </Notice>
            ) }
            { error && (
                <Notice status="error" onRemove={ () => setError( null ) }>{ error }</Notice>
            ) }
        </div>
    );

    // Develop: Block inspector. Preview: pre-launch readiness checklist.
    // Settings has its own centred panel and no right sidebar.
    let sidebar = null;
    if ( sidebarOpen && view === 'develop' ) {
        sidebar = <FormSidebar hasSelection={ !! selectedBlockId } />;
    } else if ( sidebarOpen && view === 'preview' ) {
        sidebar = (
            <PreviewSidebar
                formId={ formId }
                blocks={ blocks }
                missingRequired={ missingRequired }
            />
        );
    }

    const themeVars = ( () => {
        const merged    = mergeFormSettings( c.record.settings );
        const presets   = Array.isArray( window.dono?.styling?.presets ) ? window.dono.styling.presets : [];
        const defaults  = window.dono?.styling?.defaults || {};
        const defaultId = String( window.dono?.styling?.default_id || '' );

        // Cascade mirrors CampaignStyleResolver: form preset, else campaign
        // preset, else org default. Campaign inline overrides apply only when
        // the form has not picked its own preset; form inline overrides always
        // layer on top (form wins).
        const formPresetId     = String( merged.style?.preset_id || '' );
        const formInlineTokens = merged.style?.tokens && typeof merged.style.tokens === 'object'
            ? merged.style.tokens : {};

        const currentCampaignId  = Number( c.value( 'campaign_id', 0 ) ) || 0;
        const currentCampaign    = currentCampaignId
            ? campaigns.find( ( cmp ) => Number( cmp.id ) === currentCampaignId )
            : null;
        const campaignStyle      = currentCampaign?.style && typeof currentCampaign.style === 'object'
            ? currentCampaign.style : {};
        const campaignPresetId   = String( campaignStyle.preset_id || '' );
        const campaignInlineTokens = campaignStyle.tokens && typeof campaignStyle.tokens === 'object'
            ? campaignStyle.tokens : {};

        const chosenPresetId = formPresetId || campaignPresetId || defaultId;
        const chosenPreset   = presets.find( ( p ) => p.id === chosenPresetId );

        const tokens = {
            ...defaults,
            ...( chosenPreset?.tokens || {} ),
            ...( formPresetId ? {} : campaignInlineTokens ),
            ...formInlineTokens,
        };

        // Mirror CampaignStyleResolver: when accent-soft is just the catalogue
        // default and nothing deliberately paired one with the accent, drop it
        // so the stylesheet's color-mix derives it from --dono-accent (matches
        // the published form). Anything that explicitly set it is kept.
        const explicitSoft =
            ( chosenPreset?.tokens && 'dono-accent-soft' in chosenPreset.tokens ) ||
            ( ! formPresetId && 'dono-accent-soft' in campaignInlineTokens ) ||
            ( 'dono-accent-soft' in formInlineTokens );
        if ( ! explicitSoft && tokens[ 'dono-accent-soft' ] === defaults[ 'dono-accent-soft' ] ) {
            delete tokens[ 'dono-accent-soft' ];
        }

        const sx = {};
        for ( const k in tokens ) {
            if ( typeof tokens[ k ] === 'string' && tokens[ k ] !== '' ) {
                sx[ `--${ k }` ] = tokens[ k ];
            }
        }

        // Drives the Build canvas sheet width so it matches the published
        // form's container width (clamp mirrors the runtime in PHP).
        const cw = Number( merged.container?.width );
        if ( cw >= 320 && cw <= 1600 ) {
            sx[ '--dono-editor-sheet-width' ] = `${ cw }px`;
        }

        return sx;
    } )();

    return (
        <div className="dono-form-editor" style={ themeVars }>
            <ShortcutProvider>
                <SlotFillProvider>
                    <BlockEditorProvider
                        value={ blocks }
                        onInput={ onBlocksInput }
                        onChange={ onBlocksChange }
                        settings={ editorSettings }
                    >
                        <BlockSelectionSync onChange={ setSelectedBlockId } />
                        <DeselectOnOutsideClick />
                        <AssistantBridge />
                        <InterfaceSkeleton
                            header={ header }
                            notices={ notices }
                            content={
                                view === 'preview' ? (
                                    <PreviewPane
                                        loading={ previewLoading }
                                        html={ previewHtml }
                                        device={ device }
                                        onDeviceChange={ setDevice }
                                    />
                                ) : view === 'settings' ? (
                                    <SettingsView
                                        c={ c }
                                        campaigns={ campaigns }
                                        gateways={ gateways }
                                        funds={ funds }
                                        blocks={ blocks }
                                    />
                                ) : (
                                    <div className="dono-form-editor__canvas">
                                        <div className="dono-form-editor__sheet">
                                            <BlockTools>
                                                <WritingFlow>
                                                    <ObserveTyping>
                                                        <BlockList />
                                                    </ObserveTyping>
                                                </WritingFlow>
                                            </BlockTools>
                                            { blocks.length === 0 && (
                                                <CanvasEmpty />
                                            ) }
                                        </div>
                                    </div>
                                )
                            }
                            sidebar={ sidebar }
                            secondarySidebar={
                                secondaryView === 'inserter' ? (
                                    <div className="dono-form-editor__secondary dono-form-editor__secondary--inserter">
                                        <BlockLibrary
                                            showInserterHelpPanel={ false }
                                            rootClientId=""
                                            __experimentalInitialTab="blocks"
                                        />
                                    </div>
                                ) : secondaryView === 'listview' ? (
                                    <div className="dono-form-editor__secondary dono-form-editor__secondary--listview">
                                        <div className="dono-form-editor__secondary-title">
                                            { __( 'Form structure', 'dono' ) }
                                        </div>
                                        <BlockListView />
                                    </div>
                                ) : null
                            }
                        />
                        <Popover.Slot />
                    </BlockEditorProvider>
                </SlotFillProvider>
            </ShortcutProvider>

            { templatePickerOpen && (
                <FormTemplatePicker
                    intro={ __( "We didn't pre-build this form so you can pick a shape that fits. You can change it later.", 'dono' ) }
                    onPick={ applyTemplate }
                    onClose={ () => setTemplatePickerOpen( false ) }
                />
            ) }

            { pendingTemplate && (
                <Modal
                    title={ __( 'Apply template', 'dono' ) }
                    onRequestClose={ () => setPendingTemplate( null ) }
                    size="small"
                >
                    <p style={ { marginTop: 0 } }>
                        { __( 'Replace the current form with this template? You can undo this afterwards.', 'dono' ) }
                    </p>
                    <div style={ { display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 } }>
                        <Btn onClick={ () => setPendingTemplate( null ) }>{ __( 'Cancel', 'dono' ) }</Btn>
                        <Btn variant="primary" onClick={ () => performApplyTemplate( pendingTemplate, true ) }>
                            { __( 'Replace form', 'dono' ) }
                        </Btn>
                    </div>
                </Modal>
            ) }
        </div>
    );
}

// Template picker shown when a form has no blocks.
// Mirrors the block-editor store's selection into a React state setter on the parent.
// Lives inside BlockEditorProvider so useSelect is guaranteed to see the populated store.
function BlockSelectionSync( { onChange } ) {
    const selectedBlockId = useSelect(
        ( s ) => s( 'core/block-editor' ).getSelectedBlockClientId(),
        []
    );
    useEffect( () => {
        onChange( selectedBlockId || null );
    }, [ selectedBlockId, onChange ] );
    return null;
}

/**
 * Clear the block selection on mousedown outside a block, its popovers, or the
 * sidebars. Must dispatch via useRegistry(): BlockEditorProvider scopes the
 * block-editor store to a sub-registry the global wp.data.dispatch never reaches.
 */
function DeselectOnOutsideClick() {
    const registry = useRegistry();
    useEffect( () => {
        const ALLOW = '[data-block], .block-editor-block-list__block, ' +
            '.block-editor-block-toolbar, .block-editor-block-popover, ' +
            '.block-editor-block-contextual-toolbar, ' +
            '.components-popover, .components-dropdown, ' +
            '.dono-form-editor__sidebar, ' +
            '.dono-form-editor__secondary, ' +
            '.interface-interface-skeleton__sidebar';
        const onDocMouseDown = ( e ) => {
            const t = e.target;
            if ( ! t || t.nodeType !== 1 ) return;
            if ( t.closest( ALLOW ) ) return;
            registry.dispatch( 'core/block-editor' ).clearSelectedBlock();
        };
        document.addEventListener( 'mousedown', onDocMouseDown, true );
        return () => document.removeEventListener( 'mousedown', onDocMouseDown, true );
    }, [ registry ] );
    return null;
}

/**
 * A generic block-manipulation bridge for extensions (the AI form assistant uses
 * it): reads the current fields and inserts/updates/removes/moves them. Lives
 * inside BlockEditorProvider and dispatches via useRegistry() so it reaches the
 * scoped store, and every change flows through onChange, so undo and the dirty
 * check treat an extension edit exactly like a hand edit. Nothing is persisted
 * until the operator saves the form.
 */
function AssistantBridge() {
    const registry = useRegistry();
    useEffect( () => {
        const store = () => registry.select( 'core/block-editor' );
        const act   = () => registry.dispatch( 'core/block-editor' );
        window.donoFormBlocks = {
            getBlocks: () =>
                store().getBlocks().map( ( b ) => ( {
                    clientId: b.clientId,
                    name: b.name,
                    attributes: b.attributes,
                } ) ),
            insertBlock: ( name, attributes, afterClientId ) => {
                const block = createBlock( name, attributes || {} );
                const order = store().getBlockOrder( '' );
                const found = afterClientId ? order.indexOf( afterClientId ) : -1;
                act().insertBlock( block, found !== -1 ? found + 1 : order.length, '' );
                return block.clientId;
            },
            updateBlock: ( clientId, attributes ) =>
                act().updateBlockAttributes( clientId, attributes || {} ),
            removeBlock: ( clientId ) => act().removeBlock( clientId, false ),
            moveBlock: ( clientId, toIndex ) =>
                act().moveBlocksToPosition( [ clientId ], '', '', toIndex ),
        };
        return () => { delete window.donoFormBlocks; };
    }, [ registry ] );
    return null;
}

function CanvasEmpty() {
    return (
        <div className="dono-form-editor__empty">
            <h3>{ __( 'Start building your donation form', 'dono' ) }</h3>
            <p>{ __( 'Add a heading, an amount block, and a submit button to take your first donation.', 'dono' ) }</p>
            <Inserter
                position="bottom center"
                rootClientId=""
                renderToggle={ ( { onToggle, isOpen } ) => (
                    <button type="button" onClick={ onToggle } aria-expanded={ isOpen }>
                        + { __( 'Add your first block', 'dono' ) }
                    </button>
                ) }
            />
        </div>
    );
}

const VIEW_TABS = [
    { id: 'develop',  label: __( 'Build', 'dono' ),    icon: <LocalIcon name="edit"     size={ 15 } /> },
    { id: 'preview',  label: __( 'Preview', 'dono' ),  icon: <LocalIcon name="eye"      size={ 15 } /> },
    // Settings is a third view of the same form, so it belongs with the other
    // two rather than behind a cog on the far side of the bar, where it read as
    // a tool acting on the current view instead of a view of its own.
    { id: 'settings', label: __( 'Settings', 'dono' ), icon: <LocalIcon name="settings" size={ 15 } /> },
];

function EditorHeader( {
    backHref, title, onTitleChange,
    view, onViewChange,
    canUndo, canRedo, onUndo, onRedo, onOpenTemplates,
    saving, savingAction, isDirty, onSave,
    status, missingRequiredLabels, onPublish, onUnpublish,
    sidebarOpen, onToggleSidebar,
    secondaryView, onToggleSecondaryView,
} ) {
    const inserterOpen   = secondaryView === 'inserter';
    const listViewOpen   = secondaryView === 'listview';
    const isPublished    = status === 'published';
    const missing        = Array.isArray( missingRequiredLabels ) ? missingRequiredLabels : [];
    const publishDisabledReason = missing.length > 0
        ? sprintf(
            /* translators: %s: comma-separated list of missing block labels. */
            __( 'Add these blocks first: %s.', 'dono' ),
            missing.join( ', ' )
        )
        : '';
    // Inserter / list-view / undo / redo are authoring tools; hide them in
    // Preview + Settings so the chrome matches the mode.
    const showAuthoringTools = view === 'develop';

    return (
        <div className="dono-editor-header">
            <div className="dono-editor-header__left">
                <a className="dono-editor-header__back" href={ backHref }>
                    <LocalIcon name="chevron-left" size={ 20 } />
                    <span>{ __( 'Campaign overview', 'dono' ) }</span>
                </a>
                { showAuthoringTools && (
                    <>
                        <span className="dono-editor-header__divider" aria-hidden="true" />
                        <Button
                            icon={ inserterOpen ? CloseIcon : PlusIcon }
                            label={ inserterOpen ? __( 'Close block inserter', 'dono' ) : __( 'Toggle block inserter', 'dono' ) }
                            onClick={ () => onToggleSecondaryView( 'inserter' ) }
                            isPressed={ inserterOpen }
                            showTooltip
                        />
                        <Button
                            icon={ ListViewIcon }
                            label={ __( 'Toggle block outline', 'dono' ) }
                            onClick={ () => onToggleSecondaryView( 'listview' ) }
                            isPressed={ listViewOpen }
                            showTooltip
                        />
                        <Button icon={ UndoIcon } label={ __( 'Undo', 'dono' ) } onClick={ onUndo } disabled={ ! canUndo } />
                        <Button icon={ RedoIcon } label={ __( 'Redo', 'dono' ) } onClick={ onRedo } disabled={ ! canRedo } />
                        <Button
                            icon={ <LocalIcon name="layout-grid" size={ 20 } /> }
                            label={ __( 'Start from a template', 'dono' ) }
                            onClick={ onOpenTemplates }
                            showTooltip
                        />
                    </>
                ) }
            </div>

            <div className="dono-editor-header__center">
                <input
                    className="dono-editor-header__title"
                    type="text"
                    value={ title }
                    onChange={ ( e ) => onTitleChange( e.target.value ) }
                    placeholder={ __( 'Untitled donation form', 'dono' ) }
                />
            </div>

            <div className="dono-editor-header__right">
                <div className="dono-editor-header__tabs" role="tablist">
                    { VIEW_TABS.map( ( t ) => (
                        <button
                            key={ t.id }
                            type="button"
                            role="tab"
                            aria-selected={ view === t.id }
                            className={ `dono-editor-header__tab${ view === t.id ? ' is-active' : '' }` }
                            onClick={ () => onViewChange( t.id ) }
                        >
                            { t.icon }
                            { t.label }
                        </button>
                    ) ) }
                </div>
                <Button
                    variant="secondary"
                    onClick={ onSave }
                    disabled={ saving || ! isDirty }
                    isBusy={ saving && savingAction === 'save' }
                >
                    { isDirty ? __( 'Save', 'dono' ) : __( 'Saved', 'dono' ) }
                </Button>
                { isPublished ? (
                    <Button
                        variant="secondary"
                        onClick={ onUnpublish }
                        disabled={ saving }
                        isBusy={ saving && savingAction === 'unpublish' }
                    >
                        { __( 'Unpublish', 'dono' ) }
                    </Button>
                ) : (
                    <Button
                        variant="primary"
                        onClick={ onPublish }
                        disabled={ saving || missing.length > 0 }
                        isBusy={ saving && savingAction === 'publish' }
                        label={ publishDisabledReason || undefined }
                        showTooltip={ !! publishDisabledReason }
                    >
                        { __( 'Publish', 'dono' ) }
                    </Button>
                ) }
                <Button
                    icon={ PanelRightIcon }
                    label={ __( 'Toggle side panel', 'dono' ) }
                    onClick={ onToggleSidebar }
                    isPressed={ sidebarOpen }
                    showTooltip
                />
            </div>
        </div>
    );
}

const DEVICES = [
    { id: 'desktop', label: __( 'Desktop', 'dono' ), icon: DesktopIcon, width: '100%'  },
    { id: 'tablet',  label: __( 'Tablet', 'dono' ),  icon: TabletIcon,  width: '768px' },
    { id: 'phone',   label: __( 'Phone', 'dono' ),   icon: MobileIcon,  width: '390px' },
];

function PreviewPane( { loading, html, device, onDeviceChange } ) {
    const active = DEVICES.find( ( d ) => d.id === device ) || DEVICES[ 0 ];
    const isPhone = active.id === 'phone';

    return (
        <div className="dono-form-editor__preview">
            <div className="dono-form-editor__preview-toolbar" role="tablist">
                { DEVICES.map( ( d ) => (
                    <Button
                        key={ d.id }
                        icon={ d.icon }
                        role="tab"
                        aria-selected={ device === d.id }
                        label={ d.label }
                        showTooltip
                        className={ `dono-form-editor__device${ device === d.id ? ' is-active' : '' }` }
                        onClick={ () => onDeviceChange( d.id ) }
                    />
                ) ) }
            </div>
            { loading && html === '' ? (
                <div className="dono-form-editor__preview-spinner"><Spinner /></div>
            ) : (
                <div className="dono-form-editor__preview-stage">
                    <div
                        className={ `dono-form-editor__device-frame is-${ active.id }${ isPhone ? ' has-bezel' : '' }` }
                        style={ { width: active.width, position: 'relative' } }
                    >
                        <iframe
                            className="dono-form-editor__preview-frame"
                            title={ __( 'Form preview', 'dono' ) }
                            srcDoc={ html }
                        />
                        { loading && (
                            // Dim the stale render while a refresh is in flight.
                            <div
                                className="dono-form-editor__preview-spinner"
                                style={ { position: 'absolute', inset: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'rgba(255,255,255,0.6)' } }
                            >
                                <Spinner />
                            </div>
                        ) }
                    </div>
                </div>
            ) }
        </div>
    );
}

function SettingsView( { c, campaigns, gateways, funds, blocks } ) {
    return (
        <div className="dono-form-editor__settings">
            <FormSettingsPanel
                c={ c }
                campaigns={ campaigns }
                gateways={ gateways }
                funds={ funds }
                blocks={ blocks }
            />
        </div>
    );
}

function FormSidebar( { hasSelection } ) {
    return (
        <div className="dono-form-sidebar">
            <div className="dono-form-sidebar__header">
                <h2 className="dono-form-sidebar__title">{ __( 'Block', 'dono' ) }</h2>
            </div>
            <div className="dono-form-sidebar__body">
                { hasSelection ? (
                    <BlockInspector />
                ) : (
                    <SidebarIntro
                        iconName="edit"
                        title={ __( 'Block settings', 'dono' ) }
                        description={ __( 'Select a block on the canvas to see its settings here. Form-wide settings live in the Settings tab.', 'dono' ) }
                    />
                ) }
            </div>
        </div>
    );
}

const STATUS_RANK = { fail: 0, warn: 1, pass: 2 };

/**
 * Preview-mode sidebar: a "pre-launch readiness" checklist. Server-side
 * checks come from /admin/forms/{id}/readiness; the editor adds block-level
 * checks (required fields) on top, because those depend on the in-memory
 * (unsaved) block markup.
 */
function PreviewSidebar( { formId, blocks, missingRequired } ) {
    const [ serverChecks, setServerChecks ] = useState( null );
    const [ error, setError ] = useState( null );

    useEffect( () => {
        if ( ! formId ) return;
        let cancelled = false;
        setServerChecks( null );
        setError( null );
        // POST the live blocks so the checks reflect unsaved edits, not the
        // last-saved form the adjacent preview no longer matches.
        apiFetch( {
            path:   `/dono/v1/admin/forms/${ formId }/readiness`,
            method: 'POST',
            data:   { blocks: serialize( blocks ) },
        } )
            .then( ( res ) => { if ( ! cancelled ) setServerChecks( res.checks || [] ); } )
            .catch( ( err ) => { if ( ! cancelled ) setError( err?.message || __( 'Could not load readiness checks.', 'dono' ) ); } );
        return () => { cancelled = true; };
    }, [ formId, blocks ] );

    const blockChecks = useMemo( () => {
        const out = [];
        if ( missingRequired && missingRequired.length > 0 ) {
            out.push( {
                id:     'required-blocks',
                status: 'fail',
                label:  sprintf(
                    /* translators: %s: comma-separated list of missing block labels. */
                    __( 'Missing required fields: %s', 'dono' ),
                    missingRequired.map( ( r ) => r.label ).join( ', ' )
                ),
                detail: __( 'Donors need these to complete a donation.', 'dono' ),
            } );
        } else {
            out.push( {
                id:     'required-blocks',
                status: 'pass',
                label:  __( 'Required fields present', 'dono' ),
            } );
        }
        return out;
    }, [ missingRequired ] );

    const allChecks = useMemo( () => {
        const merged = [ ...blockChecks, ...( serverChecks || [] ) ];
        // Sort failures first, warnings next, passes last. Stable within each bucket.
        return merged
            .map( ( c, i ) => [ c, i ] )
            .sort( ( a, b ) => ( STATUS_RANK[ a[ 0 ].status ] - STATUS_RANK[ b[ 0 ].status ] ) || ( a[ 1 ] - b[ 1 ] ) )
            .map( ( pair ) => pair[ 0 ] );
    }, [ blockChecks, serverChecks ] );

    const counts = useMemo( () => {
        const out = { pass: 0, warn: 0, fail: 0 };
        for ( const c of allChecks ) out[ c.status ] = ( out[ c.status ] || 0 ) + 1;
        return out;
    }, [ allChecks ] );

    // Only surface what needs attention. Passing checks are noise: the
    // summary line already states whether the form is safe to publish.
    const visibleChecks = useMemo(
        () => allChecks.filter( ( c ) => c.status !== 'pass' ),
        [ allChecks ]
    );

    // Missing required blocks are the only hard publish block (enforced by the
    // server). Gateway/HTTPS/receipt checks are things to fix before donors can
    // actually give, but do not block saving the form as published.
    const blockFail  = useMemo( () => blockChecks.filter( ( c ) => c.status === 'fail' ).length, [ blockChecks ] );
    const serverFail = useMemo( () => ( serverChecks || [] ).filter( ( c ) => c.status === 'fail' ).length, [ serverChecks ] );

    const summaryText = ( () => {
        if ( ! serverChecks && ! error ) return __( 'Running checks…', 'dono' );
        if ( blockFail > 0 ) return sprintf(
            /* translators: %d: number of failing required-field checks that block publishing. */
            _n( '%d issue blocks publishing', '%d issues block publishing', blockFail, 'dono' ),
            blockFail
        );
        if ( serverFail > 0 ) return sprintf(
            /* translators: %d: number of readiness issues to fix before the form can take donations. */
            _n( '%d issue to fix before donors can give', '%d issues to fix before donors can give', serverFail, 'dono' ),
            serverFail
        );
        if ( counts.warn > 0 ) return sprintf(
            /* translators: %d: number of warning readiness checks. */
            _n( '%d thing to review', '%d things to review', counts.warn, 'dono' ),
            counts.warn
        );
        return __( 'Form is ready to publish', 'dono' );
    } )();

    const summaryStatus = counts.fail > 0 ? 'fail' : counts.warn > 0 ? 'warn' : 'pass';

    return (
        <div className="dono-form-sidebar">
            <div className="dono-form-sidebar__header">
                <h2 className="dono-form-sidebar__title">{ __( 'Pre-launch checks', 'dono' ) }</h2>
                <p className={ `dono-readiness__summary is-${ summaryStatus }` }>
                    <ReadinessStatusIcon status={ summaryStatus } />
                    <span>{ summaryText }</span>
                </p>
            </div>
            <div className="dono-form-sidebar__body">
                { error && (
                    <Notice status="error" isDismissible={ false }>{ error }</Notice>
                ) }
                { visibleChecks.length > 0 ? (
                    <ul className="dono-readiness__list">
                        { visibleChecks.map( ( c ) => (
                            <ReadinessRow key={ c.id } check={ c } />
                        ) ) }
                    </ul>
                ) : ( serverChecks && ! error && (
                    <p className="dono-readiness__empty">
                        { __( 'Everything looks good. This form is safe to publish.', 'dono' ) }
                    </p>
                ) ) }
            </div>
        </div>
    );
}

function ReadinessRow( { check } ) {
    return (
        <li className={ `dono-readiness__row is-${ check.status }` }>
            <ReadinessStatusIcon status={ check.status } />
            <div className="dono-readiness__body">
                <div className="dono-readiness__label">{ check.label }</div>
                { check.detail && (
                    <div className="dono-readiness__detail">{ check.detail }</div>
                ) }
                { check.action_url && check.action_label && (
                    <a
                        className="dono-readiness__action"
                        href={ check.action_url }
                        target="_blank"
                        rel="noreferrer"
                    >
                        { check.action_label }
                    </a>
                ) }
            </div>
        </li>
    );
}

function ReadinessStatusIcon( { status } ) {
    if ( status === 'pass' ) return <LocalIcon name="check" size={ 16 } className="dono-readiness__icon" aria-hidden="true" />;
    if ( status === 'warn' ) return <LocalIcon name="alert" size={ 16 } className="dono-readiness__icon" aria-hidden="true" />;
    return <LocalIcon name="close" size={ 16 } className="dono-readiness__icon" aria-hidden="true" />;
}

function SidebarIntro( { iconName, title, description } ) {
    return (
        <div className="dono-sidebar-intro">
            <span className="dono-sidebar-intro__icon" aria-hidden="true">
                <LocalIcon name={ iconName } size={ 18 } />
            </span>
            <div className="dono-sidebar-intro__text">
                <h3 className="dono-sidebar-intro__title">{ title }</h3>
                <p className="dono-sidebar-intro__desc">{ description }</p>
            </div>
        </div>
    );
}

const SETTINGS_TABS = [
    { id: 'general',   label: __( 'General', 'dono' ) },
    { id: 'goal',      label: __( 'Goal', 'dono' ) },
    { id: 'gateways',  label: __( 'Gateways', 'dono' ) },
    { id: 'after',     label: __( 'After donation', 'dono' ) },
    { id: 'embed',     label: __( 'Embed', 'dono' ) },
];

function FormSettingsPanel( { c, campaigns, gateways, funds, blocks } ) {
    const settings = useMemo(
        () => mergeFormSettings( c.record.settings ),
        [ c.record.settings ]
    );

    const setSettings = ( patch ) =>
        c.edit( { settings: { ...settings, ...patch } } );

    const [ activeTab, setActiveTab ] = useState( 'general' );

    return (
        <div className="dono-form-settings">
            <div className="dono-form-settings__nav" role="tablist" aria-label={ __( 'Settings sections', 'dono' ) }>
                { SETTINGS_TABS.map( ( t ) => (
                    <button
                        key={ t.id }
                        type="button"
                        role="tab"
                        aria-selected={ activeTab === t.id }
                        className={ `dono-form-settings__nav-item ${ activeTab === t.id ? 'is-active' : '' }` }
                        onClick={ () => setActiveTab( t.id ) }
                    >
                        { t.label }
                    </button>
                ) ) }
            </div>
            <main className="dono-form-settings__main">
                { activeTab === 'general'   && <GeneralSection   c={ c } campaigns={ campaigns } funds={ funds } settings={ settings } setSettings={ setSettings } /> }
                { activeTab === 'goal'      && <GoalSection      settings={ settings } setSettings={ setSettings } /> }
                { activeTab === 'gateways'  && <GatewaysSection  gateways={ gateways } settings={ settings } setSettings={ setSettings } blocks={ blocks } /> }
                { activeTab === 'after'     && <AfterSection     settings={ settings } setSettings={ setSettings } /> }
                { activeTab === 'embed'     && <EmbedSection     slug={ c.value( 'slug' ) } /> }
            </main>
        </div>
    );
}

function SettingsRow( { title, description, children } ) {
    return (
        <section className="dono-form-settings__row">
            <header className="dono-form-settings__row-head">
                <h3 className="dono-form-settings__row-title">{ title }</h3>
                { description && <p className="dono-form-settings__row-desc">{ description }</p> }
            </header>
            <div className="dono-form-settings__row-body">{ children }</div>
        </section>
    );
}

function fundSelectOptions( funds ) {
    const out = [ { value: '0', label: __( '(Use campaign or org default)', 'dono' ) } ];
    for ( const f of Array.isArray( funds ) ? funds : [] ) {
        if ( ! f.selectable ) {
            out.push( { value: `g:${ f.id }`, label: f.label, disabled: true } );
            continue;
        }
        out.push( {
            value: String( f.id ),
            label: f.depth ? `- ${ f.label }` : f.label,
        } );
    }
    return out;
}

function GeneralSection( { c, campaigns, funds, settings, setSettings } ) {
    return (
        <>
            <SettingsRow
                title={ __( 'Identity', 'dono' ) }
                description={ __( 'The form name and the slug used in the URL and shortcode.', 'dono' ) }
            >
                <TextControl
                    label={ __( 'Title', 'dono' ) }
                    value={ c.value( 'title' ) }
                    onChange={ c.setValue( 'title' ) }
                    __nextHasNoMarginBottom
                />
                <TextControl
                    label={ __( 'Slug', 'dono' ) }
                    value={ c.value( 'slug' ) }
                    onChange={ c.setValue( 'slug' ) }
                    help={ __( 'Used in the shortcode and the form URL.', 'dono' ) }
                    __nextHasNoMarginBottom
                />
            </SettingsRow>

            <SettingsRow
                title={ __( 'Status', 'dono' ) }
                description={ __( 'Use the Publish button in the header to go live. Archived forms stay in the system but stop accepting donations.', 'dono' ) }
            >
                <SelectControl
                    value={ c.value( 'status', 'draft' ) }
                    options={ ( () => {
                        // Publishing only flows through the gated Publish
                        // button so the readiness checks can't be bypassed.
                        // "Published" stays in the list only when the form
                        // is already published so admins can see the state.
                        const status = c.value( 'status', 'draft' );
                        const opts = [
                            { value: 'draft',    label: STATUS_LABEL.draft },
                            { value: 'archived', label: STATUS_LABEL.archived },
                        ];
                        if ( status === 'published' ) {
                            opts.splice( 1, 0, { value: 'published', label: STATUS_LABEL.published, disabled: true } );
                        }
                        return opts;
                    } )() }
                    onChange={ c.setValue( 'status' ) }
                    __nextHasNoMarginBottom
                />
            </SettingsRow>

            <SettingsRow
                title={ __( 'Campaign', 'dono' ) }
                description={ __( 'Every form lives under a campaign. Move this form to a different one here.', 'dono' ) }
            >
                <SelectControl
                    value={ String( c.value( 'campaign_id', 0 ) || 0 ) }
                    options={ campaigns.map( ( cmp ) => ( {
                        value: String( cmp.id ),
                        label: cmp.title,
                    } ) ) }
                    onChange={ ( v ) => {
                        const next = Number( v );
                        if ( next > 0 ) c.edit( { campaign_id: next } );
                    } }
                    __nextHasNoMarginBottom
                />
            </SettingsRow>

            <SettingsRow
                title={ __( 'Default fund', 'dono' ) }
                description={ __( 'Where donations land when this form has no fund picker, or the donor does not choose one.', 'dono' ) }
            >
                <SelectControl
                    value={ String( c.value( 'default_fund_id', 0 ) || 0 ) }
                    options={ fundSelectOptions( funds ) }
                    onChange={ ( v ) => c.edit( { default_fund_id: Number( v ) || null } ) }
                    help={ __( 'Leave on the default to fall back to the campaign fund, then the organisation default.', 'dono' ) }
                    __nextHasNoMarginBottom
                />
            </SettingsRow>

            <SettingsRow
                title={ __( 'Layout & style', 'dono' ) }
                description={ __( 'How the form is presented: its layout, style preset, width, and whether it sits in a card.', 'dono' ) }
            >
                <SelectControl
                    label={ __( 'Layout', 'dono' ) }
                    value={ settings.layout }
                    options={ LAYOUT_OPTIONS }
                    onChange={ ( v ) => setSettings( { layout: v } ) }
                    __nextHasNoMarginBottom
                />
                <StylePresetField
                    value={ settings.style?.preset_id || '' }
                    onChange={ ( v ) => setSettings( { style: { ...settings.style, preset_id: v } } ) }
                />
                <Slider
                    label={ __( 'Maximum width', 'dono' ) }
                    value={ settings.container?.width ?? 540 }
                    onChange={ ( v ) => setSettings( { container: { ...settings.container, width: v } } ) }
                    min={ 320 }
                    max={ 1200 }
                    unit="px"
                />
                <Segmented
                    label={ __( 'Container', 'dono' ) }
                    value={ settings.container?.style ?? 'plain' }
                    onChange={ ( v ) => setSettings( { container: { ...settings.container, style: v } } ) }
                    options={ [
                        { value: 'frame', label: __( 'Frame', 'dono' ) },
                        { value: 'plain', label: __( 'Plain', 'dono' ) },
                    ] }
                    help={ __( '"Frame" wraps the form in a card with a shadow; "Plain" renders it flush with the page.', 'dono' ) }
                />
            </SettingsRow>
        </>
    );
}

const GOAL_TYPE_OPTIONS = [
    { value: 'none',      label: __( 'No goal', 'dono' ) },
    { value: 'amount',    label: __( 'Amount', 'dono' ) },
    { value: 'donations', label: __( 'Donations', 'dono' ) },
    { value: 'donors',    label: __( 'Donors', 'dono' ) },
];

const GOAL_TYPE_DESC = {
    none:      __( 'No progress bar or target on this form.', 'dono' ),
    amount:    __( 'Track progress toward a fundraising total.', 'dono' ),
    donations: __( 'Track the number of completed donations to this form.', 'dono' ),
    donors:    __( 'Track the number of unique donors who give through this form.', 'dono' ),
};

function GoalSection( { settings, setSettings } ) {
    const goal = settings.goal || { type: 'none', amount_cents: 0, count: 0 };

    return (
        <SettingsRow
            title={ __( 'Form goal', 'dono' ) }
            description={ __( 'An optional goal tracked for this form alone. The Goal block can show this or the parent campaign goal.', 'dono' ) }
        >
            <SelectControl
                label={ __( 'Goal type', 'dono' ) }
                value={ goal.type }
                options={ GOAL_TYPE_OPTIONS }
                onChange={ ( type ) => setSettings( { goal: { type, amount_cents: 0, count: 0 } } ) }
                help={ GOAL_TYPE_DESC[ goal.type ] }
                __nextHasNoMarginBottom
            />
            { goal.type === 'amount' && (
                <BaseControl
                    id="dono-form-goal-amount"
                    label={ __( 'Target amount', 'dono' ) }
                    help={ __( 'In the currency this form uses.', 'dono' ) }
                    __nextHasNoMarginBottom
                >
                    <AmountInput
                        value={ ( Number( goal.amount_cents ) || 0 ) / 100 }
                        onChange={ ( major ) => setSettings( { goal: {
                            ...goal,
                            type:         'amount',
                            amount_cents: Number.isFinite( major ) && major > 0 ? Math.round( major * 100 ) : 0,
                            count:        0,
                        } } ) }
                        currency={ defaultCurrency() }
                        min={ 0 }
                        placeholder="0"
                        inputProps={ { id: 'dono-form-goal-amount' } }
                    />
                </BaseControl>
            ) }
            { ( goal.type === 'donations' || goal.type === 'donors' ) && (
                <TextControl
                    label={ __( 'Target count', 'dono' ) }
                    type="number"
                    min={ 0 }
                    step="1"
                    value={ String( Number( goal.count ) || '' ) }
                    onChange={ ( v ) => setSettings( { goal: {
                        ...goal,
                        count:        Math.max( 0, Math.round( Number( v ) || 0 ) ),
                        amount_cents: 0,
                    } } ) }
                    __nextHasNoMarginBottom
                />
            ) }
        </SettingsRow>
    );
}
function GatewaysSection( { gateways, settings, setSettings, blocks = [] } ) {
    // The payment-gateways block is the single writer of the allowed list on
    // save (FormService::syncGatewayAllowed). When the block is on the form,
    // editing the checkboxes here would be silently overwritten on save, so
    // we surface that and disable the controls instead of pretending otherwise.
    // Read the live editor blocks so adding/removing the block reflects here
    // immediately; the saved markup lags behind until the next save.
    const blockManaged = blocksInclude( blocks, 'dono/payment-gateways' );

    const toggleGateway = ( id ) => {
        const current = settings.gateways.allowed || [];
        const next = current.includes( id )
            ? current.filter( ( g ) => g !== id )
            : [ ...current, id ];
        setSettings( { gateways: { ...settings.gateways, allowed: next } } );
    };
    return (
        <SettingsRow
            title={ __( 'Allowed gateways', 'dono' ) }
            description={ __( 'Pick which payment gateways are offered on this form. Leave empty to allow every gateway configured in Settings.', 'dono' ) }
        >
            { blockManaged && (
                <p className="dono-form-settings__notice">
                    { __( 'A Payment gateways block on this form controls the list. Edit gateways from the form builder, not here.', 'dono' ) }
                </p>
            ) }
            <div className="dono-sidebar-list">
                { gateways.map( ( g ) => (
                    <label key={ g.id } className="dono-sidebar-check">
                        <input
                            type="checkbox"
                            checked={ ( settings.gateways.allowed || [] ).includes( g.id ) }
                            onChange={ () => toggleGateway( g.id ) }
                            disabled={ blockManaged }
                        />
                        <span>{ g.label }</span>
                    </label>
                ) ) }
            </div>
            <label className="dono-sidebar-check" style={ { marginTop: 14 } }>
                <input
                    type="checkbox"
                    checked={ !! settings.test_mode }
                    onChange={ () => setSettings( { test_mode: ! settings.test_mode } ) }
                />
                <span>{ __( 'Test mode (no real payment, excluded from reporting)', 'dono' ) }</span>
            </label>
        </SettingsRow>
    );
}

function AfterSection( { settings, setSettings } ) {
    return (
        <>
            <SettingsRow
                title={ __( 'Thank-you message', 'dono' ) }
                description={ __( 'Shown to the donor after a successful donation, unless a redirect URL is set.', 'dono' ) }
            >
                <TextareaControl
                    value={ settings.thank_you_message }
                    onChange={ ( v ) => setSettings( { thank_you_message: v } ) }
                    rows={ 3 }
                    __nextHasNoMarginBottom
                />
            </SettingsRow>
            <SettingsRow
                title={ __( 'Redirect URL', 'dono' ) }
                description={ __( 'If set, donors are sent here instead of seeing the thank-you message.', 'dono' ) }
            >
                <TextControl
                    type="url"
                    value={ settings.redirect_url }
                    onChange={ ( v ) => setSettings( { redirect_url: v } ) }
                    placeholder="https://"
                    help={
                        settings.redirect_url && ! /^https?:\/\//i.test( settings.redirect_url.trim() )
                            ? __( 'Use a full URL starting with http:// or https://', 'dono' )
                            : undefined
                    }
                    __nextHasNoMarginBottom
                />
            </SettingsRow>
        </>
    );
}

function EmbedSection( { slug } ) {
    const shortcode = `[dono_donation_form slug="${ slug }"]`;
    return (
        <SettingsRow
            title={ __( 'Embed', 'dono' ) }
            description={ __( 'Paste this shortcode into any post or page to render the form.', 'dono' ) }
        >
            <ShortcodeField value={ shortcode } />
        </SettingsRow>
    );
}

function StylePresetField( { value, onChange } ) {
    const presets   = Array.isArray( window.dono?.styling?.presets ) ? window.dono.styling.presets : [];
    const defaultId = String( window.dono?.styling?.default_id || '' );
    const defaultName = presets.find( ( p ) => p.id === defaultId )?.name || defaultId;
    return (
        <SelectControl
            label={ __( 'Style preset', 'dono' ) }
            value={ value }
            options={ [
                {
                    value: '',
                    label: __( 'Inherit (campaign or org default)', 'dono' ) +
                        ( defaultName ? ` (${ defaultName })` : '' ),
                },
                ...presets.map( ( p ) => ( { value: p.id, label: p.name } ) ),
            ] }
            onChange={ onChange }
            help={ __( 'Picks one of the presets defined in Settings → Brand. Leave on Inherit to follow the campaign\'s choice.', 'dono' ) }
            __nextHasNoMarginBottom
            __next40pxDefaultSize
        />
    );
}

function ShortcodeField( { value } ) {
    const [ copied, setCopied ] = useState( false );
    useEffect( () => {
        if ( ! copied ) return;
        const id = setTimeout( () => setCopied( false ), 1500 );
        return () => clearTimeout( id );
    }, [ copied ] );

    const onCopy = async () => {
        try {
            await navigator.clipboard?.writeText( value );
            setCopied( true );
        } catch ( e ) {
            // No clipboard access; user can copy manually.
        }
    };

    return (
        <div className="dono-shortcode">
            <code className="dono-shortcode__code">{ value }</code>
            <Button
                variant="secondary"
                size="small"
                onClick={ onCopy }
                className="dono-shortcode__copy"
            >
                { copied ? __( 'Copied', 'dono' ) : __( 'Copy', 'dono' ) }
            </Button>
        </div>
    );
}

