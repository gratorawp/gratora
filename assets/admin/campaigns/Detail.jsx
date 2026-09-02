import { useState, useEffect, useCallback, useRef, useMemo, Fragment } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { DataViews } from '@wordpress/dataviews';
import { Modal, Spinner, Button, CheckboxControl } from '@wordpress/components';
import Notice from '../_shared/components/Notice';
import ConfirmDialog from '../_shared/components/ConfirmDialog';
import { notify } from '../_shared/notify';
import { __, _n, sprintf } from '@wordpress/i18n';

import { useFundKitRecord } from '../_shared/useFundKitRecord';
import { rowLinkProps, stopRowSelect } from '../_shared/rowLink';
import { StatusBadge, STATUS_LABEL, formatAmount, defaultCurrency, formatDate, timeAgo, listHref, detailHref, formEditorHref } from '../_shared/format';
import Card from '../_shared/components/Card';
import FormRow from '../_shared/components/FormRow';
import { ToggleRow } from '../_shared/components/Switch';
import Btn from '../_shared/components/Btn';
import { downloadFile } from '../_shared/download';
import TokenEditor from '../_shared/styling/TokenEditor';
import { Copy as CopyIcon, Trash2 as TrashIcon, Coins, HandHeart, Users as UsersIcon, ListChecks, Plus, Download as DownloadIcon } from 'lucide-react';
import EmptyState from '../_shared/components/EmptyState';
import FormTemplatePicker from '../_shared/components/FormTemplatePicker';
import { GoalCell } from '../_shared/components/GoalBar';
import { IconCoins, IconHeart, IconUsers, IconActivity } from './icons';
import { IconGeneral, IconGoal, IconAppearance, IconDefaults, IconAdvanced } from './settings-icons';
import { useExtensionTabs, ExtensionTabPanel } from '../_shared/extensionTabs';
import StylePreview, { resolveEffectiveTokens } from '../_shared/styling/StylePreview';
import ScheduleTimeline from './ScheduleTimeline';
import CoverImageCard from './CoverImageCard';
import AmbitionMeter from './AmbitionMeter';
import AmountInput from '../_shared/components/AmountInput';

import WidgetGrid from '../_shared/widgets/WidgetGrid';
import LayoutControls from '../_shared/widgets/LayoutControls';
import SectionBar from '../_shared/widgets/SectionBar';
import MetricCard from '../_shared/widgets/MetricCard';
import { useFundKitLayout } from '../_shared/widgets/useFundKitLayout';
import RevenueChart from '../_shared/widgets/RevenueChart';
import ChannelBreakdown from '../_shared/widgets/ChannelBreakdown';
import Stories from './widgets/Stories';
import DonorCohort from './widgets/DonorCohort';
import DistributionHistogram from './widgets/DistributionHistogram';
import DowHourHeatmap from './widgets/DowHourHeatmap';

const DESCRIPTION_MAX = 120;

const TABS = [ 'overview', 'forms', 'settings' ];

// Names everything the cascade destroys, so the blast radius is on screen
// before the admin confirms.
export async function campaignDeleteMessage( campaign ) {
    let count = 0;
    try {
        const res = await apiFetch( {
            path:  `/fundkit/v1/admin/forms?campaign_id=${ campaign?.id }&per_page=1`,
            parse: false,
        } );
        count = Number( res.headers.get( 'x-wp-total' ) ) || 0;
    } catch {
        // A failed probe falls back to the generic message rather than blocking
        // the delete.
    }

    // No line about donations: a campaign that has any is refused outright, so
    // by the time this message is shown there are none to keep or to unlink.
    const parts = [ count > 0
        ? sprintf(
            /* translators: %d: number of forms attached to the campaign. */
            _n(
                'Permanently delete this campaign? Its %d form will also be deleted.',
                'Permanently delete this campaign? Its %d forms will also be deleted.',
                count,
                'fundkit-fundraising-campaigns'
            ),
            count,
        )
        : __( 'Permanently delete this campaign?', 'fundkit-fundraising-campaigns' ) ];

    // The page is deleted outright rather than trashed, so an admin who has
    // built it out in the block editor has nothing left to restore from.
    if ( campaign?.page_id ) {
        parts.push( __( 'The WordPress page it created is deleted with it, and it does not go to the trash, so any content you built on that page is gone for good.', 'fundkit-fundraising-campaigns' ) );
    }

    parts.push( __( 'This cannot be undone.', 'fundkit-fundraising-campaigns' ) );

    return parts.join( ' ' );
}

// The reason comes from the server, which reads it off the same rule the
// donation gate uses. Deriving it here from status and dates would be a second
// answer to the question, free to disagree with the one that matters.
/**
 * The sentence for one not_accepting reason.
 *
 * A reason with no copy gets the plain truth rather than another reason's
 * sentence. This fell back to the draft line, so a published campaign that met
 * its goal was told it was a draft, and every reason added later would have
 * been told the same.
 */
export function notAcceptingMessage( reason ) {
    const COPY = {
        draft:     __( 'This campaign is a draft, so it is not taking donations yet. Anyone who opens its form is turned away.', 'fundkit-fundraising-campaigns' ),
        archived:  __( 'This campaign is archived and is not taking donations.', 'fundkit-fundraising-campaigns' ),
        scheduled: __( 'This campaign has not started yet, so it is not taking donations until its start date.', 'fundkit-fundraising-campaigns' ),
        ended:     __( 'This campaign has ended and is no longer taking donations.', 'fundkit-fundraising-campaigns' ),
        goal_met:  __( 'This campaign has reached its goal and is set to close when it does, so it is no longer taking donations. Raise the target or turn that setting off in Goal to reopen it.', 'fundkit-fundraising-campaigns' ),
    };

    return COPY[ reason ] || __( 'This campaign is not taking donations.', 'fundkit-fundraising-campaigns' );
}

function NotAcceptingNotice( { campaign, onPublish } ) {
    const reason = campaign?.not_accepting;
    if ( ! reason ) return null;

    return (
        <Notice status={ reason === 'goal_met' ? 'success' : 'warning' } isDismissible={ false }>
            { notAcceptingMessage( reason ) }
            { reason === 'draft' && (
                <>
                    { ' ' }
                    <Button variant="link" onClick={ onPublish }>
                        { __( 'Publish it now', 'fundkit-fundraising-campaigns' ) }
                    </Button>
                </>
            ) }
        </Notice>
    );
}

export default function Detail( { id, tab } ) {
    const c = useFundKitRecord( 'campaign', id );
    const [ error, setError ]   = useState( null );
    const [ confirm, setConfirm ] = useState( null );
    const [ archivePrompt, setArchivePrompt ] = useState( null );
    const [ cancelSubs, setCancelSubs ]       = useState( false );
    const extTabsAll = useExtensionTabs( 'campaign' );

    // One-shot toast after the duplicate action redirects here; the query arg is
    // stripped once read.
    useEffect( () => {
        const params = new URLSearchParams( window.location.search );
        const from = params.get( 'duplicated_from' );
        if ( ! from ) return;
        notify.success( sprintf(
            /* translators: %s: source campaign title */
            __( 'Duplicated from "%s". Review and rename before publishing.', 'fundkit-fundraising-campaigns' ),
            from,
        ) );
        params.delete( 'duplicated_from' );
        const url = new URL( window.location.href );
        url.search = params.toString();
        window.history.replaceState( {}, '', url.toString() );
    }, [] );

    if ( c.isLoading || ( ! c.savedRecord && ! c.notFound ) ) {
        return <div style={ { padding: 40, textAlign: 'center' } }><Spinner /></div>;
    }
    if ( c.notFound ) {
        return (
            <Notice status="error" isDismissible={ false }>
                { __( 'Campaign not found.', 'fundkit-fundraising-campaigns' ) }
            </Notice>
        );
    }

    const campaign = c.record;

    const extTabs = extTabsAll.filter(
        ( t ) => ( typeof t.visible === 'function' ? t.visible( campaign ) : true )
    );
    const activeTab = [ ...TABS, ...extTabs.map( ( t ) => t.id ) ].includes( tab ) ? tab : 'overview';

    const runArchive = async ( nextStatus, cancelRecurring ) => {
        try {
            const res = await apiFetch( {
                path: `/fundkit/v1/admin/campaigns/${ campaign.id }`,
                method: 'PUT',
                data: { status: nextStatus, ...( cancelRecurring ? { cancel_recurring: true } : {} ) },
            } );
            // Cancellation runs in the background: each plan is a gateway round
            // trip and a campaign can have thousands, so the request cannot
            // know a failure count yet.
            const queued = res?.recurring_cancel?.queued || 0;
            if ( queued > 0 ) {
                notify.success( sprintf(
                    /* translators: %d: number of subscriptions */
                    _n(
                        'Campaign archived. Cancelling %d subscription in the background.',
                        'Campaign archived. Cancelling %d subscriptions in the background.',
                        queued,
                        'fundkit-fundraising-campaigns'
                    ),
                    queued
                ) );
                window.location.reload();
                return;
            }
            notify.success( nextStatus === 'archived'
                ? __( 'Campaign archived.', 'fundkit-fundraising-campaigns' )
                : __( 'Campaign restored to draft.', 'fundkit-fundraising-campaigns' ) );
            window.location.reload();
        } catch ( err ) {
            setError( err?.message || __( 'Update failed.', 'fundkit-fundraising-campaigns' ) );
        }
    };

    const onHeaderAction = async ( name ) => {
        if ( name === 'duplicate' ) {
            try {
                const dup = await apiFetch( {
                    path: `/fundkit/v1/admin/campaigns/${ campaign.id }/duplicate`,
                    method: 'POST',
                } );
                if ( dup?.id ) {
                    const url = addQueryArgs( detailHref( dup.id, 'settings' ), {
                        duplicated_from: campaign.title,
                    } );
                    window.location.href = url;
                }
            } catch ( err ) {
                setError( err?.message || __( 'Duplicate failed.', 'fundkit-fundraising-campaigns' ) );
            }
            return;
        }
        if ( name === 'unarchive' ) {
            await runArchive( 'draft', false );
            return;
        }
        if ( name === 'archive' ) {
            try {
                const summary = await apiFetch( {
                    path: `/fundkit/v1/admin/campaigns/${ campaign.id }/recurring-summary`,
                } );
                if ( summary?.count > 0 ) {
                    setCancelSubs( false );
                    setArchivePrompt( summary );
                    return;
                }
            } catch ( e ) {
                // A failed check falls through to a plain archive.
            }
            await runArchive( 'archived', false );
            return;
        }
        if ( name === 'publish' || name === 'unpublish' ) {
            const nextStatus = name === 'publish' ? 'published' : 'draft';
            try {
                await apiFetch( {
                    path: `/fundkit/v1/admin/campaigns/${ campaign.id }`,
                    method: 'PUT',
                    data: { status: nextStatus },
                } );
                notify.success( name === 'publish'
                    ? __( 'Campaign published.', 'fundkit-fundraising-campaigns' )
                    : __( 'Campaign moved to draft.', 'fundkit-fundraising-campaigns' ) );
                window.location.reload();
            } catch ( err ) {
                setError( err?.message || __( 'Update failed.', 'fundkit-fundraising-campaigns' ) );
            }
            return;
        }
        if ( name === 'delete' ) {
            // The gate's own reason, not one worked out here from
            // donations_count: that counter ignores pending, failed, test-mode
            // and ticket rows, every one of which still blocks a delete.
            if ( campaign.delete_blocked ) {
                setConfirm( {
                    title:        __( 'This campaign cannot be deleted', 'fundkit-fundraising-campaigns' ),
                    message:      campaign.delete_blocked,
                    confirmLabel: __( 'Archive instead', 'fundkit-fundraising-campaigns' ),
                    onConfirm:    () => onHeaderAction( 'archive' ),
                } );
                return;
            }

            const message = await campaignDeleteMessage( campaign );
            setConfirm( {
                title:        __( 'Delete campaign', 'fundkit-fundraising-campaigns' ),
                message,
                confirmLabel: __( 'Delete', 'fundkit-fundraising-campaigns' ),
                destructive:  true,
                onConfirm: async () => {
                    try {
                        await apiFetch( {
                            path: `/fundkit/v1/admin/campaigns/${ campaign.id }`,
                            method: 'DELETE',
                        } );
                        window.location.href = listHref();
                    } catch ( err ) {
                        setError( err?.message || __( 'Delete failed.', 'fundkit-fundraising-campaigns' ) );
                    }
                },
            } );
        }
    };

    return (
        <div className="fundkit-campaign-detail">
            <Header campaign={ campaign } />

            <NotAcceptingNotice campaign={ campaign } onPublish={ () => onHeaderAction( 'publish' ) } />

            { error && (
                <Notice status="error" onRemove={ () => setError( null ) }>{ error }</Notice>
            ) }

            <div className="fundkit-campaign-detail__body">
                { activeTab === 'overview' && (
                    <OverviewTab
                        campaign={ campaign }
                        nav={ <DetailNav campaign={ campaign } activeTab={ activeTab } extraTabs={ extTabs } onAction={ onHeaderAction } /> }
                        onError={ setError }
                    />
                ) }
                { activeTab === 'forms' && (
                    <>
                        <DetailNav campaign={ campaign } activeTab={ activeTab } extraTabs={ extTabs } onAction={ onHeaderAction } />
                        <FormsTab campaign={ campaign } />
                    </>
                ) }
                { activeTab === 'settings' && (
                    <>
                        <DetailNav campaign={ campaign } activeTab={ activeTab } extraTabs={ extTabs } onAction={ onHeaderAction } />
                        <SettingsTab
                            campaign={ campaign }
                            onError={ setError }
                        />
                    </>
                ) }
                { extTabs.map( ( t ) => (
                    activeTab === t.id && (
                        <Fragment key={ t.id }>
                            <DetailNav campaign={ campaign } activeTab={ activeTab } extraTabs={ extTabs } onAction={ onHeaderAction } />
                            <ExtensionTabPanel tab={ t } context={ { campaign } } />
                        </Fragment>
                    )
                ) ) }
            </div>

            <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />

            { archivePrompt && (
                <Modal
                    title={ __( 'Archive campaign', 'fundkit-fundraising-campaigns' ) }
                    onRequestClose={ () => setArchivePrompt( null ) }
                >
                    <p style={ { marginTop: 0 } }>
                        { sprintf(
                            /* translators: %d: number of live recurring donations */
                            _n(
                                'This campaign has %d live recurring donation.',
                                'This campaign has %d live recurring donations.',
                                archivePrompt.count,
                                'fundkit-fundraising-campaigns'
                            ),
                            archivePrompt.count
                        ) }
                        { ' ' }
                        { __( 'Live counts active, paused and past-due donations: a paused one resumes and a past-due one is still being retried.', 'fundkit-fundraising-campaigns' ) }
                        { archivePrompt.mrr_cents > 0 && ' ' + sprintf(
                            /* translators: %s: formatted monthly amount */
                            __( 'About %s a month is at stake.', 'fundkit-fundraising-campaigns' ),
                            formatAmount( archivePrompt.mrr_cents, archivePrompt.currency )
                        ) }
                    </p>
                    <p>
                        { __( 'Archiving stops new donations. These subscriptions are left as they are and stay credited to this campaign unless you cancel them.', 'fundkit-fundraising-campaigns' ) }
                    </p>
                    <CheckboxControl
                        label={ __( 'Also cancel these subscriptions (donors will be emailed)', 'fundkit-fundraising-campaigns' ) }
                        checked={ cancelSubs }
                        onChange={ setCancelSubs }
                        __nextHasNoMarginBottom
                    />
                    <div style={ { display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 20 } }>
                        <Button variant="tertiary" onClick={ () => setArchivePrompt( null ) }>
                            { __( 'Cancel', 'fundkit-fundraising-campaigns' ) }
                        </Button>
                        <Button
                            variant="primary"
                            isDestructive={ cancelSubs }
                            onClick={ () => {
                                setArchivePrompt( null );
                                runArchive( 'archived', cancelSubs );
                            } }
                        >
                            { __( 'Archive campaign', 'fundkit-fundraising-campaigns' ) }
                        </Button>
                    </div>
                </Modal>
            ) }
        </div>
    );
}

