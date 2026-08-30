// Built on @wordpress/block-editor directly rather than isolated-block-editor:
// the chrome is ours, and block data lives in a useReducer history so undo and
// redo stay local.

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
import { gatewayIsOn, toggleGatewayAllowed } from '../_shared/gatewayAllowList';
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
import { useDispatch, useRegistry, useSelect } from '@wordpress/data';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import { useFundKitRecord } from '../_shared/useFundKitRecord';
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
    { value: 'inline', label: __( 'Inline (in-page)', 'fundkit-fundraising-campaigns' ) },
    { value: 'modal',  label: __( 'Modal (button opens form)', 'fundkit-fundraising-campaigns' ) },
];

function mergeFormSettings( stored, base = defaultFormSettings() ) {
    const def = base;
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

/**
 * A template carries a shape, not a whole configuration: it replaces the keys
 * it names and leaves the rest of the author's form alone. Undo holds blocks
 * only, so anything a template overwrites here is gone for good.
 *
 * @since 1.0.0
 */
export function settingsAfterTemplate( current, templateSettings ) {
    return mergeFormSettings( templateSettings, mergeFormSettings( current ) );
}

/**
 * What picking a template does to a form: the blocks it brings, and the
 * settings the form is left with.
 *
 * The settings half lives here rather than in the component so it can be
 * driven by a test. Handing the template's settings straight to the form is
 * the mistake this exists to make hard: it discards everything the author
 * configured that the template never named.
 */
export function templateApplication( currentSettings, template ) {
    const markup = ( template?.blocks ?? '' ).trim();
    const settings = template?.settings && typeof template.settings === 'object'
        ? settingsAfterTemplate( currentSettings, template.settings )
        : null;

    return { markup, settings };
}

let blocksReady = false;
function ensureBlocksRegistered() {
    if ( blocksReady ) return;
    runBlockRegistration();
    blocksReady = true;
}

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
    const c = useFundKitRecord( 'form', formId );

    const [ campaigns, setCampaigns ] = useState( [] );
    const [ gateways, setGateways ]   = useState( [] );
    const [ funds, setFunds ]         = useState( [] );
    const [ error, setError ]         = useState( null );

    const [ history, dispatchHistory ] = useReducer( historyReducer, initialHistory );
    const blocks = history.present;
    const [ lastSavedSerialized, setLastSavedSerialized ] = useState( '' );
    const seededRef = useRef( false );
    const [ templatePickerOpen, setTemplatePickerOpen ] = useState( false );
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

    // Inserter and list view only make sense in Develop, so they close on the
    // way out and come back in whatever state they were left.
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

    // Dropdown sources, not entities, so they are fetched once. A per-source
    // failure surfaces rather than leaving a select silently empty.
    useEffect( () => {
        apiFetch( { path: '/fundkit/v1/admin/forms/campaigns' } )
            .then( setCampaigns )
            .catch( ( err ) => setError( err?.message || __( 'Could not load campaigns.', 'fundkit-fundraising-campaigns' ) ) );
        apiFetch( { path: '/fundkit/v1/admin/forms/gateways' } )
            .then( setGateways )
            .catch( ( err ) => setError( err?.message || __( 'Could not load payment gateways.', 'fundkit-fundraising-campaigns' ) ) );
        apiFetch( { path: '/fundkit/v1/admin/forms/funds' } )
            .then( setFunds )
            .catch( ( err ) => setError( err?.message || __( 'Could not load funds.', 'fundkit-fundraising-campaigns' ) ) );
    }, [] );

    // Expose form context to block edit components (Goal needs campaign progress).
    useEffect( () => {
        window.fundkitFormEditor = {
            formId,
            formCampaignId: Number( c.value( 'campaign_id', 0 ) ) || 0,
            formGoal: mergeFormSettings( c.record.settings ).goal,
            campaigns,
        };
        return () => { delete window.fundkitFormEditor; };
    }, [ formId, c.record.campaign_id, c.record.settings, campaigns ] );

    // Seeds the block-history reducer once, when the entity first resolves. A
    // form created by onboarding ships with empty `blocks`, so first open shows
    // the template picker.
    useEffect( () => {
        if ( seededRef.current || ! c.savedRecord ) return;
        seededRef.current = true;
        const saved  = c.savedRecord.blocks || '';
        const parsed = saved ? parse( saved ) : [];
        dispatchHistory( { type: 'RESET', blocks: parsed } );
        // Baseline against the re-serialized form, which is what the dirty check
        // compares: serialize(parse(x)) !== x for non-canonical markup, so the
        // raw stored markup would flag a pristine form dirty.
        setLastSavedSerialized( serialize( parsed ) );
        if ( parsed.length === 0 ) {
            setTemplatePickerOpen( true );
        }
    }, [ c.savedRecord ] );

    const performApplyTemplate = useCallback( ( template, hasContent ) => {
        const { markup, settings } = templateApplication( c.record.settings, template );
        const parsed = markup ? parse( markup ) : [];
        // CHANGE keeps it in history (undoable) when replacing existing content;
        // RESET sets a fresh baseline for the first-open empty-form seeding.
        dispatchHistory( { type: hasContent ? 'CHANGE' : 'RESET', blocks: parsed } );
        if ( settings ) {
            c.edit( { settings } );
        }
        setPendingTemplate( null );
        setTemplatePickerOpen( false );
    }, [ c ] );

    const applyTemplate = useCallback( ( template ) => {
        if ( history.present.length > 0 ) {
            // Replacing real content needs an explicit confirm.
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
                path:   '/fundkit/v1/admin/forms/preview',
                method: 'POST',
                data:   {
                    blocks:      serialize( blocks ),
                    settings:    mergeFormSettings( c.record.settings ),
                    campaign_id: Number( c.value( 'campaign_id', 0 ) ) || null,
                },
            } );
            setPreviewHtml( res.html || '' );
        } catch ( err ) {
            setError( err?.message || __( 'Preview failed.', 'fundkit-fundraising-campaigns' ) );
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
            // Pending field edits, blocks and a complete settings object go in
            // one explicit PUT so it cannot race the edits store.
            await c.saveEntity( {
                ...c.edits,
                blocks:   serialized,
                settings: mergeFormSettings( c.record.settings ),
                ...extra,
            } );
            setLastSavedSerialized( serialized );
            return true;
        } catch ( err ) {
            setError( err?.message || __( 'Save failed.', 'fundkit-fundraising-campaigns' ) );
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
                    ? __( 'Form saved.', 'fundkit-fundraising-campaigns' )
                    : __( 'Draft saved.', 'fundkit-fundraising-campaigns' )
            );
        }
    }, [ persist, c.record.status ] );

    const editorSettings = useMemo( () => ( {
        allowedBlockTypes: blockRegistry.allowed,
        hasFixedToolbar:   false,
        focusMode:         false,
        // Mirrors theme.json's `settings` shape. Without it the block supports
        // are declared on each block but the inspector ignores them.
        __experimentalFeatures: {
            appearanceTools: true,
            useRootPaddingAwareAlignments: false,
            color: {
                background:     true,
                text:           true,
                link:           true,
                button:         true,
                heading:        true,
                // `custom` enables the free-form picker. Without it only palette
                // swatches render, and the palette is empty, so the panel would
                // be blank.
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
        const required = window.fundkit?.forms?.required_blocks || [];
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
        if ( ok ) notify.success( __( 'Form published.', 'fundkit-fundraising-campaigns' ) );
    }, [ persist, missingRequired ] );

    const onUnpublish = useCallback( async () => {
        setSavingAction( 'unpublish' );
        const ok = await persist( { status: 'draft' } );
        setSavingAction( null );
        if ( ok ) notify.success( __( 'Form moved to draft.', 'fundkit-fundraising-campaigns' ) );
    }, [ persist ] );

    const dirtyForUnload = c.isDirty || serialize( blocks ) !== lastSavedSerialized;
    useEffect( () => {
        if ( ! dirtyForUnload ) return undefined;
        const handler = ( e ) => { e.preventDefault(); e.returnValue = ''; return ''; };
        window.addEventListener( 'beforeunload', handler );
        return () => window.removeEventListener( 'beforeunload', handler );
    }, [ dirtyForUnload ] );

    // Cmd/Ctrl+S saves from anywhere; undo and redo only fire outside text
    // fields so native text undo keeps working inside blocks.
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
        return <div className="fundkit-form-editor__loading"><Spinner /></div>;
    }
    if ( c.notFound ) {
        return (
            <Notice status="error" isDismissible={ false }>
                { __( 'Form not found.', 'fundkit-fundraising-campaigns' ) }
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
        <div className="fundkit-form-editor__notices">
            { missingRequired.length > 0 && (
                <Notice status="warning" isDismissible={ false }>
                    { sprintf(
                        /* translators: %s: comma-separated list of missing block labels (Name, Email). */
                        __( 'Add these blocks before publishing: %s.', 'fundkit-fundraising-campaigns' ),
                        missingRequired.map( ( r ) => r.label ).join( ', ' )
                    ) }
                </Notice>
            ) }
            { error && (
                <Notice status="error" onRemove={ () => setError( null ) }>{ error }</Notice>
            ) }
        </div>
    );

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
        const presets   = Array.isArray( window.fundkit?.styling?.presets ) ? window.fundkit.styling.presets : [];
        const defaults  = window.fundkit?.styling?.defaults || {};
        const defaultId = String( window.fundkit?.styling?.default_id || '' );

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

        // Mirrors CampaignStyleResolver: an accent-soft that is only the
        // catalogue default is dropped, so the stylesheet's color-mix derives it
        // from --fundkit-accent as the published form does. An explicit one stays.
        const explicitSoft =
            ( chosenPreset?.tokens && 'fundkit-accent-soft' in chosenPreset.tokens ) ||
            ( ! formPresetId && 'fundkit-accent-soft' in campaignInlineTokens ) ||
            ( 'fundkit-accent-soft' in formInlineTokens );
        if ( ! explicitSoft && tokens[ 'fundkit-accent-soft' ] === defaults[ 'fundkit-accent-soft' ] ) {
            delete tokens[ 'fundkit-accent-soft' ];
        }

        const sx = {};
        for ( const k in tokens ) {
            if ( typeof tokens[ k ] === 'string' && tokens[ k ] !== '' ) {
                sx[ `--${ k }` ] = tokens[ k ];
            }
        }

        // Matches the published form's container width; the clamp mirrors the
        // runtime in PHP.
        const cw = Number( merged.container?.width );
        if ( cw >= 320 && cw <= 1600 ) {
            sx[ '--fundkit-editor-sheet-width' ] = `${ cw }px`;
        }

        return sx;
    } )();

    return (
        <div className="fundkit-form-editor" style={ themeVars }>
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
                                    />
                                ) : (
                                    <div className="fundkit-form-editor__canvas">
                                        <div className="fundkit-form-editor__sheet">
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
                                    <div className="fundkit-form-editor__secondary fundkit-form-editor__secondary--inserter">
                                        <BlockLibrary
                                            showInserterHelpPanel={ false }
                                            rootClientId=""
                                            __experimentalInitialTab="blocks"
                                        />
                                    </div>
                                ) : secondaryView === 'listview' ? (
                                    <div className="fundkit-form-editor__secondary fundkit-form-editor__secondary--listview">
                                        <div className="fundkit-form-editor__secondary-title">
                                            { __( 'Form structure', 'fundkit-fundraising-campaigns' ) }
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
                    intro={ __( "We didn't pre-build this form so you can pick a shape that fits. You can change it later.", 'fundkit-fundraising-campaigns' ) }
                    onPick={ applyTemplate }
                    onClose={ () => setTemplatePickerOpen( false ) }
                />
            ) }

            { pendingTemplate && (
                <Modal
                    title={ __( 'Apply template', 'fundkit-fundraising-campaigns' ) }
                    onRequestClose={ () => setPendingTemplate( null ) }
                    size="small"
                >
                    <p style={ { marginTop: 0 } }>
                        { __( 'Replace the current form with this template? Its blocks take over, and so do the settings it carries: layout, style, gateways, recurring and the thank-you message. Undo brings the blocks back, but not the settings.', 'fundkit-fundraising-campaigns' ) }
                    </p>
                    <div style={ { display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 } }>
                        <Btn onClick={ () => setPendingTemplate( null ) }>{ __( 'Cancel', 'fundkit-fundraising-campaigns' ) }</Btn>
                        <Btn variant="primary" onClick={ () => performApplyTemplate( pendingTemplate, true ) }>
                            { __( 'Replace form', 'fundkit-fundraising-campaigns' ) }
                        </Btn>
                    </div>
                </Modal>
            ) }
        </div>
    );
}