function Header( { campaign } ) {
    const lastSaved = useLastSavedLabel( campaign.updated_at );

    return (
        <>
            <div className="fundkit-crumbs">
                <a href={ listHref() }>{ __( 'Campaigns', 'fundkit-fundraising-campaigns' ) }</a>
                <span className="sep">›</span>
                <span>{ campaign.title }</span>
            </div>
            <div className="fundkit-page-head">
                <div className="fundkit-page-head__left">
                    <h1>{ campaign.title }</h1>
                    <div className="fundkit-page-head__meta-row">
                        { campaign.campaign_type && campaign.campaign_type !== 'standard' && campaign.campaign_type_label && (
                            <span className="fundkit-pill fundkit-pill--lg fundkit-pill--type">
                                { campaign.campaign_type_label }
                            </span>
                        ) }
                        <span className={ `fundkit-pill fundkit-pill--lg ${ statusPillClass( campaign.status ) }` }>
                            { STATUS_LABEL[ campaign.status ] || campaign.status }
                        </span>
                        { lastSaved && <span className="fundkit-page-head__meta">{ lastSaved }</span> }
                    </div>
                </div>
            </div>
        </>
    );
}

function IconOverview( props ) {
    return (
        <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" { ...props }>
            <path d="M3 13V6h2v7zM7 13V3h2v10zM11 13V8h2v5z" fill="currentColor" />
        </svg>
    );
}
function IconFormsTab( props ) {
    return (
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" { ...props }>
            <rect x="3" y="3" width="18" height="18" rx="2" />
            <path d="M8 8h8M8 12h8M8 16h5" />
        </svg>
    );
}
function IconSettingsTab( props ) {
    return (
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" { ...props }>
            <circle cx="12" cy="12" r="3" />
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
        </svg>
    );
}

const VIEW_TOGGLE_DEFS = [
    { id: 'overview', label: __( 'Overview', 'fundkit-fundraising-campaigns' ), Icon: IconOverview },
    { id: 'forms',    label: __( 'Forms',    'fundkit-fundraising-campaigns' ), Icon: IconFormsTab },
    { id: 'settings', label: __( 'Settings', 'fundkit-fundraising-campaigns' ), Icon: IconSettingsTab },
];

function ViewToggle( { active, campaignId, extra = [] } ) {
    const views = [ ...VIEW_TOGGLE_DEFS, ...extra ];
    return (
        <div className="fundkit-view-toggle" role="tablist" aria-label={ __( 'Campaign sections', 'fundkit-fundraising-campaigns' ) }>
            { views.map( ( t ) => (
                <a
                    key={ t.id }
                    role="tab"
                    aria-selected={ active === t.id }
                    href={ detailHref( campaignId, t.id ) }
                    className={ `fundkit-cmp-toggle${ active === t.id ? ' is-active' : '' }` }
                >
                    { t.Icon ? <t.Icon /> : null }
                    { t.label }
                    { !! t.badge && <span className="fundkit-cmp-toggle__badge">{ t.badge }</span> }
                </a>
            ) ) }
        </div>
    );
}

function DetailNav( { campaign, activeTab, onAction, extraTabs = [] } ) {
    return (
        <div className="fundkit-detail-nav">
            <ViewToggle active={ activeTab } campaignId={ campaign.id } extra={ extraTabs } />
            { campaign.page_edit_url && ( ! campaign.campaign_type || campaign.campaign_type === 'standard' ) && (
                // Non-standard types (e.g. peer-to-peer) manage their pages from
                // their own admin tab, so the single-page edit link is redundant.
                <Btn href={ campaign.page_edit_url }>{ __( 'Edit campaign page', 'fundkit-fundraising-campaigns' ) }</Btn>
            ) }
            { campaign.page_url && (
                <Btn variant="ghost" href={ campaign.page_url } target="_blank" rel="noreferrer">
                    { __( 'View page ↗', 'fundkit-fundraising-campaigns' ) }
                </Btn>
            ) }
            <HeaderMenu campaign={ campaign } onAction={ onAction } />
        </div>
    );
}

function statusPillClass( status ) {
    switch ( status ) {
        case 'published': return 'is-ok';
        case 'draft':     return 'is-muted';
        case 'archived':  return 'is-warn';
        default:          return 'is-muted';
    }
}

function HeaderMenu( { campaign, onAction } ) {
    const [ open, setOpen ] = useState( false );
    const ref = useRef( null );
    const triggerRef = useRef( null );

    useEffect( () => {
        if ( ! open ) return undefined;
        const close = ( e ) => { if ( ref.current && ! ref.current.contains( e.target ) ) setOpen( false ); };
        document.addEventListener( 'mousedown', close );
        return () => document.removeEventListener( 'mousedown', close );
    }, [ open ] );

    const fire = ( name ) => { setOpen( false ); triggerRef.current?.focus(); onAction?.( name ); };
    const isArchived  = campaign.status === 'archived';
    const isDraft     = campaign.status === 'draft';
    const isPublished = campaign.status === 'published';

    const onKeyDown = ( e ) => {
        if ( ! open ) return;
        if ( e.key === 'Escape' ) {
            setOpen( false );
            triggerRef.current?.focus();
            return;
        }
        if ( e.key !== 'ArrowDown' && e.key !== 'ArrowUp' ) return;
        e.preventDefault();
        const items = Array.from( ref.current?.querySelectorAll( '.fundkit-menu__item' ) || [] );
        if ( ! items.length ) return;
        const idx  = items.indexOf( ref.current.ownerDocument.activeElement );
        const next = e.key === 'ArrowDown'
            ? ( idx + 1 ) % items.length
            : ( idx <= 0 ? items.length - 1 : idx - 1 );
        items[ next ].focus();
    };

    return (
        // eslint-disable-next-line jsx-a11y/no-static-element-interactions -- menu-widget wrapper; onKeyDown coordinates roving focus among the role=menuitem buttons in the popup below
        <div className="fundkit-menu" ref={ ref } onKeyDown={ onKeyDown }>
            <button
                type="button"
                ref={ triggerRef }
                className="fundkit-menu__trigger"
                aria-label={ __( 'Campaign actions', 'fundkit-fundraising-campaigns' ) }
                aria-haspopup="menu"
                aria-expanded={ open }
                onClick={ () => setOpen( ( v ) => ! v ) }
            >
                ⋯
            </button>
            { open && (
                <div className="fundkit-menu__list" role="menu">
                    { isDraft && (
                        <button type="button" role="menuitem" className="fundkit-menu__item is-primary" onClick={ () => fire( 'publish' ) }>
                            { __( 'Publish campaign', 'fundkit-fundraising-campaigns' ) }
                        </button>
                    ) }
                    { isPublished && (
                        <button type="button" role="menuitem" className="fundkit-menu__item" onClick={ () => fire( 'unpublish' ) }>
                            { __( 'Move to draft', 'fundkit-fundraising-campaigns' ) }
                        </button>
                    ) }
                    <button type="button" role="menuitem" className="fundkit-menu__item" onClick={ () => fire( 'duplicate' ) }>
                        { __( 'Duplicate campaign', 'fundkit-fundraising-campaigns' ) }
                    </button>
                    <button type="button" role="menuitem" className="fundkit-menu__item" onClick={ () => fire( isArchived ? 'unarchive' : 'archive' ) }>
                        { isArchived ? __( 'Restore to draft', 'fundkit-fundraising-campaigns' ) : __( 'Archive campaign', 'fundkit-fundraising-campaigns' ) }
                    </button>
                    <button type="button" role="menuitem" className="fundkit-menu__item is-danger" onClick={ () => fire( 'delete' ) }>
                        { __( 'Delete…', 'fundkit-fundraising-campaigns' ) }
                    </button>
                </div>
            ) }
        </div>
    );
}

function inputCls( c, key, extra = '' ) {
    return `fundkit-input${ c.isEdited( key ) ? ' fundkit-input--edited' : '' }${ extra ? ' ' + extra : '' }`;
}
function textareaCls( c, key, extra = '' ) {
    return `fundkit-textarea${ c.isEdited( key ) ? ' fundkit-textarea--edited' : '' }${ extra ? ' ' + extra : '' }`;
}
function selectCls( c, key ) {
    return `fundkit-select${ c.isEdited( key ) ? ' fundkit-input--edited' : '' }`;
}

function formatCardNames( names ) {
    if ( names.length === 0 ) return '';
    if ( names.length <= 3 ) return names.join( ', ' );
    return sprintf(
        /* translators: 1: first three section names, 2: count of remaining */
        __( '%1$s, and %2$d more', 'fundkit-fundraising-campaigns' ),
        names.slice( 0, 3 ).join( ', ' ),
        names.length - 3,
    );
}

function useLastSavedLabel( updatedAt ) {
    // Tick every 30s so timeAgo() stays current.
    const [ , setTick ] = useState( 0 );
    useEffect( () => {
        const id = setInterval( () => setTick( ( v ) => v + 1 ), 30 * 1000 );
        return () => clearInterval( id );
    }, [] );
    if ( ! updatedAt ) return null;
    return sprintf(
        /* translators: %s: relative time ago, e.g. "4m ago" */
        __( 'Last saved %s', 'fundkit-fundraising-campaigns' ),
        timeAgo( updatedAt ),
    );
}


// Order is the default layout, so a new key appended here lands at the end.
const WIDGET_KEYS = [
    'kpis',
    'revenue',
    'cohort',
    'distribution',
    'heatmap',
    'timeline',
    'stories',
    'recent',
    'top-donors',
    'top-forms',
    'channel',
    'gateway',
];

function OverviewTab( { campaign, nav, onError } ) {
    const [ range, setRange ] = useState( 'all-time' );
    const [ compareMode, setCompareMode ] = useState( 'none' );
    const [ metrics, setMetrics ] = useState( campaign.metrics );
    const [ loading, setLoading ] = useState( false );

    // Layout is a UI preference shared across all campaigns, not per-campaign.
    const layout = useFundKitLayout( 'campaign_overview', WIDGET_KEYS );

    const includeKey = useMemo( () => layout.visibleOrder.join( ',' ), [ layout.visibleOrder ] );

    useEffect( () => {
        let aborted = false;
        setLoading( true );
        const url = `/fundkit/v1/admin/campaigns/${ campaign.id }/metrics`
            + `?range=${ range }&compare=${ compareMode }&include=${ encodeURIComponent( includeKey ) }`;
        apiFetch( { path: url } )
            .then( ( m ) => { if ( ! aborted ) setMetrics( ( prev ) => ( { ...( prev || {} ), ...m } ) ); } )
            // Surface the failure instead of leaving the zero-fallback metrics
            // on screen as if they were real data.
            .catch( ( e ) => { if ( ! aborted ) onError?.( e?.message || __( 'Could not load campaign metrics.', 'fundkit-fundraising-campaigns' ) ); } )
            .finally( () => { if ( ! aborted ) setLoading( false ); } );
        return () => { aborted = true; };
    }, [ range, compareMode, campaign.id, includeKey ] );

    // Merged always, not just while metrics is null: an include=-limited
    // response omits the excluded keys, and the wrappers below deref rows
    // directly, so an absent key must still resolve to its empty default.
    const m = {
        amount_raised_cents: 0,
        donations_count: 0,
        donors_count: 0,
        avg_donation_cents: 0,
        revenue_series: [],
        recent_donations: [],
        top_donors: [],
        top_forms: [],
        by_gateway: [],
        by_channel: [],
        comparison: null,
        timeline: { kind: 'running', days: 0, total_days: null },
        cohort: null,
        notes: [],
        distribution: null,
        dow_hour: null,
        ...( metrics || {} ),
    };

    const compareOn = compareMode !== 'none';
    const cmp = compareOn ? ( m.comparison?.change_percent ?? null ) : null;
    const rangeIsComparable = range !== 'all-time' && range !== 'today';

    const registry = {
        kpis: {
            title: __( 'Key metrics', 'fundkit-fundraising-campaigns' ),
            span:  'full',
            bare:  true,
            render: () => (
                <div className="fundkit-overview__metrics">
                    <GoalProgressCard campaign={ campaign } metrics={ m } />
                    <MetricCard label={ __( 'Amount raised', 'fundkit-fundraising-campaigns' ) }
                                value={ formatAmount( m.amount_raised_cents ) }
                                changePct={ cmp?.amount_raised_cents }
                                icon={ <IconCoins /> } />
                    <MetricCard label={ __( 'Donations', 'fundkit-fundraising-campaigns' ) }
                                value={ String( m.donations_count ) }
                                changePct={ cmp?.donations_count }
                                icon={ <IconHeart /> } />
                    <MetricCard label={ __( 'Donors', 'fundkit-fundraising-campaigns' ) }
                                value={ String( m.donors_count ) }
                                changePct={ cmp?.donors_count }
                                icon={ <IconUsers /> } />
                    <MetricCard label={ __( 'Average donation', 'fundkit-fundraising-campaigns' ) }
                                value={ formatAmount( m.avg_donation_cents ) }
                                changePct={ cmp?.avg_donation_cents }
                                icon={ <IconActivity /> } />
                </div>
            ),
        },
        revenue: {
            title: __( 'Revenue', 'fundkit-fundraising-campaigns' ),
            span:  'full',
            render: () => (
                <RevenueChart
                    series={ m.revenue_series }
                    currency={ defaultCurrency() }
                    compareOn={ compareOn && rangeIsComparable }
                    comparison={ m.comparison }
                />
            ),
        },
        cohort: {
            title: __( 'Donor cohort', 'fundkit-fundraising-campaigns' ),
            render: () => <DonorCohort cohort={ m.cohort } />,
        },
        distribution: {
            title: __( 'Donation shape', 'fundkit-fundraising-campaigns' ),
            render: () => <DistributionHistogram distribution={ m.distribution } currency={ defaultCurrency() } />,
        },
        heatmap: {
            title: __( 'When donors give', 'fundkit-fundraising-campaigns' ),
            span:  'full',
            render: () => <DowHourHeatmap data={ m.dow_hour } />,
        },
        timeline: {
            title: __( 'Timeline', 'fundkit-fundraising-campaigns' ),
            render: () => <TimelineCard timeline={ m.timeline } />,
        },
        stories: {
            title: __( 'Stories', 'fundkit-fundraising-campaigns' ),
            render: () => <Stories rows={ m.notes || [] } />,
        },
        recent: {
            title: __( 'Recent donations', 'fundkit-fundraising-campaigns' ),
            render: () => <RecentDonations rows={ m.recent_donations } />,
        },
        'top-donors': {
            title: __( 'Top donors', 'fundkit-fundraising-campaigns' ),
            render: () => <TopDonors rows={ m.top_donors } currency={ defaultCurrency() } />,
        },
        'top-forms': {
            title: __( 'Top forms', 'fundkit-fundraising-campaigns' ),
            render: () => <TopForms rows={ m.top_forms } currency={ defaultCurrency() } donationsCount={ m.donations_count } />,
        },
        channel: {
            title: __( 'By channel', 'fundkit-fundraising-campaigns' ),
            render: () => <ChannelBreakdown rows={ m.by_channel } currency={ defaultCurrency() } />,
        },
        gateway: {
            title: __( 'By payment method', 'fundkit-fundraising-campaigns' ),
            render: () => <GatewayBreakdown rows={ m.by_gateway } currency={ defaultCurrency() } />,
        },
    };

function CampaignReportButton( { campaignId, reportRange } ) {
    const [ busy, setBusy ] = useState( false );

    return (
        <Btn
            variant="tertiary"
            icon={ <DownloadIcon size={ 16 } strokeWidth={ 1.75 } /> }
            disabled={ busy }
            isBusy={ busy }
            onClick={ async () => {
                setBusy( true );
                try {
                    // Same range the widgets are showing, so the PDF and the
                    // screen it was taken from cannot disagree.
                    await downloadFile(
                        addQueryArgs( `/fundkit/v1/reports/campaign/${ campaignId }/pdf`, { range: reportRange } ),
                        `campaign-${ campaignId }.pdf`
                    );
                } catch ( err ) {
                    notify.error( err?.message || __( 'Could not build the report.', 'fundkit-fundraising-campaigns' ) );
                } finally {
                    setBusy( false );
                }
            } }
        >
            { __( 'Download report', 'fundkit-fundraising-campaigns' ) }
        </Btn>
    );
}

    return (
        <div className="fundkit-overview" data-loading={ loading ? 'true' : undefined }>
            <SectionBar
                nav={ nav }
                range={ range } onRangeChange={ setRange }
                compareMode={ compareMode } onCompareModeChange={ setCompareMode }
                compareAvailable={ rangeIsComparable }
                layoutSlot={
                    <>
                        <CampaignReportButton campaignId={ campaign.id } reportRange={ range } />
                        <LayoutControls
                            hidden={ layout.hidden }
                            registry={ registry }
                            onUnhide={ layout.unhide }
                            onReset={ layout.reset }
                        />
                    </>
                }
            />
            <WidgetGrid
                visibleOrder={ layout.visibleOrder }
                registry={ registry }
                onReorder={ ( from, to ) => {
                    const fromAll = layout.order.indexOf( layout.visibleOrder[ from ] );
                    const toAll   = layout.order.indexOf( layout.visibleOrder[ to ] );
                    layout.moveTo( fromAll, toAll );
                } }
                onHide={ layout.hide }
            />
        </div>
    );
}


function TimelineCard( { timeline } ) {
    if ( ! timeline ) return null;

    const { kind, days, total_days } = timeline;
    const label = kind === 'remaining'
        ? __( 'Days remaining', 'fundkit-fundraising-campaigns' )
        : kind === 'ended'
            ? __( 'Days since ended', 'fundkit-fundraising-campaigns' )
            : __( 'Days running', 'fundkit-fundraising-campaigns' );

    const pct = total_days
        ? Math.min( 100, Math.round( ( ( total_days - days ) / total_days ) * 100 ) )
        : null;

    return (
        <div className="fundkit-timeline">
            <div className="fundkit-timeline__label">{ label }</div>
            <div className="fundkit-timeline__value">{ days }</div>
            <div className="fundkit-timeline__sub">
                { kind === 'remaining' && total_days &&
                    sprintf( /* translators: %d: total days */ __( 'of %d total', 'fundkit-fundraising-campaigns' ), total_days ) }
            </div>
            { pct !== null && (
                <div className="fundkit-timeline__bar">
                    <div className="fundkit-timeline__bar-fill" style={ { width: `${ pct }%` } } />
                </div>
            ) }
        </div>
    );
}