// Lives inside BlockEditorProvider so useSelect is guaranteed to see the
// populated store.
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

// Must dispatch via useRegistry(): BlockEditorProvider scopes the block-editor
// store to a sub-registry the global wp.data.dispatch never reaches.
function DeselectOnOutsideClick() {
    const registry = useRegistry();
    useEffect( () => {
        const ALLOW = '[data-block], .block-editor-block-list__block, ' +
            '.block-editor-block-toolbar, .block-editor-block-popover, ' +
            '.block-editor-block-contextual-toolbar, ' +
            '.components-popover, .components-dropdown, ' +
            '.fundkit-form-editor__sidebar, ' +
            '.fundkit-form-editor__secondary, ' +
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

// Block-manipulation bridge for extensions such as the AI form assistant. It
// dispatches via useRegistry() to reach the scoped store, and every change flows
// through onChange so undo and the dirty check treat an extension edit exactly
// like a hand edit. Nothing is persisted until the operator saves.
function AssistantBridge() {
    const registry = useRegistry();
    useEffect( () => {
        const store = () => registry.select( 'core/block-editor' );
        const act   = () => registry.dispatch( 'core/block-editor' );
        window.fundkitFormBlocks = {
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
        return () => { delete window.fundkitFormBlocks; };
    }, [ registry ] );
    return null;
}

function CanvasEmpty() {
    return (
        <div className="fundkit-form-editor__empty">
            <h3>{ __( 'Start building your donation form', 'fundkit-fundraising-campaigns' ) }</h3>
            <p>{ __( 'Add a heading, an amount block, and a submit button to take your first donation.', 'fundkit-fundraising-campaigns' ) }</p>
            <Inserter
                position="bottom center"
                rootClientId=""
                renderToggle={ ( { onToggle, isOpen } ) => (
                    <button type="button" onClick={ onToggle } aria-expanded={ isOpen }>
                        + { __( 'Add your first block', 'fundkit-fundraising-campaigns' ) }
                    </button>
                ) }
            />
        </div>
    );
}

const VIEW_TABS = [
    { id: 'develop',  label: __( 'Build', 'fundkit-fundraising-campaigns' ),    icon: <LocalIcon name="edit"     size={ 15 } /> },
    { id: 'preview',  label: __( 'Preview', 'fundkit-fundraising-campaigns' ),  icon: <LocalIcon name="eye"      size={ 15 } /> },
    // Settings is a third view of the same form, so it sits with the other two
    // rather than behind a cog, which reads as a tool acting on the current
    // view.
    { id: 'settings', label: __( 'Settings', 'fundkit-fundraising-campaigns' ), icon: <LocalIcon name="settings" size={ 15 } /> },
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
            __( 'Add these blocks first: %s.', 'fundkit-fundraising-campaigns' ),
            missing.join( ', ' )
        )
        : '';
    // Inserter, list view, undo and redo are authoring tools, so the chrome
    // drops them outside Develop.
    const showAuthoringTools = view === 'develop';

    return (
        <div className="fundkit-editor-header">
            <div className="fundkit-editor-header__left">
                <a className="fundkit-editor-header__back" href={ backHref }>
                    <LocalIcon name="chevron-left" size={ 20 } />
                    <span>{ __( 'Campaign overview', 'fundkit-fundraising-campaigns' ) }</span>
                </a>
                { showAuthoringTools && (
                    <>
                        <span className="fundkit-editor-header__divider" aria-hidden="true" />
                        <Button
                            icon={ inserterOpen ? CloseIcon : PlusIcon }
                            label={ inserterOpen ? __( 'Close block inserter', 'fundkit-fundraising-campaigns' ) : __( 'Toggle block inserter', 'fundkit-fundraising-campaigns' ) }
                            onClick={ () => onToggleSecondaryView( 'inserter' ) }
                            isPressed={ inserterOpen }
                            showTooltip
                        />
                        <Button
                            icon={ ListViewIcon }
                            label={ __( 'Toggle block outline', 'fundkit-fundraising-campaigns' ) }
                            onClick={ () => onToggleSecondaryView( 'listview' ) }
                            isPressed={ listViewOpen }
                            showTooltip
                        />
                        <Button icon={ UndoIcon } label={ __( 'Undo', 'fundkit-fundraising-campaigns' ) } onClick={ onUndo } disabled={ ! canUndo } />
                        <Button icon={ RedoIcon } label={ __( 'Redo', 'fundkit-fundraising-campaigns' ) } onClick={ onRedo } disabled={ ! canRedo } />
                        <Button
                            icon={ <LocalIcon name="layout-grid" size={ 20 } /> }
                            label={ __( 'Start from a template', 'fundkit-fundraising-campaigns' ) }
                            onClick={ onOpenTemplates }
                            showTooltip
                        />
                    </>
                ) }
            </div>

            <div className="fundkit-editor-header__center">
                <input
                    className="fundkit-editor-header__title"
                    type="text"
                    value={ title }
                    onChange={ ( e ) => onTitleChange( e.target.value ) }
                    placeholder={ __( 'Untitled donation form', 'fundkit-fundraising-campaigns' ) }
                />
            </div>

            <div className="fundkit-editor-header__right">
                <div className="fundkit-editor-header__tabs" role="tablist">
                    { VIEW_TABS.map( ( t ) => (
                        <button
                            key={ t.id }
                            type="button"
                            role="tab"
                            aria-selected={ view === t.id }
                            className={ `fundkit-editor-header__tab${ view === t.id ? ' is-active' : '' }` }
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
                    { isDirty ? __( 'Save', 'fundkit-fundraising-campaigns' ) : __( 'Saved', 'fundkit-fundraising-campaigns' ) }
                </Button>
                { isPublished ? (
                    <Button
                        variant="secondary"
                        onClick={ onUnpublish }
                        disabled={ saving }
                        isBusy={ saving && savingAction === 'unpublish' }
                    >
                        { __( 'Unpublish', 'fundkit-fundraising-campaigns' ) }
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
                        { __( 'Publish', 'fundkit-fundraising-campaigns' ) }
                    </Button>
                ) }
                <Button
                    icon={ PanelRightIcon }
                    label={ __( 'Toggle side panel', 'fundkit-fundraising-campaigns' ) }
                    onClick={ onToggleSidebar }
                    isPressed={ sidebarOpen }
                    showTooltip
                />
            </div>
        </div>
    );
}

const DEVICES = [
    { id: 'desktop', label: __( 'Desktop', 'fundkit-fundraising-campaigns' ), icon: DesktopIcon, width: '100%'  },
    { id: 'tablet',  label: __( 'Tablet', 'fundkit-fundraising-campaigns' ),  icon: TabletIcon,  width: '768px' },
    { id: 'phone',   label: __( 'Phone', 'fundkit-fundraising-campaigns' ),   icon: MobileIcon,  width: '390px' },
];

function PreviewPane( { loading, html, device, onDeviceChange } ) {
    const active = DEVICES.find( ( d ) => d.id === device ) || DEVICES[ 0 ];
    const isPhone = active.id === 'phone';

    return (
        <div className="fundkit-form-editor__preview">
            <div className="fundkit-form-editor__preview-toolbar" role="tablist">
                { DEVICES.map( ( d ) => (
                    <Button
                        key={ d.id }
                        icon={ d.icon }
                        role="tab"
                        aria-selected={ device === d.id }
                        label={ d.label }
                        showTooltip
                        className={ `fundkit-form-editor__device${ device === d.id ? ' is-active' : '' }` }
                        onClick={ () => onDeviceChange( d.id ) }
                    />
                ) ) }
            </div>
            { loading && html === '' ? (
                <div className="fundkit-form-editor__preview-spinner"><Spinner /></div>
            ) : (
                <div className="fundkit-form-editor__preview-stage">
                    <div
                        className={ `fundkit-form-editor__device-frame is-${ active.id }${ isPhone ? ' has-bezel' : '' }` }
                        style={ { width: active.width, position: 'relative' } }
                    >
                        <iframe
                            className="fundkit-form-editor__preview-frame"
                            title={ __( 'Form preview', 'fundkit-fundraising-campaigns' ) }
                            srcDoc={ html }
                        />
                        { loading && (
                            <div
                                className="fundkit-form-editor__preview-spinner"
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

function SettingsView( { c, campaigns, gateways, funds } ) {
    return (
        <div className="fundkit-form-editor__settings">
            <FormSettingsPanel
                c={ c }
                campaigns={ campaigns }
                gateways={ gateways }
                funds={ funds }
            />
        </div>
    );
}

function FormSidebar( { hasSelection } ) {
    return (
        <div className="fundkit-form-sidebar">
            <div className="fundkit-form-sidebar__header">
                <h2 className="fundkit-form-sidebar__title">{ __( 'Block', 'fundkit-fundraising-campaigns' ) }</h2>
            </div>
            <div className="fundkit-form-sidebar__body">
                { hasSelection ? (
                    <BlockInspector />
                ) : (
                    <SidebarIntro
                        iconName="edit"
                        title={ __( 'Block settings', 'fundkit-fundraising-campaigns' ) }
                        description={ __( 'Select a block on the canvas to see its settings here. Form-wide settings live in the Settings tab.', 'fundkit-fundraising-campaigns' ) }
                    />
                ) }
            </div>
        </div>
    );
}

const STATUS_RANK = { fail: 0, warn: 1, pass: 2 };

// Server checks come from /admin/forms/{id}/readiness; the block-level checks
// are added here because they depend on the in-memory, unsaved block markup.
function PreviewSidebar( { formId, blocks, missingRequired } ) {
    const [ serverChecks, setServerChecks ] = useState( null );
    const [ error, setError ] = useState( null );

    useEffect( () => {
        if ( ! formId ) return;
        let cancelled = false;
        setServerChecks( null );
        setError( null );
        // The live blocks are posted so the checks reflect unsaved edits.
        apiFetch( {
            path:   `/fundkit/v1/admin/forms/${ formId }/readiness`,
            method: 'POST',
            data:   { blocks: serialize( blocks ) },
        } )
            .then( ( res ) => { if ( ! cancelled ) setServerChecks( res.checks || [] ); } )
            .catch( ( err ) => { if ( ! cancelled ) setError( err?.message || __( 'Could not load readiness checks.', 'fundkit-fundraising-campaigns' ) ); } );
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
                    __( 'Missing required fields: %s', 'fundkit-fundraising-campaigns' ),
                    missingRequired.map( ( r ) => r.label ).join( ', ' )
                ),
                detail: __( 'Donors need these to complete a donation.', 'fundkit-fundraising-campaigns' ),
            } );
        } else {
            out.push( {
                id:     'required-blocks',
                status: 'pass',
                label:  __( 'Required fields present', 'fundkit-fundraising-campaigns' ),
            } );
        }
        return out;
    }, [ missingRequired ] );

    const allChecks = useMemo( () => {
        const merged = [ ...blockChecks, ...( serverChecks || [] ) ];
        // Failures first, warnings next, passes last; stable within a bucket.
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

    // Passing checks are noise: the summary line already states whether the
    // form is safe to publish.
    const visibleChecks = useMemo(
        () => allChecks.filter( ( c ) => c.status !== 'pass' ),
        [ allChecks ]
    );

    // Missing required blocks are the only hard publish block, enforced by the
    // server. Gateway, HTTPS and receipt checks matter before donors can give
    // but do not stop the form being saved as published.
    const blockFail  = useMemo( () => blockChecks.filter( ( c ) => c.status === 'fail' ).length, [ blockChecks ] );
    const serverFail = useMemo( () => ( serverChecks || [] ).filter( ( c ) => c.status === 'fail' ).length, [ serverChecks ] );

    const summaryText = ( () => {
        if ( ! serverChecks && ! error ) return __( 'Running checks…', 'fundkit-fundraising-campaigns' );
        if ( blockFail > 0 ) return sprintf(
            /* translators: %d: number of failing required-field checks that block publishing. */
            _n( '%d issue blocks publishing', '%d issues block publishing', blockFail, 'fundkit-fundraising-campaigns' ),
            blockFail
        );
        if ( serverFail > 0 ) return sprintf(
            /* translators: %d: number of readiness issues to fix before the form can take donations. */
            _n( '%d issue to fix before donors can give', '%d issues to fix before donors can give', serverFail, 'fundkit-fundraising-campaigns' ),
            serverFail
        );
        if ( counts.warn > 0 ) return sprintf(
            /* translators: %d: number of warning readiness checks. */
            _n( '%d thing to review', '%d things to review', counts.warn, 'fundkit-fundraising-campaigns' ),
            counts.warn
        );
        return __( 'Form is ready to publish', 'fundkit-fundraising-campaigns' );
    } )();

    const summaryStatus = counts.fail > 0 ? 'fail' : counts.warn > 0 ? 'warn' : 'pass';

    return (
        <div className="fundkit-form-sidebar">
            <div className="fundkit-form-sidebar__header">
                <h2 className="fundkit-form-sidebar__title">{ __( 'Pre-launch checks', 'fundkit-fundraising-campaigns' ) }</h2>
                <p className={ `fundkit-readiness__summary is-${ summaryStatus }` }>
                    <ReadinessStatusIcon status={ summaryStatus } />
                    <span>{ summaryText }</span>
                </p>
            </div>
            <div className="fundkit-form-sidebar__body">
                { error && (
                    <Notice status="error" isDismissible={ false }>{ error }</Notice>
                ) }
                { visibleChecks.length > 0 ? (
                    <ul className="fundkit-readiness__list">
                        { visibleChecks.map( ( c ) => (
                            <ReadinessRow key={ c.id } check={ c } />
                        ) ) }
                    </ul>
                ) : ( serverChecks && ! error && (
                    <p className="fundkit-readiness__empty">
                        { __( 'Everything looks good. This form is safe to publish.', 'fundkit-fundraising-campaigns' ) }
                    </p>
                ) ) }
            </div>
        </div>
    );
}

function ReadinessRow( { check } ) {
    return (
        <li className={ `fundkit-readiness__row is-${ check.status }` }>
            <ReadinessStatusIcon status={ check.status } />
            <div className="fundkit-readiness__body">
                <div className="fundkit-readiness__label">{ check.label }</div>
                { check.detail && (
                    <div className="fundkit-readiness__detail">{ check.detail }</div>
                ) }
                { check.action_url && check.action_label && (
                    <a
                        className="fundkit-readiness__action"
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
    if ( status === 'pass' ) return <LocalIcon name="check" size={ 16 } className="fundkit-readiness__icon" aria-hidden="true" />;
    if ( status === 'warn' ) return <LocalIcon name="alert" size={ 16 } className="fundkit-readiness__icon" aria-hidden="true" />;
    return <LocalIcon name="close" size={ 16 } className="fundkit-readiness__icon" aria-hidden="true" />;
}

function SidebarIntro( { iconName, title, description } ) {
    return (
        <div className="fundkit-sidebar-intro">
            <span className="fundkit-sidebar-intro__icon" aria-hidden="true">
                <LocalIcon name={ iconName } size={ 18 } />
            </span>
            <div className="fundkit-sidebar-intro__text">
                <h3 className="fundkit-sidebar-intro__title">{ title }</h3>
                <p className="fundkit-sidebar-intro__desc">{ description }</p>
            </div>
        </div>
    );
}

const SETTINGS_TABS = [
    { id: 'general',   label: __( 'General', 'fundkit-fundraising-campaigns' ) },
    { id: 'goal',      label: __( 'Goal', 'fundkit-fundraising-campaigns' ) },
    { id: 'gateways',  label: __( 'Gateways', 'fundkit-fundraising-campaigns' ) },
    { id: 'after',     label: __( 'After donation', 'fundkit-fundraising-campaigns' ) },
    { id: 'embed',     label: __( 'Embed', 'fundkit-fundraising-campaigns' ) },
];

function FormSettingsPanel( { c, campaigns, gateways, funds } ) {
    const settings = useMemo(
        () => mergeFormSettings( c.record.settings ),
        [ c.record.settings ]
    );

    const setSettings = ( patch ) =>
        c.edit( { settings: { ...settings, ...patch } } );

    const [ activeTab, setActiveTab ] = useState( 'general' );

    return (
        <div className="fundkit-form-settings">
            <div className="fundkit-form-settings__nav" role="tablist" aria-label={ __( 'Settings sections', 'fundkit-fundraising-campaigns' ) }>
                { SETTINGS_TABS.map( ( t ) => (
                    <button
                        key={ t.id }
                        type="button"
                        role="tab"
                        aria-selected={ activeTab === t.id }
                        className={ `fundkit-form-settings__nav-item ${ activeTab === t.id ? 'is-active' : '' }` }
                        onClick={ () => setActiveTab( t.id ) }
                    >
                        { t.label }
                    </button>
                ) ) }
            </div>
            <main className="fundkit-form-settings__main">
                { activeTab === 'general'   && <GeneralSection   c={ c } campaigns={ campaigns } funds={ funds } settings={ settings } setSettings={ setSettings } /> }
                { activeTab === 'goal'      && <GoalSection      settings={ settings } setSettings={ setSettings } /> }
                { activeTab === 'gateways'  && <GatewaysSection  gateways={ gateways } settings={ settings } setSettings={ setSettings } /> }
                { activeTab === 'after'     && <AfterSection     settings={ settings } setSettings={ setSettings } /> }
                { activeTab === 'embed'     && <EmbedSection     slug={ c.value( 'slug' ) } /> }
            </main>
        </div>
    );
}

function SettingsRow( { title, description, children } ) {
    return (
        <section className="fundkit-form-settings__row">
            <header className="fundkit-form-settings__row-head">
                <h3 className="fundkit-form-settings__row-title">{ title }</h3>
                { description && <p className="fundkit-form-settings__row-desc">{ description }</p> }
            </header>
            <div className="fundkit-form-settings__row-body">{ children }</div>
        </section>
    );
}

function fundSelectOptions( funds ) {
    const out = [ { value: '0', label: __( '(Use campaign or org default)', 'fundkit-fundraising-campaigns' ) } ];
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
                title={ __( 'Identity', 'fundkit-fundraising-campaigns' ) }
                description={ __( 'The form name and the slug used in the URL and shortcode.', 'fundkit-fundraising-campaigns' ) }
            >
                <TextControl
                    label={ __( 'Title', 'fundkit-fundraising-campaigns' ) }
                    value={ c.value( 'title' ) }
                    onChange={ c.setValue( 'title' ) }
                    __nextHasNoMarginBottom
                />
                <TextControl
                    label={ __( 'Slug', 'fundkit-fundraising-campaigns' ) }
                    value={ c.value( 'slug' ) }
                    onChange={ c.setValue( 'slug' ) }
                    help={ __( 'Used in the shortcode and the form URL.', 'fundkit-fundraising-campaigns' ) }
                    __nextHasNoMarginBottom
                />
            </SettingsRow>

            <SettingsRow
                title={ __( 'Status', 'fundkit-fundraising-campaigns' ) }
                description={ __( 'Use the Publish button in the header to go live. Archived forms stay in the system but stop accepting donations.', 'fundkit-fundraising-campaigns' ) }
            >
                <SelectControl
                    value={ c.value( 'status', 'draft' ) }
                    options={ ( () => {
                        // Publishing flows only through the gated Publish
                        // button, so the readiness checks cannot be bypassed
                        // here. "Published" is listed when the form already is
                        // one, so the state stays visible.
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
                title={ __( 'Campaign', 'fundkit-fundraising-campaigns' ) }
                description={ __( 'Every form lives under a campaign. Move this form to a different one here.', 'fundkit-fundraising-campaigns' ) }
            >
                <SelectControl
                    value={ String( c.value( 'campaign_id', 0 ) || 0 ) }
                    options={ ( () => {
                        const opts = campaigns.map( ( cmp ) => ( {
                            value: String( cmp.id ),
                            label: cmp.title,
                        } ) );
                        // listForPicker is published/draft only and capped at
                        // 200, so a form under an archived or older campaign
                        // has no option of its own and the select would show
                        // somebody else's campaign as this form's.
                        const current = String( c.value( 'campaign_id', 0 ) || 0 );
                        if ( current !== '0' && ! opts.some( ( o ) => o.value === current ) ) {
                            opts.unshift( {
                                value: current,
                                label: c.value( 'campaign', null )?.title
                                    || __( 'Current campaign', 'fundkit-fundraising-campaigns' ),
                            } );
                        }
                        return opts;
                    } )() }
                    onChange={ ( v ) => {
                        const next = Number( v );
                        if ( next > 0 ) c.edit( { campaign_id: next } );
                    } }
                    __nextHasNoMarginBottom
                />
            </SettingsRow>

            <SettingsRow
                title={ __( 'Default fund', 'fundkit-fundraising-campaigns' ) }
                description={ __( 'Where donations land when this form has no fund picker, or the donor does not choose one.', 'fundkit-fundraising-campaigns' ) }
            >
                <SelectControl
                    value={ String( c.value( 'default_fund_id', 0 ) || 0 ) }
                    options={ fundSelectOptions( funds ) }
                    onChange={ ( v ) => c.edit( { default_fund_id: Number( v ) || null } ) }
                    help={ __( 'Leave on the default to fall back to the campaign fund, then the organization default.', 'fundkit-fundraising-campaigns' ) }
                    __nextHasNoMarginBottom
                />
            </SettingsRow>

            <SettingsRow
                title={ __( 'Layout & style', 'fundkit-fundraising-campaigns' ) }
                description={ __( 'How the form is presented: its layout, style preset, width, and whether it sits in a card.', 'fundkit-fundraising-campaigns' ) }
            >
                <SelectControl
                    label={ __( 'Layout', 'fundkit-fundraising-campaigns' ) }
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
                    label={ __( 'Maximum width', 'fundkit-fundraising-campaigns' ) }
                    value={ settings.container?.width ?? 540 }
                    onChange={ ( v ) => setSettings( { container: { ...settings.container, width: v } } ) }
                    min={ 320 }
                    max={ 1200 }
                    unit="px"
                />
                <Segmented
                    label={ __( 'Container', 'fundkit-fundraising-campaigns' ) }
                    value={ settings.container?.style ?? 'plain' }
                    onChange={ ( v ) => setSettings( { container: { ...settings.container, style: v } } ) }
                    options={ [
                        { value: 'frame', label: __( 'Frame', 'fundkit-fundraising-campaigns' ) },
                        { value: 'plain', label: __( 'Plain', 'fundkit-fundraising-campaigns' ) },
                    ] }
                    help={ __( '"Frame" wraps the form in a card with a shadow; "Plain" renders it flush with the page.', 'fundkit-fundraising-campaigns' ) }
                />
            </SettingsRow>
        </>
    );
}

const GOAL_TYPE_OPTIONS = [
    { value: 'none',      label: __( 'No goal', 'fundkit-fundraising-campaigns' ) },
    { value: 'amount',    label: __( 'Amount', 'fundkit-fundraising-campaigns' ) },
    { value: 'donations', label: __( 'Donations', 'fundkit-fundraising-campaigns' ) },
    { value: 'donors',    label: __( 'Donors', 'fundkit-fundraising-campaigns' ) },
];

const GOAL_TYPE_DESC = {
    none:      __( 'No progress bar or target on this form.', 'fundkit-fundraising-campaigns' ),
    amount:    __( 'Track progress toward a fundraising total.', 'fundkit-fundraising-campaigns' ),
    donations: __( 'Track the number of completed donations to this form.', 'fundkit-fundraising-campaigns' ),
    donors:    __( 'Track the number of unique donors who give through this form.', 'fundkit-fundraising-campaigns' ),
};

function GoalSection( { settings, setSettings } ) {
    const goal = settings.goal || { type: 'none', amount_cents: 0, count: 0 };

    return (
        <SettingsRow
            title={ __( 'Form goal', 'fundkit-fundraising-campaigns' ) }
            description={ __( 'An optional goal tracked for this form alone. The Goal block can show this or the parent campaign goal.', 'fundkit-fundraising-campaigns' ) }
        >
            <SelectControl
                label={ __( 'Goal type', 'fundkit-fundraising-campaigns' ) }
                value={ goal.type }
                options={ GOAL_TYPE_OPTIONS }
                onChange={ ( type ) => setSettings( { goal: { type, amount_cents: 0, count: 0 } } ) }
                help={ GOAL_TYPE_DESC[ goal.type ] }
                __nextHasNoMarginBottom
            />
            { goal.type === 'amount' && (
                <BaseControl
                    id="fundkit-form-goal-amount"
                    label={ __( 'Target amount', 'fundkit-fundraising-campaigns' ) }
                    help={ __( 'In the currency this form uses.', 'fundkit-fundraising-campaigns' ) }
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
                        inputProps={ { id: 'fundkit-form-goal-amount' } }
                    />
                </BaseControl>
            ) }
            { ( goal.type === 'donations' || goal.type === 'donors' ) && (
                <TextControl
                    label={ __( 'Target count', 'fundkit-fundraising-campaigns' ) }
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
// Still listed and still tickable: what a form allows is the author's answer,
// and Settings switching a gateway off is a separate one that can be undone.
function gatewayLabel( g ) {
    if ( g.enabled !== false ) return g.label;

    /* translators: %s: payment gateway name. */
    return sprintf( __( '%s (off in Settings)', 'fundkit-fundraising-campaigns' ), g.label );
}

function GatewaysSection( { gateways, settings, setSettings } ) {
    // FormService::syncGatewayAllowed makes the payment-gateways block the sole
    // writer of the allowed list on save, so when the form carries one these
    // checkboxes edit the block rather than the setting. Writing to settings
    // here would be overwritten by the block on the next save.
    const block = useSelect( ( s ) => {
        const store = s( 'core/block-editor' );
        const id = store
            .getClientIdsWithDescendants()
            .find( ( cid ) => store.getBlockName( cid ) === 'fundkit/payment-gateways' );

        return id ? { clientId: id, allowed: store.getBlockAttributes( id )?.allowed || [] } : null;
    }, [] );
    const { updateBlockAttributes } = useDispatch( 'core/block-editor' );

    const allowed = block ? block.allowed : ( settings.gateways.allowed || [] );

    const toggleGateway = ( id ) => {
        const next = toggleGatewayAllowed( allowed, id, gateways.map( ( g ) => g.id ) );

        if ( block ) {
            updateBlockAttributes( block.clientId, { allowed: next } );
            return;
        }
        setSettings( { gateways: { ...settings.gateways, allowed: next } } );
    };
    return (
        <SettingsRow
            title={ __( 'Allowed gateways', 'fundkit-fundraising-campaigns' ) }
            description={ __( 'Pick which payment gateways are offered on this form. Leave empty to allow every gateway configured in Settings.', 'fundkit-fundraising-campaigns' ) }
        >
            <div className="fundkit-sidebar-list">
                { gateways.map( ( g ) => (
                    <label key={ g.id } className="fundkit-sidebar-check">
                        <input
                            type="checkbox"
                            checked={ gatewayIsOn( allowed, g.id ) }
                            onChange={ () => toggleGateway( g.id ) }
                        />
                        <span>{ gatewayLabel( g ) }</span>
                    </label>
                ) ) }
            </div>
            <label className="fundkit-sidebar-check" style={ { marginTop: 14 } }>
                <input
                    type="checkbox"
                    checked={ !! settings.test_mode }
                    onChange={ () => setSettings( { test_mode: ! settings.test_mode } ) }
                />
                <span>{ __( 'Test mode (no real payment, excluded from reporting)', 'fundkit-fundraising-campaigns' ) }</span>
            </label>
        </SettingsRow>
    );
}

function AfterSection( { settings, setSettings } ) {
    return (
        <>
            <SettingsRow
                title={ __( 'Thank-you message', 'fundkit-fundraising-campaigns' ) }
                description={ __( 'Shown to the donor after a successful donation, unless a redirect URL is set.', 'fundkit-fundraising-campaigns' ) }
            >
                <TextareaControl
                    value={ settings.thank_you_message }
                    onChange={ ( v ) => setSettings( { thank_you_message: v } ) }
                    rows={ 3 }
                    __nextHasNoMarginBottom
                />
            </SettingsRow>
            <SettingsRow
                title={ __( 'Redirect URL', 'fundkit-fundraising-campaigns' ) }
                description={ __( 'If set, donors are sent here instead of seeing the thank-you message.', 'fundkit-fundraising-campaigns' ) }
            >
                <TextControl
                    type="url"
                    value={ settings.redirect_url }
                    onChange={ ( v ) => setSettings( { redirect_url: v } ) }
                    placeholder="https://"
                    help={
                        settings.redirect_url && ! /^https?:\/\//i.test( settings.redirect_url.trim() )
                            ? __( 'Use a full URL starting with http:// or https://', 'fundkit-fundraising-campaigns' )
                            : undefined
                    }
                    __nextHasNoMarginBottom
                />
            </SettingsRow>
        </>
    );
}

function EmbedSection( { slug } ) {
    const shortcode = `[fundkit_donation_form slug="${ slug }"]`;
    return (
        <SettingsRow
            title={ __( 'Embed', 'fundkit-fundraising-campaigns' ) }
            description={ __( 'Paste this shortcode into any post or page to render the form.', 'fundkit-fundraising-campaigns' ) }
        >
            <ShortcodeField value={ shortcode } />
        </SettingsRow>
    );
}

function StylePresetField( { value, onChange } ) {
    const presets   = Array.isArray( window.fundkit?.styling?.presets ) ? window.fundkit.styling.presets : [];
    const defaultId = String( window.fundkit?.styling?.default_id || '' );
    const defaultName = presets.find( ( p ) => p.id === defaultId )?.name || defaultId;
    return (
        <SelectControl
            label={ __( 'Style preset', 'fundkit-fundraising-campaigns' ) }
            value={ value }
            options={ [
                {
                    value: '',
                    label: __( 'Inherit (campaign or org default)', 'fundkit-fundraising-campaigns' ) +
                        ( defaultName ? ` (${ defaultName })` : '' ),
                },
                ...presets.map( ( p ) => ( { value: p.id, label: p.name } ) ),
            ] }
            onChange={ onChange }
            help={ __( 'Picks one of the presets defined in Settings → Brand. Leave on Inherit to follow the campaign\'s choice.', 'fundkit-fundraising-campaigns' ) }
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
            // No clipboard access; the value stays visible to copy by hand.
        }
    };

    return (
        <div className="fundkit-shortcode">
            <code className="fundkit-shortcode__code">{ value }</code>
            <Button
                variant="secondary"
                size="small"
                onClick={ onCopy }
                className="fundkit-shortcode__copy"
            >
                { copied ? __( 'Copied', 'fundkit-fundraising-campaigns' ) : __( 'Copy', 'fundkit-fundraising-campaigns' ) }
            </Button>
        </div>
    );
}