function RecentDonations( { rows } ) {
    if ( rows.length === 0 ) {
        return (
            <EmptyState
                compact
                icon={ <Coins size={ 22 } strokeWidth={ 1.75 } /> }
                title={ __( 'No donations yet', 'fundkit-fundraising-campaigns' ) }
                body={ __( 'Recent donor activity will appear here once your form is live and the first donation lands.', 'fundkit-fundraising-campaigns' ) }
            />
        );
    }
    return (
        <table className="fundkit-table">
            <tbody>
                { rows.map( ( r ) => (
                    <tr key={ r.id }>
                        <td>
                            <div className="fundkit-table__primary">{ r.donor_name }</div>
                            { r.form_title && <div className="fundkit-table__sub">{ r.form_title }</div> }
                        </td>
                        <td className="fundkit-table__right">
                            <div className="fundkit-table__primary">{ formatAmount( r.amount_cents, r.currency ) }</div>
                            <div className="fundkit-table__sub" title={ formatDate( r.paid_at ) }>{ timeAgo( r.paid_at ) }</div>
                        </td>
                    </tr>
                ) ) }
            </tbody>
        </table>
    );
}

function TopForms( { rows, currency, donationsCount = 0 } ) {
    const total = rows.reduce( ( s, r ) => s + r.amount_cents, 0 );
    if ( rows.length === 0 ) {
        // Two different nothings: a campaign whose donations came in without a
        // form must not be told to wait for donations it already has.
        const gotDonations = donationsCount > 0;
        return (
            <EmptyState
                compact
                icon={ <ListChecks size={ 22 } strokeWidth={ 1.75 } /> }
                title={ gotDonations
                    ? __( 'No donations through a form yet', 'fundkit-fundraising-campaigns' )
                    : __( 'No form data yet', 'fundkit-fundraising-campaigns' ) }
                body={ gotDonations
                    ? __( 'This campaign\'s donations were recorded without a form, so there is nothing to rank. Donations made through a donation form appear here.', 'fundkit-fundraising-campaigns' )
                    : __( 'Once donations come in, this card ranks your forms by total raised.', 'fundkit-fundraising-campaigns' ) }
            />
        );
    }
    return (
        <table className="fundkit-table">
            <tbody>
                { rows.map( ( r ) => {
                    const pct = total > 0 ? Math.round( ( r.amount_cents / total ) * 100 ) : 0;
                    return (
                        <tr key={ r.form_id }>
                            <td>
                                <div className="fundkit-table__primary">
                                    <a href={ formEditorHref( r.form_id ) }>{ r.form_title }</a>
                                </div>
                                <div className="fundkit-table__bar">
                                    <div className="fundkit-table__bar-fill" style={ { width: `${ pct }%` } } />
                                </div>
                            </td>
                            <td className="fundkit-table__right">
                                <div className="fundkit-table__primary">{ formatAmount( r.amount_cents, currency ) }</div>
                                <div className="fundkit-table__sub">
                                    { sprintf( /* translators: %d: number of donations */ _n( '%d donation', '%d donations', r.donations_count, 'fundkit-fundraising-campaigns' ), r.donations_count ) }
                                </div>
                            </td>
                        </tr>
                    );
                } ) }
            </tbody>
        </table>
    );
}

function GatewayBreakdown( { rows, currency } ) {
    const total = rows.reduce( ( s, r ) => s + r.amount_cents, 0 );
    const colors = [
        'var(--fundkit-chart-1, #2271b1)',
        'var(--fundkit-chart-2, #6f5ce6)',
        'var(--fundkit-chart-3, #d63384)',
        'var(--fundkit-chart-4, #856a1d)',
        'var(--fundkit-chart-5, #7c2222)',
    ];

    if ( rows.length === 0 || total === 0 ) {
        return (
            <EmptyState
                compact
                icon={ <HandHeart size={ 22 } strokeWidth={ 1.75 } /> }
                title={ __( 'No payments yet', 'fundkit-fundraising-campaigns' ) }
                body={ __( 'Gateway breakdown shows up after the first paid donation.', 'fundkit-fundraising-campaigns' ) }
            />
        );
    }
    return (
        <div className="fundkit-gateway">
            <StackedBar segments={ rows.map( ( r, i ) => ( {
                value: r.amount_cents,
                color: colors[ i % colors.length ],
            } ) ) } total={ total } />
            <ul className="fundkit-gateway__legend">
                { rows.map( ( r, i ) => {
                    const pct = total > 0 ? Math.round( ( r.amount_cents / total ) * 100 ) : 0;
                    return (
                        <li key={ r.gateway }>
                            <span className="fundkit-gateway__dot" style={ { background: colors[ i % colors.length ] } } />
                            <span className="fundkit-gateway__label">{ r.gateway }</span>
                            <span className="fundkit-gateway__value">{ formatAmount( r.amount_cents, currency ) }</span>
                            <span className="fundkit-gateway__pct">{ pct }%</span>
                        </li>
                    );
                } ) }
            </ul>
        </div>
    );
}

function StackedBar( { segments, total } ) {
    return (
        <div className="fundkit-stackbar">
            { segments.map( ( s, i ) => {
                const pct = total > 0 ? ( s.value / total ) * 100 : 0;
                return (
                    <div key={ i }
                         className="fundkit-stackbar__seg"
                         style={ { width: `${ pct }%`, background: s.color } } />
                );
            } ) }
        </div>
    );
}

function TopDonors( { rows, currency } ) {
    if ( rows.length === 0 ) {
        return (
            <EmptyState
                compact
                icon={ <UsersIcon size={ 22 } strokeWidth={ 1.75 } /> }
                title={ __( 'No donors yet', 'fundkit-fundraising-campaigns' ) }
                body={ __( 'Top supporters appear here after the first donation completes.', 'fundkit-fundraising-campaigns' ) }
            />
        );
    }
    return (
        <table className="fundkit-table">
            <tbody>
                { rows.map( ( r ) => (
                    <tr key={ r.donor_id }>
                        <td>
                            <div className="fundkit-table__primary">{ r.name }</div>
                            <div className="fundkit-table__sub">
                                { sprintf(
                                    /* translators: %d: number of donations */
                                    _n( '%d donation', '%d donations', r.donations_count, 'fundkit-fundraising-campaigns' ),
                                    r.donations_count
                                ) }
                            </div>
                        </td>
                        <td className="fundkit-table__right">
                            <div className="fundkit-table__primary">{ formatAmount( r.total_cents, currency ) }</div>
                        </td>
                    </tr>
                ) ) }
            </tbody>
        </table>
    );
}

export function GoalProgressCard( { campaign } ) {
    const goalType = campaign.goal_type ?? 'amount';
    const target = goalType === 'amount'
        ? ( campaign.goal_cents ?? 0 )
        : ( campaign.goal_count ?? 0 );
    // Goal progress is cumulative: campaign-lifetime totals, never the
    // range-scoped metrics, or a range with no donations reads 0% on an
    // already-funded campaign.
    const current = goalType === 'amount'
        ? ( campaign.raised_cents ?? 0 )
        : goalType === 'donations'
            ? ( campaign.donations_count ?? 0 )
            : ( campaign.donors_count ?? 0 );
    // A campaign with no target has no progress to report, and a card reading
    // "-" over "No goal set" was taking a fifth of the row to say so.
    if ( ! ( target > 0 ) ) {
        return null;
    }

    const pct = Math.min( 100, Math.round( ( current / target ) * 100 ) );

    // Raised totals are summed in the org base currency, so they format with
    // the org default and take no per-campaign currency argument.
    const fmt = ( v ) => goalType === 'amount'
        ? formatAmount( v )
        : Number( v ).toLocaleString();

    return (
        <div className="fundkit-metric">
            <div className="fundkit-metric__head">
                <span className="fundkit-metric__icon">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round">
                        <circle cx="12" cy="12" r="9" />
                        <circle cx="12" cy="12" r="5" />
                        <circle cx="12" cy="12" r="1.5" fill="currentColor" />
                    </svg>
                </span>
            </div>
            <div className="fundkit-metric__label">{ __( 'Goal progress', 'fundkit-fundraising-campaigns' ) }</div>
            <div className="fundkit-metric__row">
                <div className="fundkit-metric__value">{ `${ pct }%` }</div>
            </div>
            <div className="fundkit-metric__sub">{ `${ fmt( current ) } / ${ fmt( target ) }` }</div>
            <div className="fundkit-metric__bar" aria-hidden="true">
                <div className="fundkit-metric__bar-fill" style={ { width: `${ pct }%` } } />
            </div>
        </div>
    );
}

const FORM_STATUS_OPTIONS = Object.entries( STATUS_LABEL ).map( ( [ value, label ] ) => ( { value, label } ) );

function ShortcodeCell( { slug } ) {
    const [ copied, setCopied ] = useState( false );
    if ( ! slug ) {
        return <span className="fundkit-row__sub">-</span>;
    }
    const code = `[fundkit_donation_form slug="${ slug }"]`;
    // The cell sits in a DataViews row, which toggles its bulk selection on
    // click, so copying the shortcode has to stop the event or it ticks the
    // row too.
    const copy = async ( e ) => {
        stopRowSelect( e );
        try {
            await navigator.clipboard.writeText( code );
            setCopied( true );
            setTimeout( () => setCopied( false ), 1500 );
        } catch ( err ) {
            // Clipboard API unavailable on an insecure context; the code stays
            // visible to copy by hand.
        }
    };
    return (
        <button
            type="button"
            className="fundkit-shortcode-copy"
            onMouseDown={ stopRowSelect }
            onClick={ copy }
            title={ __( 'Copy shortcode', 'fundkit-fundraising-campaigns' ) }
            aria-label={ __( 'Copy shortcode', 'fundkit-fundraising-campaigns' ) }
        >
            <code className="fundkit-shortcode-copy__code">{ code }</code>
            <span className="fundkit-shortcode-copy__hint">
                { copied
                    ? __( 'Copied', 'fundkit-fundraising-campaigns' )
                    : <CopyIcon size={ 14 } strokeWidth={ 1.75 } /> }
            </span>
        </button>
    );
}

function FormsTab( { campaign } ) {
    const [ view, setView ] = useState( {
        type:    'table',
        perPage: 25,
        page:    1,
        sort:    { field: 'updated_at', direction: 'desc' },
        filters: [],
        search:  '',
        fields:  [ 'title', 'status', 'goal', 'shortcode', 'updated_at' ],
    } );
    const [ data, setData ]         = useState( [] );
    const [ total, setTotal ]       = useState( 0 );
    const [ loading, setLoading ]   = useState( false );
    const [ error, setError ]       = useState( null );
    const [ creating, setCreating ] = useState( false );
    const [ pickerOpen, setPickerOpen ] = useState( false );
    const [ confirm, setConfirm ] = useState( null );
    const [ defaultFormId, setDefaultFormId ] = useState( campaign.default_form_id || null );

    useEffect( () => {
        setDefaultFormId( campaign.default_form_id || null );
    }, [ campaign.default_form_id ] );

    const statusFilter = view.filters?.find( ( f ) => f.field === 'status' );

    const load = useCallback( () => {
        let aborted = false;
        setLoading( true );

        apiFetch( {
            path: addQueryArgs( '/fundkit/v1/admin/forms', {
                campaign_id: campaign.id,
                page:        view.page,
                per_page:    view.perPage,
                orderby:     view.sort?.field || 'updated_at',
                order:       view.sort?.direction || 'desc',
                search:      view.search || undefined,
                status:      statusFilter?.value || undefined,
            } ),
            parse: false,
        } )
            .then( async ( res ) => {
                if ( aborted ) return;
                const items = await res.json();
                setData( Array.isArray( items ) ? items : [] );
                setTotal( parseInt( res.headers.get( 'X-WP-Total' ) || '0', 10 ) );
            } )
            .catch( ( err ) => {
                if ( aborted ) return;
                setError( err?.message || __( 'Failed to load forms.', 'fundkit-fundraising-campaigns' ) );
            } )
            .finally( () => ! aborted && setLoading( false ) );

        return () => { aborted = true; };
    }, [ view, statusFilter, campaign.id ] );

    useEffect( () => load(), [ load ] );

    const onCreate = ( template ) => async () => {
        setCreating( true );
        setError( null );
        try {
            const payload = {
                title:       template?.name
                    ? `${ template.name } form`
                    : __( 'Untitled donation form', 'fundkit-fundraising-campaigns' ),
                campaign_id: campaign.id,
                blocks:      template?.blocks || '',
            };
            if ( template?.settings ) {
                payload.settings = template.settings;
            }
            const f = await apiFetch( {
                path:   '/fundkit/v1/admin/forms',
                method: 'POST',
                data:   payload,
            } );
            window.location.href = formEditorHref( f.id );
        } catch ( err ) {
            setError( err?.message || __( 'Could not create form.', 'fundkit-fundraising-campaigns' ) );
            setCreating( false );
            setPickerOpen( false );
        }
    };

    const fields = useMemo( () => [
        {
            id:            'title',
            label:         __( 'Title', 'fundkit-fundraising-campaigns' ),
            enableSorting: true,
            render: ( { item } ) => (
                <div style={ { lineHeight: 1.3 } }>
                    <a className="fundkit-row__link fundkit-row__link--strong" href={ formEditorHref( item.id ) } { ...rowLinkProps }>
                        { item.title }
                    </a>
                    { item.id === defaultFormId && (
                        <span className="fundkit-default-pill">
                            { __( 'Default', 'fundkit-fundraising-campaigns' ) }
                        </span>
                    ) }
                    <div className="fundkit-row__sub fundkit-row__sub--mono">
                        { item.slug }
                    </div>
                </div>
            ),
        },
        {
            id:       'status',
            label:    __( 'Status', 'fundkit-fundraising-campaigns' ),
            elements: FORM_STATUS_OPTIONS,
            filterBy: { operators: [ 'is' ] },
            render:   ( { item } ) => <StatusBadge status={ item.status } />,
        },
        {
            id:            'updated_at',
            label:         __( 'Updated', 'fundkit-fundraising-campaigns' ),
            enableSorting: true,
            render: ( { item } ) => (
                <span className="fundkit-time" title={ formatDate( item.updated_at ) }>
                    <span className="fundkit-time__rel">{ timeAgo( item.updated_at ) }</span>
                    <span className="fundkit-time__abs">{ formatDate( item.updated_at ) }</span>
                </span>
            ),
        },
        {
            id:    'goal',
            label: __( 'Goal', 'fundkit-fundraising-campaigns' ),
            // No orderby for these on the server, and DataViews offers sorting
            // on anything that does not opt out.
            enableSorting: false,
            render: ( { item } ) => <GoalCell item={ item } />,
        },
        {
            id:     'shortcode',
            label:  __( 'Shortcode', 'fundkit-fundraising-campaigns' ),
            enableSorting: false,
            render: ( { item } ) => <ShortcodeCell slug={ item.slug } />,
        },
    ], [ defaultFormId ] );

    const setAsDefault = useCallback( async ( formId ) => {
        try {
            await apiFetch( {
                path:   `/fundkit/v1/admin/campaigns/${ campaign.id }`,
                method: 'PATCH',
                data:   { default_form_id: formId },
            } );
            setDefaultFormId( formId );
        } catch ( err ) {
            setError( err?.message || __( 'Could not set default form.', 'fundkit-fundraising-campaigns' ) );
        }
    }, [ campaign.id ] );

    const actions = useMemo( () => [
        {
            id:       'edit',
            label:    __( 'Edit', 'fundkit-fundraising-campaigns' ),
            isPrimary: true,
            callback: ( items ) => {
                if ( items[ 0 ] ) window.location.href = formEditorHref( items[ 0 ].id );
            },
        },
        {
            id:       'set-default',
            label:    __( 'Set as default', 'fundkit-fundraising-campaigns' ),
            isEligible: ( item ) =>
                item.id !== defaultFormId && item.status === 'published',
            callback: ( items ) => {
                if ( items[ 0 ] ) setAsDefault( items[ 0 ].id );
            },
        },
        {
            id:    'duplicate',
            label: __( 'Duplicate', 'fundkit-fundraising-campaigns' ),
            // WP `<Icon>` cloneElements an icon-as-element with its own
            // size={24}. A render function takes the `typeof === 'function'`
            // branch instead, where the size sticks.
            icon:  () => <CopyIcon size={ 16 } strokeWidth={ 1.75 } />,
            supportsBulk: true,
            callback: async ( items ) => {
                if ( ! items.length ) return;
                try {
                    if ( items.length === 1 ) {
                        const copy = await apiFetch( {
                            path:   `/fundkit/v1/admin/forms/${ items[ 0 ].id }/duplicate`,
                            method: 'POST',
                        } );
                        window.location.href = formEditorHref( copy.id );
                        return;
                    }
                    await Promise.all( items.map( ( i ) => apiFetch( {
                        path:   `/fundkit/v1/admin/forms/${ i.id }/duplicate`,
                        method: 'POST',
                    } ) ) );
                    load();
                } catch ( err ) {
                    setError( err?.message || __( 'Could not duplicate one or more forms.', 'fundkit-fundraising-campaigns' ) );
                    // Some of the batch may have gone through. Leaving the
                    // table as it was makes the author reload to find out
                    // which.
                    load();
                }
            },
        },
        {
            id:     'delete',
            label:  __( 'Delete', 'fundkit-fundraising-campaigns' ),
            icon:   () => <TrashIcon size={ 16 } strokeWidth={ 1.75 } />,
            isDestructive: true,
            supportsBulk: true,
            // The default form cannot be deleted. The bulk callback filters on
            // the same predicate, as defence in depth.
            isEligible: ( item ) => item.id !== defaultFormId,
            callback: ( items ) => {
                const targets = items.filter( ( i ) => i.id !== defaultFormId );
                if ( ! targets.length ) return;
                const message = targets.length === 1
                    ? __( 'Permanently delete this form? This cannot be undone.', 'fundkit-fundraising-campaigns' )
                    : sprintf(
                        /* translators: %d: number of forms to delete */
                        _n(
                            'Permanently delete %d form? This cannot be undone.',
                            'Permanently delete %d forms? This cannot be undone.',
                            targets.length,
                            'fundkit-fundraising-campaigns'
                        ),
                        targets.length
                    );
                setConfirm( {
                    title:        _n( 'Delete form', 'Delete forms', targets.length, 'fundkit-fundraising-campaigns' ),
                    message,
                    confirmLabel: __( 'Delete', 'fundkit-fundraising-campaigns' ),
                    destructive:  true,
                    onConfirm: async () => {
                        try {
                            await Promise.all( targets.map( ( i ) => apiFetch( {
                                path:   `/fundkit/v1/admin/forms/${ i.id }`,
                                method: 'DELETE',
                            } ) ) );
                            load();
                        } catch ( err ) {
                            setError( err?.message || __( 'Could not delete one or more forms.', 'fundkit-fundraising-campaigns' ) );
                            // Rows already deleted are still on screen
                            // otherwise, so the error reads as nothing having
                            // happened at all.
                            load();
                        }
                    },
                } );
            },
        },
    ], [ defaultFormId, setAsDefault, load ] );

    const paginationInfo = useMemo( () => ( {
        totalItems: total,
        totalPages: Math.max( 1, Math.ceil( total / view.perPage ) ),
    } ), [ total, view.perPage ] );

    return (
        <div>
            <div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 } }>
                <h2 style={ { margin: 0, fontSize: 16 } }>
                    { __( 'Forms', 'fundkit-fundraising-campaigns' ) }
                    <span style={ { color: '#666', fontWeight: 400, marginLeft: 8 } }>({ total })</span>
                </h2>
                <Btn variant="primary" onClick={ () => setPickerOpen( true ) } disabled={ creating }>
                    <Plus size={ 16 } strokeWidth={ 1.75 } />
                    { __( 'Add new form', 'fundkit-fundraising-campaigns' ) }
                </Btn>
            </div>

            { error && (
                <Notice status="error" onRemove={ () => setError( null ) }>{ error }</Notice>
            ) }

            { ! loading && total === 0 && ! view.search && ! statusFilter ? (
                <EmptyState
                    icon={ <ListChecks size={ 22 } strokeWidth={ 1.75 } /> }
                    title={ __( 'No forms yet', 'fundkit-fundraising-campaigns' ) }
                    body={ __( 'Donation forms collect the actual donations for this campaign. Pick a template to get started, then customize everything inside.', 'fundkit-fundraising-campaigns' ) }
                    action={
                        <Btn variant="primary" onClick={ () => setPickerOpen( true ) } disabled={ creating }>
                            { __( 'Add your first form', 'fundkit-fundraising-campaigns' ) }
                        </Btn>
                    }
                />
            ) : (
                <div style={ { padding: '8px 0' } } className="fundkit-dataviews">
                    <DataViews
                        data={ data }
                        isLoading={ loading }
                        fields={ fields }
                        view={ view }
                        onChangeView={ setView }
                        actions={ actions }
                        paginationInfo={ paginationInfo }
                        defaultLayouts={ { table: {}, list: {} } }
                        getItemId={ ( item ) => String( item.id ) }
                    />
                </div>
            ) }

            { pickerOpen && (
                <FormTemplatePicker
                    onPick={ ( t ) => onCreate( t )() }
                    onClose={ () => setPickerOpen( false ) }
                    creating={ creating }
                />
            ) }

            <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
        </div>
    );
}


const GOAL_TYPES = [
    // "No goal" is not a fourth goal_type: a null target is already how every
    // campaign without a goal is stored, and goalMet() and the progress blocks
    // read it that way. This names the state that existed and had no control.
    { value: 'none',      label: __( 'No goal', 'fundkit-fundraising-campaigns' ) },
    { value: 'amount',    label: __( 'Amount raised', 'fundkit-fundraising-campaigns' ) },
    { value: 'donations', label: __( 'Number of donations', 'fundkit-fundraising-campaigns' ) },
    { value: 'donors',    label: __( 'Number of donors', 'fundkit-fundraising-campaigns' ) },
];

const SUB_TABS = [
    { key: 'general',    label: __( 'General', 'fundkit-fundraising-campaigns' ),    Icon: IconGeneral },
    { key: 'goal',       label: __( 'Goal', 'fundkit-fundraising-campaigns' ),       Icon: IconGoal },
    { key: 'appearance', label: __( 'Appearance', 'fundkit-fundraising-campaigns' ), Icon: IconAppearance },
    { key: 'defaults',   label: __( 'Defaults', 'fundkit-fundraising-campaigns' ),   Icon: IconDefaults },
    { key: 'advanced',   label: __( 'Advanced', 'fundkit-fundraising-campaigns' ),   Icon: IconAdvanced },
];

const FIELD_TO_SUBTAB = {
    title: 'general', description: 'general', slug: 'general', status: 'general',
    starts_at: 'general', ends_at: 'general',
    image_attachment_id: 'general', image_url: 'general',
    goal_type: 'goal', goal_cents: 'goal', goal_count: 'goal',
    style: 'appearance', hide_header: 'appearance', hide_footer: 'appearance',
    default_form_id: 'defaults', default_fund_id: 'defaults',
};

const fieldToCard = () => ( {
    title:                __( 'Identity', 'fundkit-fundraising-campaigns' ),
    description:          __( 'Identity', 'fundkit-fundraising-campaigns' ),
    slug:                 __( 'Public address', 'fundkit-fundraising-campaigns' ),
    image_attachment_id:  __( 'Cover image', 'fundkit-fundraising-campaigns' ),
    image_url:            __( 'Cover image', 'fundkit-fundraising-campaigns' ),
    status:               __( 'Status & schedule', 'fundkit-fundraising-campaigns' ),
    starts_at:            __( 'Status & schedule', 'fundkit-fundraising-campaigns' ),
    ends_at:              __( 'Status & schedule', 'fundkit-fundraising-campaigns' ),
    goal_type:            __( 'Goal', 'fundkit-fundraising-campaigns' ),
    goal_cents:           __( 'Goal', 'fundkit-fundraising-campaigns' ),
    goal_count:           __( 'Goal', 'fundkit-fundraising-campaigns' ),
    style:                __( 'Appearance', 'fundkit-fundraising-campaigns' ),
    hide_header:          __( 'Page header & footer', 'fundkit-fundraising-campaigns' ),
    hide_footer:          __( 'Page header & footer', 'fundkit-fundraising-campaigns' ),
    default_form_id:      __( 'Default form', 'fundkit-fundraising-campaigns' ),
    default_fund_id:      __( 'Default fund', 'fundkit-fundraising-campaigns' ),
} );

function SettingsTab( { campaign, onError } ) {
    const c = useFundKitRecord( 'campaign', campaign.id );
    const extSubTabs = useExtensionTabs( 'campaign-settings' );

    const [ subTab, setSubTab ] = useState( 'general' );
    const [ funds, setFunds ]   = useState( [] );
    const [ forms, setForms ]   = useState( [] );

    useEffect( () => {
        apiFetch( { path: addQueryArgs( '/fundkit/v1/admin/campaigns/funds', {
            include: campaign?.default_fund_id || undefined,
        } ) } )
            .then( setFunds )
            .catch( () => onError?.( __( 'Could not load funds.', 'fundkit-fundraising-campaigns' ) ) );
        apiFetch( { path: `/fundkit/v1/admin/forms?campaign_id=${ campaign.id }&per_page=100` } )
            .then( ( res ) => setForms( Array.isArray( res ) ? res : ( res?.items || [] ) ) )
            .catch( () => onError?.( __( 'Could not load forms.', 'fundkit-fundraising-campaigns' ) ) );
    }, [ campaign.id ] );

    useEffect( () => {
        if ( ! c.isDirty ) return undefined;
        const handler = ( e ) => { e.preventDefault(); e.returnValue = ''; return ''; };
        window.addEventListener( 'beforeunload', handler );
        return () => window.removeEventListener( 'beforeunload', handler );
    }, [ c.isDirty ] );

    const dirtyByTab = useMemo( () => {
        const out = {};
        const edits = c.edits || {};
        for ( const key of Object.keys( edits ) ) {
            const tab = FIELD_TO_SUBTAB[ key ];
            if ( tab ) out[ tab ] = true;
        }
        return out;
    }, [ c.edits ] );

    const editsCount = c.edits ? Object.keys( c.edits ).length : 0;

    const dirtyCardNames = useMemo( () => {
        const map = fieldToCard();
        const set = new Set();
        for ( const k of Object.keys( c.edits || {} ) ) {
            const name = map[ k ];
            if ( name ) set.add( name );
        }
        return Array.from( set );
    }, [ c.edits ] );

    const onSave = async () => {
        try {
            await c.save();
            notify.success( __( 'Campaign saved.', 'fundkit-fundraising-campaigns' ) );
        } catch ( err ) {
            onError( err?.message || __( 'Save failed.', 'fundkit-fundraising-campaigns' ) );
        }
    };

    const onDiscard = () => {
        if ( c.discard ) c.discard();
        else if ( c.reset ) c.reset();
    };

    return (
        <div>
            <div className="fundkit-subtabs" role="tablist">
                { SUB_TABS.map( ( t ) => {
                    const active   = subTab === t.key;
                    const isDirty  = !! dirtyByTab[ t.key ];
                    const Icon     = t.Icon;
                    return (
                        <a
                            key={ t.key }
                            href={ `#${ t.key }` }
                            role="tab"
                            aria-selected={ active }
                            className={ active ? 'is-active' : '' }
                            onClick={ ( e ) => { e.preventDefault(); setSubTab( t.key ); } }
                        >
                            <Icon />
                            { t.label }
                            { isDirty && <span className="fundkit-subtabs__dot" title={ __( 'Unsaved changes', 'fundkit-fundraising-campaigns' ) } /> }
                        </a>
                    );
                } ) }
                { extSubTabs.filter( ( t ) => ! t.visible || t.visible( campaign ) ).map( ( t ) => (
                    <a
                        key={ `ext-${ t.id }` }
                        href={ `#ext-${ t.id }` }
                        role="tab"
                        aria-selected={ subTab === `ext-${ t.id }` }
                        className={ subTab === `ext-${ t.id }` ? 'is-active' : '' }
                        onClick={ ( e ) => { e.preventDefault(); setSubTab( `ext-${ t.id }` ); } }
                    >
                        { t.Icon ? <t.Icon /> : null }
                        { t.label }
                    </a>
                ) ) }
            </div>

            { ( () => {
                const showRail = subTab === 'general' || subTab === 'goal' || subTab === 'appearance';
                return (
                    <div className={ `fundkit-settings-layout${ showRail ? '' : ' fundkit-settings-layout--no-rail' }` }>
                        <div className="fundkit-settings-layout__main">
                            <div hidden={ subTab !== 'general' }><GeneralPanel c={ c } campaign={ campaign } /></div>
                            <div hidden={ subTab !== 'goal' }><GoalPanel c={ c } /></div>
                            <div hidden={ subTab !== 'appearance' }><AppearancePanel c={ c } /></div>
                            <div hidden={ subTab !== 'defaults' }><DefaultsPanel c={ c } forms={ forms } funds={ funds } /></div>
                            <div hidden={ subTab !== 'advanced' }><AdvancedPanel campaign={ campaign } onError={ onError } /></div>
                            { extSubTabs.filter( ( t ) => ! t.visible || t.visible( campaign ) ).map( ( t ) => (
                                <div key={ `ext-${ t.id }` } hidden={ subTab !== `ext-${ t.id }` }>
                                    <ExtensionTabPanel tab={ t } context={ { campaign } } />
                                </div>
                            ) ) }
                        </div>
                        { showRail && ( () => {
                            const style    = ( c.record?.style && typeof c.record.style === 'object' ) ? c.record.style : {};
                            const presetId = String( style.preset_id || '' );
                            const inline   = ( style.tokens && typeof style.tokens === 'object' ) ? style.tokens : {};
                            return (
                                <aside className="fundkit-settings-layout__rail">
                                    <StylePreview
                                        tokens={ inline }
                                        presetId={ presetId }
                                        campaign={ c.record }
                                        layer="campaign"
                                        styling={ window.fundkit?.styling || {} }
                                    />
                                </aside>
                            );
                        } )() }
                    </div>
                );
            } )() }

            { c.isDirty && (
                <div className="fundkit-save-bar" role="status" aria-live="polite">
                    <span className="fundkit-save-bar__dot" aria-hidden="true" />
                    <span className="fundkit-save-bar__count">
                        <strong>{ editsCount }</strong>{ ' ' }
                        { _n( 'unsaved change', 'unsaved changes', editsCount, 'fundkit-fundraising-campaigns' ) }
                        { dirtyCardNames.length > 0 && (
                            <em>{ ' ' }{ __( 'in', 'fundkit-fundraising-campaigns' ) } { formatCardNames( dirtyCardNames ) }</em>
                        ) }
                    </span>
                    <button
                        type="button"
                        className="fundkit-save-bar__btn fundkit-save-bar__btn--ghost"
                        onClick={ onDiscard }
                        disabled={ c.isSaving }
                    >
                        { __( 'Discard', 'fundkit-fundraising-campaigns' ) }
                    </button>
                    <button
                        type="button"
                        className="fundkit-save-bar__btn fundkit-save-bar__btn--primary"
                        onClick={ onSave }
                        disabled={ c.isSaving }
                    >
                        { __( 'Save changes', 'fundkit-fundraising-campaigns' ) }
                    </button>
                </div>
            ) }
        </div>
    );
}

function CampaignTypeCard( { campaign } ) {
    const types   = window.fundkit?.campaign_types || {};
    const notices = window.fundkit?.campaign_type_notices || {};
    const [ target, setTarget ] = useState( null );
    const [ busy, setBusy ]     = useState( false );

    if ( Object.keys( types ).length <= 1 ) return null;

    const current     = campaign.campaign_type || 'standard';
    const convertible = current === 'standard'
        ? Object.keys( types ).filter( ( t ) => t !== 'standard' )
        : [];

    const convert = async () => {
        setBusy( true );
        try {
            await apiFetch( {
                path:   `/fundkit/v1/admin/campaigns/${ campaign.id }`,
                method: 'PUT',
                data:   { campaign_type: target },
            } );
            window.location.reload();
        } catch ( err ) {
            setBusy( false );
            setTarget( null );
            notify.error( err?.message || __( 'Conversion failed.', 'fundkit-fundraising-campaigns' ) );
        }
    };

    return (
        <Card
            title={ __( 'Campaign type', 'fundkit-fundraising-campaigns' ) }
            sub={ __( 'Set when the campaign is created. A standard campaign can be converted to a richer type, but not back.', 'fundkit-fundraising-campaigns' ) }
        >
            <FormRow label={ __( 'Type', 'fundkit-fundraising-campaigns' ) }>
                <div style={ { display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' } }>
                    <strong>{ types[ current ] || __( 'Standard', 'fundkit-fundraising-campaigns' ) }</strong>
                    { convertible.map( ( t ) => (
                        <Btn key={ t } variant="secondary" onClick={ () => setTarget( t ) }>
                            { sprintf( /* translators: %s: campaign type label */ __( 'Convert to %s', 'fundkit-fundraising-campaigns' ), types[ t ] ) }
                        </Btn>
                    ) ) }
                </div>
            </FormRow>

            { target && (
                <Modal
                    title={ sprintf( /* translators: %s: campaign type label */ __( 'Convert to %s', 'fundkit-fundraising-campaigns' ), types[ target ] ) }
                    onRequestClose={ () => ! busy && setTarget( null ) }
                >
                    { notices[ target ] && <p>{ notices[ target ] }</p> }
                    <p style={ { fontWeight: 600 } }>{ __( "This can't be undone.", 'fundkit-fundraising-campaigns' ) }</p>
                    <div style={ { display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 16 } }>
                        <Btn variant="tertiary" onClick={ () => setTarget( null ) } disabled={ busy }>
                            { __( 'Cancel', 'fundkit-fundraising-campaigns' ) }
                        </Btn>
                        <Btn variant="primary" onClick={ convert } disabled={ busy }>
                            { busy ? __( 'Converting…', 'fundkit-fundraising-campaigns' ) : __( 'Convert', 'fundkit-fundraising-campaigns' ) }
                        </Btn>
                    </div>
                </Modal>
            ) }
        </Card>
    );
}

/**
 * The address donors land on, taken apart for display. WordPress owns the page
 * slug: it appends a suffix when another page already holds the campaign's one,
 * and the campaign row is never told, so only the permalink the server built
 * describes where the page actually is.
 *
 * @since 1.0.0
 */
export function publicAddress( pageUrl ) {
    if ( ! pageUrl ) {
        return { url: null, prefix: null, display: null, segment: null };
    }
    const trimmed = String( pageUrl ).replace( /\/+$/, '' );
    const cut     = trimmed.lastIndexOf( '/' );

    return {
        url:     pageUrl,
        prefix:  trimmed.slice( 0, cut + 1 ).replace( /^https?:\/\//, '' ),
        display: trimmed.replace( /^https?:\/\//, '' ),
        segment: trimmed.slice( cut + 1 ),
    };
}

/** @since 1.0.0 */
export function PublicAddressCard( { c, pageUrl } ) {
    const slug   = c.value( 'slug', '' );
    const edited = c.isEdited( 'slug' );
    const addr   = publicAddress( pageUrl );
    const origin = window.location.host || '';

    return (
        <Card
            title={ __( 'Public address', 'fundkit-fundraising-campaigns' ) }
            sub={ __( 'The URL donors land on. Changing this may break inbound links.', 'fundkit-fundraising-campaigns' ) }
            edited={ edited ? 1 : 0 }
        >
            <FormRow
                label={ __( 'Slug', 'fundkit-fundraising-campaigns' ) }
                help={ __( 'Letters, numbers, and hyphens only.', 'fundkit-fundraising-campaigns' ) }
            >
                <div className={ `fundkit-input-prefixed${ edited ? ' is-edited' : '' }` }>
                    <span className="fundkit-input-prefixed__prefix">{ addr.prefix || `${ origin }/campaigns/` }</span>
                    <input type="text" className="fundkit-input" { ...c.bind( 'slug' ) } />
                </div>
                { addr.url && (
                    <div className="fundkit-url-preview">
                        <span className="lbl">{ __( 'Public URL', 'fundkit-fundraising-campaigns' ) }</span>
                        <span className="url">{ addr.prefix }<em>{ addr.segment }</em></span>
                        <a href={ addr.url } target="_blank" rel="noreferrer">{ __( 'Visit page ↗', 'fundkit-fundraising-campaigns' ) }</a>
                    </div>
                ) }
                { addr.url && edited && (
                    <div className="fundkit-form-row__field-help">
                        { __( 'Saving moves the page. WordPress adds a suffix if another page already holds the slug, so check this address again afterwards.', 'fundkit-fundraising-campaigns' ) }
                    </div>
                ) }
                { ! addr.url && !! slug && (
                    <div className="fundkit-form-row__field-help">
                        { __( 'This campaign has no page yet, so it has no public address. One is created when the campaign is published.', 'fundkit-fundraising-campaigns' ) }
                    </div>
                ) }
            </FormRow>
        </Card>
    );
}

function GeneralPanel( { c, campaign } ) {
    const r = c.record;
    const desc = c.value( 'description', '' );
    const title = c.value( 'title', '' );

    const overLimit = desc.length > DESCRIPTION_MAX;
    const nearLimit = ! overLimit && desc.length >= DESCRIPTION_MAX - 10;
    const teaserSrc = title && desc ? `"${ title } - ${ desc.replace( /\s+/g, ' ' ).slice( 0, 80 ) }${ desc.length > 80 ? '…' : '' }"` : null;

    const editedCount = ( keys ) => keys.reduce( ( n, k ) => n + ( c.edits?.[ k ] !== undefined ? 1 : 0 ), 0 );

    return (
        <div className="fundkit-section-block">
            <Card
                title={ __( 'Identity', 'fundkit-fundraising-campaigns' ) }
                sub={ __( 'Title and short description, used everywhere this campaign appears.', 'fundkit-fundraising-campaigns' ) }
                edited={ editedCount( [ 'title', 'description' ] ) }
            >
                <FormRow
                    label={ __( 'Campaign title', 'fundkit-fundraising-campaigns' ) }
                    help={ __( 'Donor-facing. Appears in the page header, on cards, in receipts.', 'fundkit-fundraising-campaigns' ) }
                    fieldHelp={ __( 'Try to keep it under 6 words.', 'fundkit-fundraising-campaigns' ) }
                >
                    <input type="text" className={ inputCls( c, 'title' ) } { ...c.bind( 'title' ) } />
                </FormRow>

                <FormRow
                    label={ __( 'Short description', 'fundkit-fundraising-campaigns' ) }
                    help={ __( 'One or two sentences. Shows on campaign cards and the page hero.', 'fundkit-fundraising-campaigns' ) }
                >
                    <div className="fundkit-input-counter">
                        <textarea
                            className={ textareaCls( c, 'description' ) }
                            rows={ 4 }
                            maxLength={ DESCRIPTION_MAX + 50 }
                            { ...c.bind( 'description' ) }
                        />
                        <span className={ `fundkit-input-counter__count${ overLimit ? ' is-over' : ( nearLimit ? ' is-warn' : '' ) }` }>
                            { desc.length } / { DESCRIPTION_MAX }
                        </span>
                    </div>
                    { teaserSrc && (
                        <div className="fundkit-form-row__field-help">
                            <strong style={ { color: '#111827', fontWeight: 500 } }>{ __( 'How donors will see this:', 'fundkit-fundraising-campaigns' ) }</strong>{ ' ' }
                            { teaserSrc }
                        </div>
                    ) }
                </FormRow>
            </Card>

            <CampaignTypeCard campaign={ campaign } />

            <PublicAddressCard c={ c } pageUrl={ campaign?.page_url || r.page_url || null } />

            <Card
                title={ __( 'Cover image', 'fundkit-fundraising-campaigns' ) }
                sub={ __( 'Recommended 1600 × 900 (16:9). Shows on the page hero and campaign grid.', 'fundkit-fundraising-campaigns' ) }
                edited={ editedCount( [ 'image_attachment_id', 'image_url' ] ) }
            >
                <CoverImageCard
                    id={ r.image_attachment_id || null }
                    url={ r.image_url || null }
                    onChange={ ( picked ) => c.edit( {
                        image_attachment_id: picked?.id ?? null,
                        image_url:           picked?.url ?? null,
                    } ) }
                />
            </Card>

            <Card
                title={ __( 'Status & schedule', 'fundkit-fundraising-campaigns' ) }
                sub={ __( "Whether donors can give right now, and when the campaign runs.", 'fundkit-fundraising-campaigns' ) }
                edited={ editedCount( [ 'status', 'starts_at', 'ends_at' ] ) }
            >
                <FormRow
                    label={ __( 'Status', 'fundkit-fundraising-campaigns' ) }
                    help={ __( "Active campaigns accept donations. Drafts and archived campaigns don't. Archive from the campaign menu, which handles any recurring donations first.", 'fundkit-fundraising-campaigns' ) }
                >
                    <StatusPillGroup value={ c.value( 'status', 'draft' ) } onChange={ c.setValue( 'status' ) } />
                </FormRow>
                <FormRow
                    label={ __( 'Schedule', 'fundkit-fundraising-campaigns' ) }
                    help={ __( 'Optional. Leave blank for an open-ended campaign.', 'fundkit-fundraising-campaigns' ) }
                    wide
                >
                    <ScheduleTimeline
                        startsAt={ c.value( 'starts_at', '' ) }
                        endsAt={ c.value( 'ends_at', '' ) }
                        startEdited={ c.isEdited( 'starts_at' ) }
                        endEdited={ c.isEdited( 'ends_at' ) }
                        onChange={ ( patch ) => c.edit( patch ) }
                    />
                </FormRow>
            </Card>
        </div>
    );
}

export function GoalPanel( { c } ) {
    const r = c.record;
    const editedCount = [ 'goal_type', 'goal_cents', 'goal_count', 'close_at_goal' ]
        .reduce( ( n, k ) => n + ( c.edits?.[ k ] !== undefined ? 1 : 0 ), 0 );

    const targetSet = r.goal_type === 'amount'
        ? Number( r.goal_cents ) > 0
        : Number( r.goal_count ) > 0;

    // No target means no goal, which is how a campaign without one is stored.
    const shown = targetSet ? ( r.goal_type ?? 'amount' ) : 'none';

    // The pick only overrides that to hold the panel open on a measurement type
    // whose target is momentarily blank, which is every keystroke of typing one.
    // It never wins the other way, so discarding an edit puts the panel back on
    // the goal the campaign still has.
    const [ picked, setPicked ] = useState( null );
    const mode = ( picked && picked !== 'none' && shown === 'none' ) ? picked : shown;

    const chooseMode = ( next ) => {
        setPicked( next );
        if ( next === 'none' ) {
            c.edit( { goal_cents: null, goal_count: null } );
            return;
        }
        c.edit( { goal_type: next } );
    };

    const hasGoal = mode !== 'none' && targetSet;
    return (
        <div className="fundkit-section-block">
            <Card
                title={ __( 'Campaign goal', 'fundkit-fundraising-campaigns' ) }
                sub={ __( 'Drives the progress widget on the campaign page and the goal donut on the overview.', 'fundkit-fundraising-campaigns' ) }
                edited={ editedCount }
            >
                <FormRow label={ __( 'Goal type', 'fundkit-fundraising-campaigns' ) }>
                    <select
                        className={ `fundkit-select${ [ 'goal_type', 'goal_cents', 'goal_count' ].some( ( k ) => c.isEdited( k ) ) ? ' fundkit-input--edited' : '' }` }
                        value={ mode }
                        onChange={ ( e ) => chooseMode( e.target.value ) }
                    >
                        { GOAL_TYPES.map( ( t ) => (
                            <option key={ t.value } value={ t.value }>{ t.label }</option>
                        ) ) }
                    </select>
                </FormRow>

                { mode === 'amount' && (
                    <FormRow label={ __( 'Target amount', 'fundkit-fundraising-campaigns' ) }>
                        <AmountInput
                            currency={ defaultCurrency() }
                            min={ 0 }
                            value={ r.goal_cents == null || r.goal_cents === '' ? '' : Number( r.goal_cents ) / 100 }
                            onChange={ ( v ) => c.edit( { goal_cents: v ? Math.round( v * 100 ) : null } ) }
                        />
                    </FormRow>
                ) }

                { mode === 'donations' && (
                    <FormRow label={ __( 'Target donations', 'fundkit-fundraising-campaigns' ) }>
                        <input type="number" className={ inputCls( c, 'goal_count' ) } min="0" { ...c.bindNumber( 'goal_count' ) } />
                    </FormRow>
                ) }

                { mode === 'donors' && (
                    <FormRow label={ __( 'Target donors', 'fundkit-fundraising-campaigns' ) }>
                        <input type="number" className={ inputCls( c, 'goal_count' ) } min="0" { ...c.bindNumber( 'goal_count' ) } />
                    </FormRow>
                ) }

                { mode !== 'none' && (
                    <ToggleRow
                        title={ __( 'Close when the goal is met', 'fundkit-fundraising-campaigns' ) }
                        sub={ hasGoal
                            ? __( 'The campaign stops accepting donations as soon as it reaches the target. Reopen it by raising the target or turning this off.', 'fundkit-fundraising-campaigns' )
                            : __( 'Set a target above first. Without one there is nothing to reach.', 'fundkit-fundraising-campaigns' ) }
                        disabled={ ! hasGoal }
                        checked={ !! r.close_at_goal }
                        onChange={ ( v ) => c.edit( { close_at_goal: !! v } ) }
                    />
                ) }

                { r.close_at_goal && r.goal_met && (
                    <p className="fundkit-muted">
                        { __( 'This campaign has reached its goal and is not accepting donations.', 'fundkit-fundraising-campaigns' ) }
                    </p>
                ) }
            </Card>

            <AmbitionMeter
                campaignId={ r.id }
                goalType={ mode }
                goalCents={ r.goal_cents }
                currency={ r.currency }
            />
        </div>
    );
}

function AppearancePanel( { c } ) {
    // style: null = org default; { preset_id } = named preset; { preset_id, tokens } = preset + inline overrides.
    const rawStyle = c.record?.style ?? null;
    const style    = rawStyle && typeof rawStyle === 'object' ? rawStyle : {};
    const presetId = String( style.preset_id || '' );
    const inline   = ( style.tokens && typeof style.tokens === 'object' ) ? style.tokens : {};
    // Keyed on the presence of `tokens`, not its content, so the editor stays
    // open before the first override.
    const isCustomizing = !! ( style.tokens && typeof style.tokens === 'object' );
    const editedCount   = c.edits?.style !== undefined ? 1 : 0;

    const presets   = Array.isArray( window.fundkit?.styling?.presets ) ? window.fundkit.styling.presets : [];
    const defaultId = String( window.fundkit?.styling?.default_id || '' );
    // Baseline is global defaults plus the selected preset's tokens, so each
    // control shows the chosen theme's value with inline overrides on top.
    const presetBase = resolveEffectiveTokens( { tokens: {}, presetId, layer: 'campaign', styling: window.fundkit?.styling || {} } );

    const writeStyle = ( next ) => c.edit( { style: next } );

    const selectPreset = ( id ) => {
        if ( id === '' ) {
            writeStyle( isCustomizing ? { tokens: { ...inline } } : null );
            return;
        }
        const next = { preset_id: id };
        if ( isCustomizing ) next.tokens = { ...inline };
        writeStyle( next );
    };

    const toggleCustomizing = ( on ) => {
        if ( on ) {
            writeStyle( { ...style, tokens: { ...inline } } );
        } else if ( presetId === '' ) {
            // No preset either, so drop the field and let the brand default win.
            writeStyle( null );
        } else {
            writeStyle( { preset_id: presetId } );
        }
    };

    const setInline = ( next ) => {
        const cleaned = {};
        for ( const k in next ) {
            if ( next[ k ] !== '' && next[ k ] != null ) cleaned[ k ] = next[ k ];
        }
        const out = { ...style, tokens: cleaned };
        if ( presetId === '' ) delete out.preset_id;
        writeStyle( out );
    };

    return (
        <div className="fundkit-section-block">
            <Card
                title={ __( 'Campaign appearance', 'fundkit-fundraising-campaigns' ) }
                sub={ __( "Pick which brand preset this campaign uses. Optionally tweak individual tokens for a one-off look.", 'fundkit-fundraising-campaigns' ) }
                edited={ editedCount }
            >
                <FormRow label={ __( 'Style preset', 'fundkit-fundraising-campaigns' ) }>
                    <select
                        className="fundkit-select"
                        value={ presetId }
                        onChange={ ( e ) => selectPreset( e.target.value ) }
                    >
                        <option value="">
                            { __( 'Use org default', 'fundkit-fundraising-campaigns' ) +
                                ( defaultId ? ` (${ presets.find( ( p ) => p.id === defaultId )?.name || defaultId })` : '' ) }
                        </option>
                        { presets.map( ( p ) => (
                            <option key={ p.id } value={ p.id }>{ p.name }</option>
                        ) ) }
                    </select>
                </FormRow>

                <div className="fundkit-custom-style-toggle" style={ { marginTop: 16 } }>
                    <ToggleRow
                        title={ __( 'Customize tokens for this campaign', 'fundkit-fundraising-campaigns' ) }
                        sub={ isCustomizing
                            ? __( 'Inline overrides applied on top of the chosen preset.', 'fundkit-fundraising-campaigns' )
                            : __( 'Toggle on to tweak individual tokens without creating a new brand preset.', 'fundkit-fundraising-campaigns' )
                        }
                        checked={ isCustomizing }
                        onChange={ toggleCustomizing }
                    />
                </div>

                { isCustomizing && (
                    <div className="fundkit-custom-style-body">
                        <TokenEditor
                            value={ inline }
                            defaults={ presetBase }
                            onChange={ setInline }
                            catalogue={ window.fundkit?.styling?.catalogue || {} }
                            groups={ window.fundkit?.styling?.groups || {} }
                        />
                    </div>
                ) }
            </Card>

            <Card
                title={ __( 'Page header & footer', 'fundkit-fundraising-campaigns' ) }
                sub={ __( "Hide the theme's header or footer on every page this campaign renders on.", 'fundkit-fundraising-campaigns' ) }
                edited={ ( c.edits?.hide_header !== undefined ? 1 : 0 ) + ( c.edits?.hide_footer !== undefined ? 1 : 0 ) }
            >
                <ToggleRow
                    title={ __( 'Hide theme header', 'fundkit-fundraising-campaigns' ) }
                    checked={ !! c.value( 'hide_header', false ) }
                    onChange={ ( v ) => c.edit( { hide_header: v } ) }
                />
                <div style={ { marginTop: 16 } }>
                    <ToggleRow
                        title={ __( 'Hide theme footer', 'fundkit-fundraising-campaigns' ) }
                        checked={ !! c.value( 'hide_footer', false ) }
                        onChange={ ( v ) => c.edit( { hide_footer: v } ) }
                    />
                </div>
            </Card>
        </div>
    );
}

function DefaultsPanel( { c, forms, funds } ) {
    const r = c.record;
    const hasFormEdit = c.edits?.default_form_id !== undefined;
    const hasFundEdit = c.edits?.default_fund_id !== undefined;
    return (
        <div className="fundkit-section-block">
            <Card
                title={ __( 'Default form', 'fundkit-fundraising-campaigns' ) }
                sub={ __( 'The form the campaign page and donate-button block submit to by default.', 'fundkit-fundraising-campaigns' ) }
                edited={ hasFormEdit ? 1 : 0 }
            >
                <FormRow
                    label={ __( 'Form', 'fundkit-fundraising-campaigns' ) }
                    help={ __( 'Only published forms that belong to this campaign appear here. A draft cannot be the default: the page would fall back to a different form without saying so.', 'fundkit-fundraising-campaigns' ) }
                >
                    <div className="fundkit-grid-2-eq" style={ { gridTemplateColumns: '1fr auto', alignItems: 'center' } }>
                        <select className={ selectCls( c, 'default_form_id' ) } { ...c.bindNumber( 'default_form_id' ) }>
                            <option value="">{ __( '( None )', 'fundkit-fundraising-campaigns' ) }</option>
                            { /* The Forms tab's own "Set as default" already
                                 guards on published, and every runtime reader
                                 takes this id only when the form is published.
                                 A draft here reads as set and does nothing. */ }
                            { forms
                                .filter( ( f ) => f.status === 'published'
                                    // A default set before this rule, kept
                                    // visible and labelled, so the select does
                                    // not quietly show a different form as this
                                    // campaign's default.
                                    || Number( f.id ) === Number( c.value( 'default_form_id', 0 ) ) )
                                .map( ( f ) => (
                                    <option key={ f.id } value={ f.id }>
                                        { f.status === 'published'
                                            ? f.title
                                            : sprintf(
                                                /* translators: %s: form title */
                                                __( '%s (not published)', 'fundkit-fundraising-campaigns' ),
                                                f.title
                                            ) }
                                    </option>
                                ) ) }
                        </select>
                        { r.default_form_id && (
                            <Btn variant="ghost" size="sm" href={ formEditorHref( Number( r.default_form_id ) ) }>
                                { __( 'Edit form', 'fundkit-fundraising-campaigns' ) } →
                            </Btn>
                        ) }
                    </div>
                </FormRow>
            </Card>

            <Card
                title={ __( 'Default fund', 'fundkit-fundraising-campaigns' ) }
                sub={ __( 'Where donations from this campaign are routed. Useful when separating restricted donations from general operations.', 'fundkit-fundraising-campaigns' ) }
                edited={ hasFundEdit ? 1 : 0 }
            >
                <FormRow label={ __( 'Fund', 'fundkit-fundraising-campaigns' ) }>
                    <select className={ selectCls( c, 'default_fund_id' ) } { ...c.bindNumber( 'default_fund_id' ) }>
                        <option value="">{ __( '( Unassigned )', 'fundkit-fundraising-campaigns' ) }</option>
                        { funds.map( ( f ) => (
                            <option key={ f.id } value={ f.id }>
                                { f.is_active === false
                                    ? sprintf(
                                        /* translators: %s: fund name */
                                        __( '%s (inactive)', 'fundkit-fundraising-campaigns' ),
                                        f.name
                                    )
                                    : f.name }
                            </option>
                        ) ) }
                    </select>
                </FormRow>
            </Card>
        </div>
    );
}

function AdvancedPanel( { campaign, onError } ) {
    const [ deleting, setDeleting ] = useState( false );
    const [ confirm, setConfirm ] = useState( null );

    const onDelete = async () => {
        const message = await campaignDeleteMessage( campaign );
        setConfirm( {
            title:        __( 'Delete campaign', 'fundkit-fundraising-campaigns' ),
            message,
            confirmLabel: __( 'Delete', 'fundkit-fundraising-campaigns' ),
            destructive:  true,
            onConfirm: async () => {
                setDeleting( true );
                try {
                    await apiFetch( {
                        path:   `/fundkit/v1/admin/campaigns/${ campaign.id }`,
                        method: 'DELETE',
                    } );
                    window.location.href = listHref();
                } catch ( err ) {
                    onError( err?.message || __( 'Delete failed.', 'fundkit-fundraising-campaigns' ) );
                    setDeleting( false );
                }
            },
        } );
    };

    return (
        <div className="fundkit-section-block">
            <Card
                title={ __( 'Danger zone', 'fundkit-fundraising-campaigns' ) }
                sub={ __( 'Irreversible actions. Use with care.', 'fundkit-fundraising-campaigns' ) }
            >
                <div className="fundkit-danger">
                    <div className="fundkit-danger__copy">
                        <div className="fundkit-danger__title">{ __( 'Delete this campaign', 'fundkit-fundraising-campaigns' ) }</div>
                        <div className="fundkit-danger__help">
                            { __(
                                'Removes the campaign, its forms, and the WordPress page it created. A campaign that has any donations or recurring plans is never deleted: archive it instead to keep its records.',
                                'fundkit-fundraising-campaigns'
                            ) }
                        </div>
                    </div>
                    <Btn variant="danger" onClick={ onDelete } isBusy={ deleting } disabled={ deleting }>
                        { __( 'Delete campaign', 'fundkit-fundraising-campaigns' ) }
                    </Btn>
                </div>
            </Card>

            <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
        </div>
    );
}

function StatusPillGroup( { value, onChange } ) {
    // Archiving runs its own flow from the menu: it asks the server how many
    // live recurring donations the campaign carries and, when there are any,
    // names the count and the amount at stake and offers to cancel them. This
    // pill wrote the same state change with none of that, so it is offered
    // only when the campaign already is archived, to keep the state visible.
    const options = Object.entries( STATUS_LABEL ).filter(
        ( [ key ] ) => key !== 'archived' || value === 'archived'
    );

    return (
        <div className="fundkit-status-pills" role="radiogroup">
            { options.map( ( [ key, label ] ) => (
                <button
                    key={ key }
                    type="button"
                    role="radio"
                    data-state={ key }
                    aria-checked={ value === key }
                    className={ `fundkit-status-pill${ value === key ? ' is-active' : '' }` }
                    onClick={ () => onChange( key ) }
                >
                    <span className="fundkit-status-pill__dot" />
                    { label }
                </button>
            ) ) }
        </div>
    );
}
