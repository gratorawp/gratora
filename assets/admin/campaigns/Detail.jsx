import { useState, useEffect, useCallback, useRef, useMemo, Fragment } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { DataViews } from '@wordpress/dataviews';
import { Modal, Spinner, Button, CheckboxControl } from '@wordpress/components';
import Notice from '../_shared/components/Notice';
import ConfirmDialog from '../_shared/components/ConfirmDialog';
import { notify } from '../_shared/notify';
import { __, _n, sprintf } from '@wordpress/i18n';

import { useGiveFlowRecord } from '../_shared/useGiveFlowRecord';
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
import { useGiveFlowLayout } from '../_shared/widgets/useGiveFlowLayout';
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
            path:  `/giveflow/v1/admin/forms?campaign_id=${ campaign?.id }&per_page=1`,
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
                'giveflow-fundraising-campaigns'
            ),
            count,
        )
        : __( 'Permanently delete this campaign?', 'giveflow-fundraising-campaigns' ) ];

    // The page is deleted outright rather than trashed, so an admin who has
    // built it out in the block editor has nothing left to restore from.
    if ( campaign?.page_id ) {
        parts.push( __( 'The WordPress page it created is deleted with it, and it does not go to the trash, so any content you built on that page is gone for good.', 'giveflow-fundraising-campaigns' ) );
    }

    parts.push( __( 'This cannot be undone.', 'giveflow-fundraising-campaigns' ) );

    return parts.join( ' ' );
}

// The reason comes from the server, which reads it off the same rule the
// donation gate uses. Deriving it here from status and dates would be a second
// answer to the question, free to disagree with the one that matters.
function NotAcceptingNotice( { campaign, onPublish } ) {
    const reason = campaign?.not_accepting;
    if ( ! reason ) return null;

    const COPY = {
        draft:     __( 'This campaign is a draft, so it is not taking donations yet. Anyone who opens its form is turned away.', 'giveflow-fundraising-campaigns' ),
        archived:  __( 'This campaign is archived and is not taking donations.', 'giveflow-fundraising-campaigns' ),
        scheduled: __( 'This campaign has not started yet, so it is not taking donations until its start date.', 'giveflow-fundraising-campaigns' ),
        ended:     __( 'This campaign has ended and is no longer taking donations.', 'giveflow-fundraising-campaigns' ),
    };

    return (
        <Notice status="warning" isDismissible={ false }>
            { COPY[ reason ] || COPY.draft }
            { reason === 'draft' && (
                <>
                    { ' ' }
                    <Button variant="link" onClick={ onPublish }>
                        { __( 'Publish it now', 'giveflow-fundraising-campaigns' ) }
                    </Button>
                </>
            ) }
        </Notice>
    );
}

export default function Detail( { id, tab } ) {
    const c = useGiveFlowRecord( 'campaign', id );
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
            __( 'Duplicated from "%s". Review and rename before publishing.', 'giveflow-fundraising-campaigns' ),
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
                { __( 'Campaign not found.', 'giveflow-fundraising-campaigns' ) }
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
                path: `/giveflow/v1/admin/campaigns/${ campaign.id }`,
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
                        'giveflow-fundraising-campaigns'
                    ),
                    queued
                ) );
                window.location.reload();
                return;
            }
            notify.success( nextStatus === 'archived'
                ? __( 'Campaign archived.', 'giveflow-fundraising-campaigns' )
                : __( 'Campaign restored to draft.', 'giveflow-fundraising-campaigns' ) );
            window.location.reload();
        } catch ( err ) {
            setError( err?.message || __( 'Update failed.', 'giveflow-fundraising-campaigns' ) );
        }
    };

    const onHeaderAction = async ( name ) => {
        if ( name === 'duplicate' ) {
            try {
                const dup = await apiFetch( {
                    path: `/giveflow/v1/admin/campaigns/${ campaign.id }/duplicate`,
                    method: 'POST',
                } );
                if ( dup?.id ) {
                    const url = addQueryArgs( detailHref( dup.id, 'settings' ), {
                        duplicated_from: campaign.title,
                    } );
                    window.location.href = url;
                }
            } catch ( err ) {
                setError( err?.message || __( 'Duplicate failed.', 'giveflow-fundraising-campaigns' ) );
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
                    path: `/giveflow/v1/admin/campaigns/${ campaign.id }/recurring-summary`,
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
                    path: `/giveflow/v1/admin/campaigns/${ campaign.id }`,
                    method: 'PUT',
                    data: { status: nextStatus },
                } );
                notify.success( name === 'publish'
                    ? __( 'Campaign published.', 'giveflow-fundraising-campaigns' )
                    : __( 'Campaign moved to draft.', 'giveflow-fundraising-campaigns' ) );
                window.location.reload();
            } catch ( err ) {
                setError( err?.message || __( 'Update failed.', 'giveflow-fundraising-campaigns' ) );
            }
            return;
        }
        if ( name === 'delete' ) {
            // The gate's own reason, not one worked out here from
            // donations_count: that counter ignores pending, failed, test-mode
            // and ticket rows, every one of which still blocks a delete.
            if ( campaign.delete_blocked ) {
                setConfirm( {
                    title:        __( 'This campaign cannot be deleted', 'giveflow-fundraising-campaigns' ),
                    message:      campaign.delete_blocked,
                    confirmLabel: __( 'Archive instead', 'giveflow-fundraising-campaigns' ),
                    onConfirm:    () => onHeaderAction( 'archive' ),
                } );
                return;
            }

            const message = await campaignDeleteMessage( campaign );
            setConfirm( {
                title:        __( 'Delete campaign', 'giveflow-fundraising-campaigns' ),
                message,
                confirmLabel: __( 'Delete', 'giveflow-fundraising-campaigns' ),
                destructive:  true,
                onConfirm: async () => {
                    try {
                        await apiFetch( {
                            path: `/giveflow/v1/admin/campaigns/${ campaign.id }`,
                            method: 'DELETE',
                        } );
                        window.location.href = listHref();
                    } catch ( err ) {
                        setError( err?.message || __( 'Delete failed.', 'giveflow-fundraising-campaigns' ) );
                    }
                },
            } );
        }
    };

    return (
        <div className="giveflow-campaign-detail">
            <Header campaign={ campaign } />

            <NotAcceptingNotice campaign={ campaign } onPublish={ () => onHeaderAction( 'publish' ) } />

            { error && (
                <Notice status="error" onRemove={ () => setError( null ) }>{ error }</Notice>
            ) }

            <div className="giveflow-campaign-detail__body">
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
                    title={ __( 'Archive campaign', 'giveflow-fundraising-campaigns' ) }
                    onRequestClose={ () => setArchivePrompt( null ) }
                >
                    <p style={ { marginTop: 0 } }>
                        { sprintf(
                            /* translators: %d: number of live recurring donations */
                            _n(
                                'This campaign has %d live recurring donation.',
                                'This campaign has %d live recurring donations.',
                                archivePrompt.count,
                                'giveflow-fundraising-campaigns'
                            ),
                            archivePrompt.count
                        ) }
                        { ' ' }
                        { __( 'Live counts active, paused and past-due donations: a paused one resumes and a past-due one is still being retried.', 'giveflow-fundraising-campaigns' ) }
                        { archivePrompt.mrr_cents > 0 && ' ' + sprintf(
                            /* translators: %s: formatted monthly amount */
                            __( 'About %s a month is at stake.', 'giveflow-fundraising-campaigns' ),
                            formatAmount( archivePrompt.mrr_cents, archivePrompt.currency )
                        ) }
                    </p>
                    <p>
                        { __( 'Archiving stops new donations. These subscriptions are left as they are and stay credited to this campaign unless you cancel them.', 'giveflow-fundraising-campaigns' ) }
                    </p>
                    <CheckboxControl
                        label={ __( 'Also cancel these subscriptions (donors will be emailed)', 'giveflow-fundraising-campaigns' ) }
                        checked={ cancelSubs }
                        onChange={ setCancelSubs }
                        __nextHasNoMarginBottom
                    />
                    <div style={ { display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 20 } }>
                        <Button variant="tertiary" onClick={ () => setArchivePrompt( null ) }>
                            { __( 'Cancel', 'giveflow-fundraising-campaigns' ) }
                        </Button>
                        <Button
                            variant="primary"
                            isDestructive={ cancelSubs }
                            onClick={ () => {
                                setArchivePrompt( null );
                                runArchive( 'archived', cancelSubs );
                            } }
                        >
                            { __( 'Archive campaign', 'giveflow-fundraising-campaigns' ) }
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
            <div className="giveflow-crumbs">
                <a href={ listHref() }>{ __( 'Campaigns', 'giveflow-fundraising-campaigns' ) }</a>
                <span className="sep">›</span>
                <span>{ campaign.title }</span>
            </div>
            <div className="giveflow-page-head">
                <div className="giveflow-page-head__left">
                    <h1>{ campaign.title }</h1>
                    <div className="giveflow-page-head__meta-row">
                        { campaign.campaign_type && campaign.campaign_type !== 'standard' && campaign.campaign_type_label && (
                            <span className="giveflow-pill giveflow-pill--lg giveflow-pill--type">
                                { campaign.campaign_type_label }
                            </span>
                        ) }
                        <span className={ `giveflow-pill giveflow-pill--lg ${ statusPillClass( campaign.status ) }` }>
                            { STATUS_LABEL[ campaign.status ] || campaign.status }
                        </span>
                        { lastSaved && <span className="giveflow-page-head__meta">{ lastSaved }</span> }
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
    { id: 'overview', label: __( 'Overview', 'giveflow-fundraising-campaigns' ), Icon: IconOverview },
    { id: 'forms',    label: __( 'Forms',    'giveflow-fundraising-campaigns' ), Icon: IconFormsTab },
    { id: 'settings', label: __( 'Settings', 'giveflow-fundraising-campaigns' ), Icon: IconSettingsTab },
];

function ViewToggle( { active, campaignId, extra = [] } ) {
    const views = [ ...VIEW_TOGGLE_DEFS, ...extra ];
    return (
        <div className="giveflow-view-toggle" role="tablist" aria-label={ __( 'Campaign sections', 'giveflow-fundraising-campaigns' ) }>
            { views.map( ( t ) => (
                <a
                    key={ t.id }
                    role="tab"
                    aria-selected={ active === t.id }
                    href={ detailHref( campaignId, t.id ) }
                    className={ `giveflow-cmp-toggle${ active === t.id ? ' is-active' : '' }` }
                >
                    { t.Icon ? <t.Icon /> : null }
                    { t.label }
                    { !! t.badge && <span className="giveflow-cmp-toggle__badge">{ t.badge }</span> }
                </a>
            ) ) }
        </div>
    );
}

function DetailNav( { campaign, activeTab, onAction, extraTabs = [] } ) {
    return (
        <div className="giveflow-detail-nav">
            <ViewToggle active={ activeTab } campaignId={ campaign.id } extra={ extraTabs } />
            { campaign.page_edit_url && ( ! campaign.campaign_type || campaign.campaign_type === 'standard' ) && (
                // Non-standard types (e.g. peer-to-peer) manage their pages from
                // their own admin tab, so the single-page edit link is redundant.
                <Btn href={ campaign.page_edit_url }>{ __( 'Edit campaign page', 'giveflow-fundraising-campaigns' ) }</Btn>
            ) }
            { campaign.page_url && (
                <Btn variant="ghost" href={ campaign.page_url } target="_blank" rel="noreferrer">
                    { __( 'View page ↗', 'giveflow-fundraising-campaigns' ) }
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
        const items = Array.from( ref.current?.querySelectorAll( '.giveflow-menu__item' ) || [] );
        if ( ! items.length ) return;
        const idx  = items.indexOf( ref.current.ownerDocument.activeElement );
        const next = e.key === 'ArrowDown'
            ? ( idx + 1 ) % items.length
            : ( idx <= 0 ? items.length - 1 : idx - 1 );
        items[ next ].focus();
    };

    return (
        // eslint-disable-next-line jsx-a11y/no-static-element-interactions -- menu-widget wrapper; onKeyDown coordinates roving focus among the role=menuitem buttons in the popup below
        <div className="giveflow-menu" ref={ ref } onKeyDown={ onKeyDown }>
            <button
                type="button"
                ref={ triggerRef }
                className="giveflow-menu__trigger"
                aria-label={ __( 'Campaign actions', 'giveflow-fundraising-campaigns' ) }
                aria-haspopup="menu"
                aria-expanded={ open }
                onClick={ () => setOpen( ( v ) => ! v ) }
            >
                ⋯
            </button>
            { open && (
                <div className="giveflow-menu__list" role="menu">
                    { isDraft && (
                        <button type="button" role="menuitem" className="giveflow-menu__item is-primary" onClick={ () => fire( 'publish' ) }>
                            { __( 'Publish campaign', 'giveflow-fundraising-campaigns' ) }
                        </button>
                    ) }
                    { isPublished && (
                        <button type="button" role="menuitem" className="giveflow-menu__item" onClick={ () => fire( 'unpublish' ) }>
                            { __( 'Move to draft', 'giveflow-fundraising-campaigns' ) }
                        </button>
                    ) }
                    <button type="button" role="menuitem" className="giveflow-menu__item" onClick={ () => fire( 'duplicate' ) }>
                        { __( 'Duplicate campaign', 'giveflow-fundraising-campaigns' ) }
                    </button>
                    <button type="button" role="menuitem" className="giveflow-menu__item" onClick={ () => fire( isArchived ? 'unarchive' : 'archive' ) }>
                        { isArchived ? __( 'Restore to draft', 'giveflow-fundraising-campaigns' ) : __( 'Archive campaign', 'giveflow-fundraising-campaigns' ) }
                    </button>
                    <button type="button" role="menuitem" className="giveflow-menu__item is-danger" onClick={ () => fire( 'delete' ) }>
                        { __( 'Delete…', 'giveflow-fundraising-campaigns' ) }
                    </button>
                </div>
            ) }
        </div>
    );
}

function inputCls( c, key, extra = '' ) {
    return `giveflow-input${ c.isEdited( key ) ? ' giveflow-input--edited' : '' }${ extra ? ' ' + extra : '' }`;
}
function textareaCls( c, key, extra = '' ) {
    return `giveflow-textarea${ c.isEdited( key ) ? ' giveflow-textarea--edited' : '' }${ extra ? ' ' + extra : '' }`;
}
function selectCls( c, key ) {
    return `giveflow-select${ c.isEdited( key ) ? ' giveflow-input--edited' : '' }`;
}

function formatCardNames( names ) {
    if ( names.length === 0 ) return '';
    if ( names.length <= 3 ) return names.join( ', ' );
    return sprintf(
        /* translators: 1: first three section names, 2: count of remaining */
        __( '%1$s, and %2$d more', 'giveflow-fundraising-campaigns' ),
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
        __( 'Last saved %s', 'giveflow-fundraising-campaigns' ),
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
    const layout = useGiveFlowLayout( 'campaign_overview', WIDGET_KEYS );

    const includeKey = useMemo( () => layout.visibleOrder.join( ',' ), [ layout.visibleOrder ] );

    useEffect( () => {
        let aborted = false;
        setLoading( true );
        const url = `/giveflow/v1/admin/campaigns/${ campaign.id }/metrics`
            + `?range=${ range }&compare=${ compareMode }&include=${ encodeURIComponent( includeKey ) }`;
        apiFetch( { path: url } )
            .then( ( m ) => { if ( ! aborted ) setMetrics( ( prev ) => ( { ...( prev || {} ), ...m } ) ); } )
            // Surface the failure instead of leaving the zero-fallback metrics
            // on screen as if they were real data.
            .catch( ( e ) => { if ( ! aborted ) onError?.( e?.message || __( 'Could not load campaign metrics.', 'giveflow-fundraising-campaigns' ) ); } )
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
            title: __( 'Key metrics', 'giveflow-fundraising-campaigns' ),
            span:  'full',
            bare:  true,
            render: () => (
                <div className="giveflow-overview__metrics">
                    <GoalProgressCard campaign={ campaign } metrics={ m } />
                    <MetricCard label={ __( 'Amount raised', 'giveflow-fundraising-campaigns' ) }
                                value={ formatAmount( m.amount_raised_cents ) }
                                changePct={ cmp?.amount_raised_cents }
                                icon={ <IconCoins /> } />
                    <MetricCard label={ __( 'Donations', 'giveflow-fundraising-campaigns' ) }
                                value={ String( m.donations_count ) }
                                changePct={ cmp?.donations_count }
                                icon={ <IconHeart /> } />
                    <MetricCard label={ __( 'Donors', 'giveflow-fundraising-campaigns' ) }
                                value={ String( m.donors_count ) }
                                changePct={ cmp?.donors_count }
                                icon={ <IconUsers /> } />
                    <MetricCard label={ __( 'Average donation', 'giveflow-fundraising-campaigns' ) }
                                value={ formatAmount( m.avg_donation_cents ) }
                                changePct={ cmp?.avg_donation_cents }
                                icon={ <IconActivity /> } />
                </div>
            ),
        },
        revenue: {
            title: __( 'Revenue', 'giveflow-fundraising-campaigns' ),
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
            title: __( 'Donor cohort', 'giveflow-fundraising-campaigns' ),
            render: () => <DonorCohort cohort={ m.cohort } />,
        },
        distribution: {
            title: __( 'Donation shape', 'giveflow-fundraising-campaigns' ),
            render: () => <DistributionHistogram distribution={ m.distribution } currency={ defaultCurrency() } />,
        },
        heatmap: {
            title: __( 'When donors give', 'giveflow-fundraising-campaigns' ),
            span:  'full',
            render: () => <DowHourHeatmap data={ m.dow_hour } />,
        },
        timeline: {
            title: __( 'Timeline', 'giveflow-fundraising-campaigns' ),
            render: () => <TimelineCard timeline={ m.timeline } />,
        },
        stories: {
            title: __( 'Stories', 'giveflow-fundraising-campaigns' ),
            render: () => <Stories rows={ m.notes || [] } />,
        },
        recent: {
            title: __( 'Recent donations', 'giveflow-fundraising-campaigns' ),
            render: () => <RecentDonations rows={ m.recent_donations } />,
        },
        'top-donors': {
            title: __( 'Top donors', 'giveflow-fundraising-campaigns' ),
            render: () => <TopDonors rows={ m.top_donors } currency={ defaultCurrency() } />,
        },
        'top-forms': {
            title: __( 'Top forms', 'giveflow-fundraising-campaigns' ),
            render: () => <TopForms rows={ m.top_forms } currency={ defaultCurrency() } donationsCount={ m.donations_count } />,
        },
        channel: {
            title: __( 'By channel', 'giveflow-fundraising-campaigns' ),
            render: () => <ChannelBreakdown rows={ m.by_channel } currency={ defaultCurrency() } />,
        },
        gateway: {
            title: __( 'By payment method', 'giveflow-fundraising-campaigns' ),
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
                        addQueryArgs( `/giveflow/v1/reports/campaign/${ campaignId }/pdf`, { range: reportRange } ),
                        `campaign-${ campaignId }.pdf`
                    );
                } catch ( err ) {
                    notify.error( err?.message || __( 'Could not build the report.', 'giveflow-fundraising-campaigns' ) );
                } finally {
                    setBusy( false );
                }
            } }
        >
            { __( 'Download report', 'giveflow-fundraising-campaigns' ) }
        </Btn>
    );
}

    return (
        <div className="giveflow-overview" data-loading={ loading ? 'true' : undefined }>
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
        ? __( 'Days remaining', 'giveflow-fundraising-campaigns' )
        : kind === 'ended'
            ? __( 'Days since ended', 'giveflow-fundraising-campaigns' )
            : __( 'Days running', 'giveflow-fundraising-campaigns' );

    const pct = total_days
        ? Math.min( 100, Math.round( ( ( total_days - days ) / total_days ) * 100 ) )
        : null;

    return (
        <div className="giveflow-timeline">
            <div className="giveflow-timeline__label">{ label }</div>
            <div className="giveflow-timeline__value">{ days }</div>
            <div className="giveflow-timeline__sub">
                { kind === 'remaining' && total_days &&
                    sprintf( /* translators: %d: total days */ __( 'of %d total', 'giveflow-fundraising-campaigns' ), total_days ) }
            </div>
            { pct !== null && (
                <div className="giveflow-timeline__bar">
                    <div className="giveflow-timeline__bar-fill" style={ { width: `${ pct }%` } } />
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
                title={ __( 'No donations yet', 'giveflow-fundraising-campaigns' ) }
                body={ __( 'Recent donor activity will appear here once your form is live and the first donation lands.', 'giveflow-fundraising-campaigns' ) }
            />
        );
    }
    return (
        <table className="giveflow-table">
            <tbody>
                { rows.map( ( r ) => (
                    <tr key={ r.id }>
                        <td>
                            <div className="giveflow-table__primary">{ r.donor_name }</div>
                            { r.form_title && <div className="giveflow-table__sub">{ r.form_title }</div> }
                        </td>
                        <td className="giveflow-table__right">
                            <div className="giveflow-table__primary">{ formatAmount( r.amount_cents, r.currency ) }</div>
                            <div className="giveflow-table__sub" title={ formatDate( r.paid_at ) }>{ timeAgo( r.paid_at ) }</div>
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
                    ? __( 'No donations through a form yet', 'giveflow-fundraising-campaigns' )
                    : __( 'No form data yet', 'giveflow-fundraising-campaigns' ) }
                body={ gotDonations
                    ? __( 'This campaign\'s donations were recorded without a form, so there is nothing to rank. Donations made through a donation form appear here.', 'giveflow-fundraising-campaigns' )
                    : __( 'Once donations come in, this card ranks your forms by total raised.', 'giveflow-fundraising-campaigns' ) }
            />
        );
    }
    return (
        <table className="giveflow-table">
            <tbody>
                { rows.map( ( r ) => {
                    const pct = total > 0 ? Math.round( ( r.amount_cents / total ) * 100 ) : 0;
                    return (
                        <tr key={ r.form_id }>
                            <td>
                                <div className="giveflow-table__primary">
                                    <a href={ formEditorHref( r.form_id ) }>{ r.form_title }</a>
                                </div>
                                <div className="giveflow-table__bar">
                                    <div className="giveflow-table__bar-fill" style={ { width: `${ pct }%` } } />
                                </div>
                            </td>
                            <td className="giveflow-table__right">
                                <div className="giveflow-table__primary">{ formatAmount( r.amount_cents, currency ) }</div>
                                <div className="giveflow-table__sub">
                                    { sprintf( /* translators: %d: number of donations */ _n( '%d donation', '%d donations', r.donations_count, 'giveflow-fundraising-campaigns' ), r.donations_count ) }
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
        'var(--giveflow-chart-1, #2271b1)',
        'var(--giveflow-chart-2, #6f5ce6)',
        'var(--giveflow-chart-3, #d63384)',
        'var(--giveflow-chart-4, #856a1d)',
        'var(--giveflow-chart-5, #7c2222)',
    ];

    if ( rows.length === 0 || total === 0 ) {
        return (
            <EmptyState
                compact
                icon={ <HandHeart size={ 22 } strokeWidth={ 1.75 } /> }
                title={ __( 'No payments yet', 'giveflow-fundraising-campaigns' ) }
                body={ __( 'Gateway breakdown shows up after the first paid donation.', 'giveflow-fundraising-campaigns' ) }
            />
        );
    }
    return (
        <div className="giveflow-gateway">
            <StackedBar segments={ rows.map( ( r, i ) => ( {
                value: r.amount_cents,
                color: colors[ i % colors.length ],
            } ) ) } total={ total } />
            <ul className="giveflow-gateway__legend">
                { rows.map( ( r, i ) => {
                    const pct = total > 0 ? Math.round( ( r.amount_cents / total ) * 100 ) : 0;
                    return (
                        <li key={ r.gateway }>
                            <span className="giveflow-gateway__dot" style={ { background: colors[ i % colors.length ] } } />
                            <span className="giveflow-gateway__label">{ r.gateway }</span>
                            <span className="giveflow-gateway__value">{ formatAmount( r.amount_cents, currency ) }</span>
                            <span className="giveflow-gateway__pct">{ pct }%</span>
                        </li>
                    );
                } ) }
            </ul>
        </div>
    );
}

function StackedBar( { segments, total } ) {
    return (
        <div className="giveflow-stackbar">
            { segments.map( ( s, i ) => {
                const pct = total > 0 ? ( s.value / total ) * 100 : 0;
                return (
                    <div key={ i }
                         className="giveflow-stackbar__seg"
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
                title={ __( 'No donors yet', 'giveflow-fundraising-campaigns' ) }
                body={ __( 'Top supporters appear here after the first donation completes.', 'giveflow-fundraising-campaigns' ) }
            />
        );
    }
    return (
        <table className="giveflow-table">
            <tbody>
                { rows.map( ( r ) => (
                    <tr key={ r.donor_id }>
                        <td>
                            <div className="giveflow-table__primary">{ r.name }</div>
                            <div className="giveflow-table__sub">
                                { sprintf(
                                    /* translators: %d: number of donations */
                                    _n( '%d donation', '%d donations', r.donations_count, 'giveflow-fundraising-campaigns' ),
                                    r.donations_count
                                ) }
                            </div>
                        </td>
                        <td className="giveflow-table__right">
                            <div className="giveflow-table__primary">{ formatAmount( r.total_cents, currency ) }</div>
                        </td>
                    </tr>
                ) ) }
            </tbody>
        </table>
    );
}

function GoalProgressCard( { campaign } ) {
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
    const pct = target > 0 ? Math.min( 100, Math.round( ( current / target ) * 100 ) ) : null;

    // Raised totals are summed in the org base currency, so they format with
    // the org default and take no per-campaign currency argument.
    const fmt = ( v ) => goalType === 'amount'
        ? formatAmount( v )
        : Number( v ).toLocaleString();

    return (
        <div className="giveflow-metric">
            <div className="giveflow-metric__head">
                <span className="giveflow-metric__icon">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round">
                        <circle cx="12" cy="12" r="9" />
                        <circle cx="12" cy="12" r="5" />
                        <circle cx="12" cy="12" r="1.5" fill="currentColor" />
                    </svg>
                </span>
            </div>
            <div className="giveflow-metric__label">{ __( 'Goal progress', 'giveflow-fundraising-campaigns' ) }</div>
            <div className="giveflow-metric__row">
                <div className="giveflow-metric__value">{ pct === null ? '-' : `${ pct }%` }</div>
            </div>
            <div className="giveflow-metric__sub">
                { target > 0
                    ? `${ fmt( current ) } / ${ fmt( target ) }`
                    : __( 'No goal set', 'giveflow-fundraising-campaigns' ) }
            </div>
            { target > 0 && (
                <div className="giveflow-metric__bar" aria-hidden="true">
                    <div className="giveflow-metric__bar-fill" style={ { width: `${ pct ?? 0 }%` } } />
                </div>
            ) }
        </div>
    );
}

const FORM_STATUS_OPTIONS = Object.entries( STATUS_LABEL ).map( ( [ value, label ] ) => ( { value, label } ) );

function ShortcodeCell( { slug } ) {
    const [ copied, setCopied ] = useState( false );
    if ( ! slug ) {
        return <span className="giveflow-row__sub">-</span>;
    }
    const code = `[giveflow_donation_form slug="${ slug }"]`;
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
            className="giveflow-shortcode-copy"
            onMouseDown={ stopRowSelect }
            onClick={ copy }
            title={ __( 'Copy shortcode', 'giveflow-fundraising-campaigns' ) }
            aria-label={ __( 'Copy shortcode', 'giveflow-fundraising-campaigns' ) }
        >
            <code className="giveflow-shortcode-copy__code">{ code }</code>
            <span className="giveflow-shortcode-copy__hint">
                { copied
                    ? __( 'Copied', 'giveflow-fundraising-campaigns' )
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
            path: addQueryArgs( '/giveflow/v1/admin/forms', {
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
                setError( err?.message || __( 'Failed to load forms.', 'giveflow-fundraising-campaigns' ) );
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
                    : __( 'Untitled donation form', 'giveflow-fundraising-campaigns' ),
                campaign_id: campaign.id,
                blocks:      template?.blocks || '',
            };
            if ( template?.settings ) {
                payload.settings = template.settings;
            }
            const f = await apiFetch( {
                path:   '/giveflow/v1/admin/forms',
                method: 'POST',
                data:   payload,
            } );
            window.location.href = formEditorHref( f.id );
        } catch ( err ) {
            setError( err?.message || __( 'Could not create form.', 'giveflow-fundraising-campaigns' ) );
            setCreating( false );
            setPickerOpen( false );
        }
    };

    const fields = useMemo( () => [
        {
            id:            'title',
            label:         __( 'Title', 'giveflow-fundraising-campaigns' ),
            enableSorting: true,
            render: ( { item } ) => (
                <div style={ { lineHeight: 1.3 } }>
                    <a className="giveflow-row__link giveflow-row__link--strong" href={ formEditorHref( item.id ) } { ...rowLinkProps }>
                        { item.title }
                    </a>
                    { item.id === defaultFormId && (
                        <span className="giveflow-default-pill">
                            { __( 'Default', 'giveflow-fundraising-campaigns' ) }
                        </span>
                    ) }
                    <div className="giveflow-row__sub giveflow-row__sub--mono">
                        { item.slug }
                    </div>
                </div>
            ),
        },
        {
            id:       'status',
            label:    __( 'Status', 'giveflow-fundraising-campaigns' ),
            elements: FORM_STATUS_OPTIONS,
            filterBy: { operators: [ 'is' ] },
            render:   ( { item } ) => <StatusBadge status={ item.status } />,
        },
        {
            id:            'updated_at',
            label:         __( 'Updated', 'giveflow-fundraising-campaigns' ),
            enableSorting: true,
            render: ( { item } ) => (
                <span className="giveflow-row__sub">
                    { formatDate( item.updated_at ) }
                </span>
            ),
        },
        {
            id:    'goal',
            label: __( 'Goal', 'giveflow-fundraising-campaigns' ),
            // No orderby for these on the server, and DataViews offers sorting
            // on anything that does not opt out.
            enableSorting: false,
            render: ( { item } ) => <GoalCell item={ item } />,
        },
        {
            id:     'shortcode',
            label:  __( 'Shortcode', 'giveflow-fundraising-campaigns' ),
            enableSorting: false,
            render: ( { item } ) => <ShortcodeCell slug={ item.slug } />,
        },
    ], [ defaultFormId ] );

    const setAsDefault = useCallback( async ( formId ) => {
        try {
            await apiFetch( {
                path:   `/giveflow/v1/admin/campaigns/${ campaign.id }`,
                method: 'PATCH',
                data:   { default_form_id: formId },
            } );
            setDefaultFormId( formId );
        } catch ( err ) {
            setError( err?.message || __( 'Could not set default form.', 'giveflow-fundraising-campaigns' ) );
        }
    }, [ campaign.id ] );

    const actions = useMemo( () => [
        {
            id:       'edit',
            label:    __( 'Edit', 'giveflow-fundraising-campaigns' ),
            isPrimary: true,
            callback: ( items ) => {
                if ( items[ 0 ] ) window.location.href = formEditorHref( items[ 0 ].id );
            },
        },
        {
            id:       'set-default',
            label:    __( 'Set as default', 'giveflow-fundraising-campaigns' ),
            isEligible: ( item ) =>
                item.id !== defaultFormId && item.status === 'published',
            callback: ( items ) => {
                if ( items[ 0 ] ) setAsDefault( items[ 0 ].id );
            },
        },
        {
            id:    'duplicate',
            label: __( 'Duplicate', 'giveflow-fundraising-campaigns' ),
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
                            path:   `/giveflow/v1/admin/forms/${ items[ 0 ].id }/duplicate`,
                            method: 'POST',
                        } );
                        window.location.href = formEditorHref( copy.id );
                        return;
                    }
                    await Promise.all( items.map( ( i ) => apiFetch( {
                        path:   `/giveflow/v1/admin/forms/${ i.id }/duplicate`,
                        method: 'POST',
                    } ) ) );
                    load();
                } catch ( err ) {
                    setError( err?.message || __( 'Could not duplicate one or more forms.', 'giveflow-fundraising-campaigns' ) );
                    // Some of the batch may have gone through. Leaving the
                    // table as it was makes the author reload to find out
                    // which.
                    load();
                }
            },
        },
        {
            id:     'delete',
            label:  __( 'Delete', 'giveflow-fundraising-campaigns' ),
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
                    ? __( 'Permanently delete this form? This cannot be undone.', 'giveflow-fundraising-campaigns' )
                    : sprintf(
                        /* translators: %d: number of forms to delete */
                        _n(
                            'Permanently delete %d form? This cannot be undone.',
                            'Permanently delete %d forms? This cannot be undone.',
                            targets.length,
                            'giveflow-fundraising-campaigns'
                        ),
                        targets.length
                    );
                setConfirm( {
                    title:        _n( 'Delete form', 'Delete forms', targets.length, 'giveflow-fundraising-campaigns' ),
                    message,
                    confirmLabel: __( 'Delete', 'giveflow-fundraising-campaigns' ),
                    destructive:  true,
                    onConfirm: async () => {
                        try {
                            await Promise.all( targets.map( ( i ) => apiFetch( {
                                path:   `/giveflow/v1/admin/forms/${ i.id }`,
                                method: 'DELETE',
                            } ) ) );
                            load();
                        } catch ( err ) {
                            setError( err?.message || __( 'Could not delete one or more forms.', 'giveflow-fundraising-campaigns' ) );
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
                    { __( 'Forms', 'giveflow-fundraising-campaigns' ) }
                    <span style={ { color: '#666', fontWeight: 400, marginLeft: 8 } }>({ total })</span>
                </h2>
                <Btn variant="primary" onClick={ () => setPickerOpen( true ) } disabled={ creating }>
                    <Plus size={ 16 } strokeWidth={ 1.75 } />
                    { __( 'Add new form', 'giveflow-fundraising-campaigns' ) }
                </Btn>
            </div>

            { error && (
                <Notice status="error" onRemove={ () => setError( null ) }>{ error }</Notice>
            ) }

            { ! loading && total === 0 && ! view.search && ! statusFilter ? (
                <EmptyState
                    icon={ <ListChecks size={ 22 } strokeWidth={ 1.75 } /> }
                    title={ __( 'No forms yet', 'giveflow-fundraising-campaigns' ) }
                    body={ __( 'Donation forms collect the actual donations for this campaign. Pick a template to get started, then customize everything inside.', 'giveflow-fundraising-campaigns' ) }
                    action={
                        <Btn variant="primary" onClick={ () => setPickerOpen( true ) } disabled={ creating }>
                            { __( 'Add your first form', 'giveflow-fundraising-campaigns' ) }
                        </Btn>
                    }
                />
            ) : (
                <div style={ { padding: '8px 0' } } className="giveflow-dataviews">
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
    { value: 'amount',    label: __( 'Amount raised', 'giveflow-fundraising-campaigns' ) },
    { value: 'donations', label: __( 'Number of donations', 'giveflow-fundraising-campaigns' ) },
    { value: 'donors',    label: __( 'Number of donors', 'giveflow-fundraising-campaigns' ) },
];

const SUB_TABS = [
    { key: 'general',    label: __( 'General', 'giveflow-fundraising-campaigns' ),    Icon: IconGeneral },
    { key: 'goal',       label: __( 'Goal', 'giveflow-fundraising-campaigns' ),       Icon: IconGoal },
    { key: 'appearance', label: __( 'Appearance', 'giveflow-fundraising-campaigns' ), Icon: IconAppearance },
    { key: 'defaults',   label: __( 'Defaults', 'giveflow-fundraising-campaigns' ),   Icon: IconDefaults },
    { key: 'advanced',   label: __( 'Advanced', 'giveflow-fundraising-campaigns' ),   Icon: IconAdvanced },
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
    title:                __( 'Identity', 'giveflow-fundraising-campaigns' ),
    description:          __( 'Identity', 'giveflow-fundraising-campaigns' ),
    slug:                 __( 'Public address', 'giveflow-fundraising-campaigns' ),
    image_attachment_id:  __( 'Cover image', 'giveflow-fundraising-campaigns' ),
    image_url:            __( 'Cover image', 'giveflow-fundraising-campaigns' ),
    status:               __( 'Status & schedule', 'giveflow-fundraising-campaigns' ),
    starts_at:            __( 'Status & schedule', 'giveflow-fundraising-campaigns' ),
    ends_at:              __( 'Status & schedule', 'giveflow-fundraising-campaigns' ),
    goal_type:            __( 'Goal', 'giveflow-fundraising-campaigns' ),
    goal_cents:           __( 'Goal', 'giveflow-fundraising-campaigns' ),
    goal_count:           __( 'Goal', 'giveflow-fundraising-campaigns' ),
    style:                __( 'Appearance', 'giveflow-fundraising-campaigns' ),
    hide_header:          __( 'Page header & footer', 'giveflow-fundraising-campaigns' ),
    hide_footer:          __( 'Page header & footer', 'giveflow-fundraising-campaigns' ),
    default_form_id:      __( 'Default form', 'giveflow-fundraising-campaigns' ),
    default_fund_id:      __( 'Default fund', 'giveflow-fundraising-campaigns' ),
} );

function SettingsTab( { campaign, onError } ) {
    const c = useGiveFlowRecord( 'campaign', campaign.id );
    const extSubTabs = useExtensionTabs( 'campaign-settings' );

    const [ subTab, setSubTab ] = useState( 'general' );
    const [ funds, setFunds ]   = useState( [] );
    const [ forms, setForms ]   = useState( [] );

    useEffect( () => {
        apiFetch( { path: addQueryArgs( '/giveflow/v1/admin/campaigns/funds', {
            include: campaign?.default_fund_id || undefined,
        } ) } )
            .then( setFunds )
            .catch( () => onError?.( __( 'Could not load funds.', 'giveflow-fundraising-campaigns' ) ) );
        apiFetch( { path: `/giveflow/v1/admin/forms?campaign_id=${ campaign.id }&per_page=100` } )
            .then( ( res ) => setForms( Array.isArray( res ) ? res : ( res?.items || [] ) ) )
            .catch( () => onError?.( __( 'Could not load forms.', 'giveflow-fundraising-campaigns' ) ) );
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
            notify.success( __( 'Campaign saved.', 'giveflow-fundraising-campaigns' ) );
        } catch ( err ) {
            onError( err?.message || __( 'Save failed.', 'giveflow-fundraising-campaigns' ) );
        }
    };

    const onDiscard = () => {
        if ( c.discard ) c.discard();
        else if ( c.reset ) c.reset();
    };

    return (
        <div>
            <div className="giveflow-subtabs" role="tablist">
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
                            { isDirty && <span className="giveflow-subtabs__dot" title={ __( 'Unsaved changes', 'giveflow-fundraising-campaigns' ) } /> }
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
                    <div className={ `giveflow-settings-layout${ showRail ? '' : ' giveflow-settings-layout--no-rail' }` }>
                        <div className="giveflow-settings-layout__main">
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
                                <aside className="giveflow-settings-layout__rail">
                                    <StylePreview
                                        tokens={ inline }
                                        presetId={ presetId }
                                        campaign={ c.record }
                                        layer="campaign"
                                        styling={ window.giveflow?.styling || {} }
                                    />
                                </aside>
                            );
                        } )() }
                    </div>
                );
            } )() }

            { c.isDirty && (
                <div className="giveflow-save-bar" role="status" aria-live="polite">
                    <span className="giveflow-save-bar__dot" aria-hidden="true" />
                    <span className="giveflow-save-bar__count">
                        <strong>{ editsCount }</strong>{ ' ' }
                        { _n( 'unsaved change', 'unsaved changes', editsCount, 'giveflow-fundraising-campaigns' ) }
                        { dirtyCardNames.length > 0 && (
                            <em>{ ' ' }{ __( 'in', 'giveflow-fundraising-campaigns' ) } { formatCardNames( dirtyCardNames ) }</em>
                        ) }
                    </span>
                    <button
                        type="button"
                        className="giveflow-save-bar__btn giveflow-save-bar__btn--ghost"
                        onClick={ onDiscard }
                        disabled={ c.isSaving }
                    >
                        { __( 'Discard', 'giveflow-fundraising-campaigns' ) }
                    </button>
                    <button
                        type="button"
                        className="giveflow-save-bar__btn giveflow-save-bar__btn--primary"
                        onClick={ onSave }
                        disabled={ c.isSaving }
                    >
                        { __( 'Save changes', 'giveflow-fundraising-campaigns' ) }
                    </button>
                </div>
            ) }
        </div>
    );
}

function CampaignTypeCard( { campaign } ) {
    const types   = window.giveflow?.campaign_types || {};
    const notices = window.giveflow?.campaign_type_notices || {};
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
                path:   `/giveflow/v1/admin/campaigns/${ campaign.id }`,
                method: 'PUT',
                data:   { campaign_type: target },
            } );
            window.location.reload();
        } catch ( err ) {
            setBusy( false );
            setTarget( null );
            notify.error( err?.message || __( 'Conversion failed.', 'giveflow-fundraising-campaigns' ) );
        }
    };

    return (
        <Card
            title={ __( 'Campaign type', 'giveflow-fundraising-campaigns' ) }
            sub={ __( 'Set when the campaign is created. A standard campaign can be converted to a richer type, but not back.', 'giveflow-fundraising-campaigns' ) }
        >
            <FormRow label={ __( 'Type', 'giveflow-fundraising-campaigns' ) }>
                <div style={ { display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' } }>
                    <strong>{ types[ current ] || __( 'Standard', 'giveflow-fundraising-campaigns' ) }</strong>
                    { convertible.map( ( t ) => (
                        <Btn key={ t } variant="secondary" onClick={ () => setTarget( t ) }>
                            { sprintf( /* translators: %s: campaign type label */ __( 'Convert to %s', 'giveflow-fundraising-campaigns' ), types[ t ] ) }
                        </Btn>
                    ) ) }
                </div>
            </FormRow>

            { target && (
                <Modal
                    title={ sprintf( /* translators: %s: campaign type label */ __( 'Convert to %s', 'giveflow-fundraising-campaigns' ), types[ target ] ) }
                    onRequestClose={ () => ! busy && setTarget( null ) }
                >
                    { notices[ target ] && <p>{ notices[ target ] }</p> }
                    <p style={ { fontWeight: 600 } }>{ __( "This can't be undone.", 'giveflow-fundraising-campaigns' ) }</p>
                    <div style={ { display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 16 } }>
                        <Btn variant="tertiary" onClick={ () => setTarget( null ) } disabled={ busy }>
                            { __( 'Cancel', 'giveflow-fundraising-campaigns' ) }
                        </Btn>
                        <Btn variant="primary" onClick={ convert } disabled={ busy }>
                            { busy ? __( 'Converting…', 'giveflow-fundraising-campaigns' ) : __( 'Convert', 'giveflow-fundraising-campaigns' ) }
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
            title={ __( 'Public address', 'giveflow-fundraising-campaigns' ) }
            sub={ __( 'The URL donors land on. Changing this may break inbound links.', 'giveflow-fundraising-campaigns' ) }
            edited={ edited ? 1 : 0 }
        >
            <FormRow
                label={ __( 'Slug', 'giveflow-fundraising-campaigns' ) }
                help={ __( 'Letters, numbers, and hyphens only.', 'giveflow-fundraising-campaigns' ) }
            >
                <div className={ `giveflow-input-prefixed${ edited ? ' is-edited' : '' }` }>
                    <span className="giveflow-input-prefixed__prefix">{ addr.prefix || `${ origin }/campaigns/` }</span>
                    <input type="text" className="giveflow-input" { ...c.bind( 'slug' ) } />
                </div>
                { addr.url && (
                    <div className="giveflow-url-preview">
                        <span className="lbl">{ __( 'Public URL', 'giveflow-fundraising-campaigns' ) }</span>
                        <span className="url">{ addr.prefix }<em>{ addr.segment }</em></span>
                        <a href={ addr.url } target="_blank" rel="noreferrer">{ __( 'Visit page ↗', 'giveflow-fundraising-campaigns' ) }</a>
                    </div>
                ) }
                { addr.url && edited && (
                    <div className="giveflow-form-row__field-help">
                        { __( 'Saving moves the page. WordPress adds a suffix if another page already holds the slug, so check this address again afterwards.', 'giveflow-fundraising-campaigns' ) }
                    </div>
                ) }
                { ! addr.url && !! slug && (
                    <div className="giveflow-form-row__field-help">
                        { __( 'This campaign has no page yet, so it has no public address. One is created when the campaign is published.', 'giveflow-fundraising-campaigns' ) }
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
        <div className="giveflow-section-block">
            <Card
                title={ __( 'Identity', 'giveflow-fundraising-campaigns' ) }
                sub={ __( 'Title and short description, used everywhere this campaign appears.', 'giveflow-fundraising-campaigns' ) }
                edited={ editedCount( [ 'title', 'description' ] ) }
            >
                <FormRow
                    label={ __( 'Campaign title', 'giveflow-fundraising-campaigns' ) }
                    help={ __( 'Donor-facing. Appears in the page header, on cards, in receipts.', 'giveflow-fundraising-campaigns' ) }
                    fieldHelp={ __( 'Try to keep it under 6 words.', 'giveflow-fundraising-campaigns' ) }
                >
                    <input type="text" className={ inputCls( c, 'title' ) } { ...c.bind( 'title' ) } />
                </FormRow>

                <FormRow
                    label={ __( 'Short description', 'giveflow-fundraising-campaigns' ) }
                    help={ __( 'One or two sentences. Shows on campaign cards and the page hero.', 'giveflow-fundraising-campaigns' ) }
                >
                    <div className="giveflow-input-counter">
                        <textarea
                            className={ textareaCls( c, 'description' ) }
                            rows={ 4 }
                            maxLength={ DESCRIPTION_MAX + 50 }
                            { ...c.bind( 'description' ) }
                        />
                        <span className={ `giveflow-input-counter__count${ overLimit ? ' is-over' : ( nearLimit ? ' is-warn' : '' ) }` }>
                            { desc.length } / { DESCRIPTION_MAX }
                        </span>
                    </div>
                    { teaserSrc && (
                        <div className="giveflow-form-row__field-help">
                            <strong style={ { color: '#111827', fontWeight: 500 } }>{ __( 'How donors will see this:', 'giveflow-fundraising-campaigns' ) }</strong>{ ' ' }
                            { teaserSrc }
                        </div>
                    ) }
                </FormRow>
            </Card>

            <CampaignTypeCard campaign={ campaign } />

            <PublicAddressCard c={ c } pageUrl={ campaign?.page_url || r.page_url || null } />

            <Card
                title={ __( 'Cover image', 'giveflow-fundraising-campaigns' ) }
                sub={ __( 'Recommended 1600 × 900 (16:9). Shows on the page hero and campaign grid.', 'giveflow-fundraising-campaigns' ) }
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
                title={ __( 'Status & schedule', 'giveflow-fundraising-campaigns' ) }
                sub={ __( "Whether donors can give right now, and when the campaign runs.", 'giveflow-fundraising-campaigns' ) }
                edited={ editedCount( [ 'status', 'starts_at', 'ends_at' ] ) }
            >
                <FormRow
                    label={ __( 'Status', 'giveflow-fundraising-campaigns' ) }
                    help={ __( "Active campaigns accept donations. Drafts and archived campaigns don't. Archive from the campaign menu, which handles any recurring donations first.", 'giveflow-fundraising-campaigns' ) }
                >
                    <StatusPillGroup value={ c.value( 'status', 'draft' ) } onChange={ c.setValue( 'status' ) } />
                </FormRow>
                <FormRow
                    label={ __( 'Schedule', 'giveflow-fundraising-campaigns' ) }
                    help={ __( 'Optional. Leave blank for an open-ended campaign.', 'giveflow-fundraising-campaigns' ) }
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

function GoalPanel( { c } ) {
    const r = c.record;
    const editedCount = [ 'goal_type', 'goal_cents', 'goal_count' ]
        .reduce( ( n, k ) => n + ( c.edits?.[ k ] !== undefined ? 1 : 0 ), 0 );
    return (
        <div className="giveflow-section-block">
            <Card
                title={ __( 'Campaign goal', 'giveflow-fundraising-campaigns' ) }
                sub={ __( 'Drives the progress widget on the campaign page and the goal donut on the overview.', 'giveflow-fundraising-campaigns' ) }
                edited={ editedCount }
            >
                <FormRow label={ __( 'Goal type', 'giveflow-fundraising-campaigns' ) }>
                    <select className={ selectCls( c, 'goal_type' ) } { ...c.bind( 'goal_type', 'amount' ) }>
                        { GOAL_TYPES.map( ( t ) => (
                            <option key={ t.value } value={ t.value }>{ t.label }</option>
                        ) ) }
                    </select>
                </FormRow>

                { r.goal_type === 'amount' && (
                    <FormRow
                        label={ __( 'Target amount', 'giveflow-fundraising-campaigns' ) }
                        help={ __( 'Leave empty for no goal.', 'giveflow-fundraising-campaigns' ) }
                    >
                        <AmountInput
                            currency={ defaultCurrency() }
                            min={ 0 }
                            value={ r.goal_cents == null || r.goal_cents === '' ? '' : Number( r.goal_cents ) / 100 }
                            onChange={ ( v ) => c.edit( { goal_cents: v ? Math.round( v * 100 ) : null } ) }
                        />
                    </FormRow>
                ) }

                { r.goal_type === 'donations' && (
                    <FormRow label={ __( 'Target donations', 'giveflow-fundraising-campaigns' ) }>
                        <input type="number" className={ inputCls( c, 'goal_count' ) } min="0" { ...c.bindNumber( 'goal_count' ) } />
                    </FormRow>
                ) }

                { r.goal_type === 'donors' && (
                    <FormRow label={ __( 'Target donors', 'giveflow-fundraising-campaigns' ) }>
                        <input type="number" className={ inputCls( c, 'goal_count' ) } min="0" { ...c.bindNumber( 'goal_count' ) } />
                    </FormRow>
                ) }
            </Card>

            <AmbitionMeter
                campaignId={ r.id }
                goalType={ r.goal_type }
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

    const presets   = Array.isArray( window.giveflow?.styling?.presets ) ? window.giveflow.styling.presets : [];
    const defaultId = String( window.giveflow?.styling?.default_id || '' );
    // Baseline is global defaults plus the selected preset's tokens, so each
    // control shows the chosen theme's value with inline overrides on top.
    const presetBase = resolveEffectiveTokens( { tokens: {}, presetId, layer: 'campaign', styling: window.giveflow?.styling || {} } );

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
        <div className="giveflow-section-block">
            <Card
                title={ __( 'Campaign appearance', 'giveflow-fundraising-campaigns' ) }
                sub={ __( "Pick which brand preset this campaign uses. Optionally tweak individual tokens for a one-off look.", 'giveflow-fundraising-campaigns' ) }
                edited={ editedCount }
            >
                <FormRow label={ __( 'Style preset', 'giveflow-fundraising-campaigns' ) }>
                    <select
                        className="giveflow-select"
                        value={ presetId }
                        onChange={ ( e ) => selectPreset( e.target.value ) }
                    >
                        <option value="">
                            { __( 'Use org default', 'giveflow-fundraising-campaigns' ) +
                                ( defaultId ? ` (${ presets.find( ( p ) => p.id === defaultId )?.name || defaultId })` : '' ) }
                        </option>
                        { presets.map( ( p ) => (
                            <option key={ p.id } value={ p.id }>{ p.name }</option>
                        ) ) }
                    </select>
                </FormRow>

                <div className="giveflow-custom-style-toggle" style={ { marginTop: 16 } }>
                    <ToggleRow
                        title={ __( 'Customize tokens for this campaign', 'giveflow-fundraising-campaigns' ) }
                        sub={ isCustomizing
                            ? __( 'Inline overrides applied on top of the chosen preset.', 'giveflow-fundraising-campaigns' )
                            : __( 'Toggle on to tweak individual tokens without creating a new brand preset.', 'giveflow-fundraising-campaigns' )
                        }
                        checked={ isCustomizing }
                        onChange={ toggleCustomizing }
                    />
                </div>

                { isCustomizing && (
                    <div className="giveflow-custom-style-body">
                        <TokenEditor
                            value={ inline }
                            defaults={ presetBase }
                            onChange={ setInline }
                            catalogue={ window.giveflow?.styling?.catalogue || {} }
                            groups={ window.giveflow?.styling?.groups || {} }
                        />
                    </div>
                ) }
            </Card>

            <Card
                title={ __( 'Page header & footer', 'giveflow-fundraising-campaigns' ) }
                sub={ __( "Hide the theme's header or footer on every page this campaign renders on.", 'giveflow-fundraising-campaigns' ) }
                edited={ ( c.edits?.hide_header !== undefined ? 1 : 0 ) + ( c.edits?.hide_footer !== undefined ? 1 : 0 ) }
            >
                <ToggleRow
                    title={ __( 'Hide theme header', 'giveflow-fundraising-campaigns' ) }
                    checked={ !! c.value( 'hide_header', false ) }
                    onChange={ ( v ) => c.edit( { hide_header: v } ) }
                />
                <div style={ { marginTop: 16 } }>
                    <ToggleRow
                        title={ __( 'Hide theme footer', 'giveflow-fundraising-campaigns' ) }
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
        <div className="giveflow-section-block">
            <Card
                title={ __( 'Default form', 'giveflow-fundraising-campaigns' ) }
                sub={ __( 'The form the campaign page and donate-button block submit to by default.', 'giveflow-fundraising-campaigns' ) }
                edited={ hasFormEdit ? 1 : 0 }
            >
                <FormRow
                    label={ __( 'Form', 'giveflow-fundraising-campaigns' ) }
                    help={ __( 'Only published forms that belong to this campaign appear here. A draft cannot be the default: the page would fall back to a different form without saying so.', 'giveflow-fundraising-campaigns' ) }
                >
                    <div className="giveflow-grid-2-eq" style={ { gridTemplateColumns: '1fr auto', alignItems: 'center' } }>
                        <select className={ selectCls( c, 'default_form_id' ) } { ...c.bindNumber( 'default_form_id' ) }>
                            <option value="">{ __( '( None )', 'giveflow-fundraising-campaigns' ) }</option>
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
                                                __( '%s (not published)', 'giveflow-fundraising-campaigns' ),
                                                f.title
                                            ) }
                                    </option>
                                ) ) }
                        </select>
                        { r.default_form_id && (
                            <Btn variant="ghost" size="sm" href={ formEditorHref( Number( r.default_form_id ) ) }>
                                { __( 'Edit form', 'giveflow-fundraising-campaigns' ) } →
                            </Btn>
                        ) }
                    </div>
                </FormRow>
            </Card>

            <Card
                title={ __( 'Default fund', 'giveflow-fundraising-campaigns' ) }
                sub={ __( 'Where donations from this campaign are routed. Useful when separating restricted donations from general operations.', 'giveflow-fundraising-campaigns' ) }
                edited={ hasFundEdit ? 1 : 0 }
            >
                <FormRow label={ __( 'Fund', 'giveflow-fundraising-campaigns' ) }>
                    <select className={ selectCls( c, 'default_fund_id' ) } { ...c.bindNumber( 'default_fund_id' ) }>
                        <option value="">{ __( '( Unassigned )', 'giveflow-fundraising-campaigns' ) }</option>
                        { funds.map( ( f ) => (
                            <option key={ f.id } value={ f.id }>
                                { f.is_active === false
                                    ? sprintf(
                                        /* translators: %s: fund name */
                                        __( '%s (inactive)', 'giveflow-fundraising-campaigns' ),
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
            title:        __( 'Delete campaign', 'giveflow-fundraising-campaigns' ),
            message,
            confirmLabel: __( 'Delete', 'giveflow-fundraising-campaigns' ),
            destructive:  true,
            onConfirm: async () => {
                setDeleting( true );
                try {
                    await apiFetch( {
                        path:   `/giveflow/v1/admin/campaigns/${ campaign.id }`,
                        method: 'DELETE',
                    } );
                    window.location.href = listHref();
                } catch ( err ) {
                    onError( err?.message || __( 'Delete failed.', 'giveflow-fundraising-campaigns' ) );
                    setDeleting( false );
                }
            },
        } );
    };

    return (
        <div className="giveflow-section-block">
            <Card
                title={ __( 'Danger zone', 'giveflow-fundraising-campaigns' ) }
                sub={ __( 'Irreversible actions. Use with care.', 'giveflow-fundraising-campaigns' ) }
            >
                <div className="giveflow-danger">
                    <div className="giveflow-danger__copy">
                        <div className="giveflow-danger__title">{ __( 'Delete this campaign', 'giveflow-fundraising-campaigns' ) }</div>
                        <div className="giveflow-danger__help">
                            { __(
                                'Removes the campaign, its forms, and the WordPress page it created. A campaign that has any donations or recurring plans is never deleted: archive it instead to keep its records.',
                                'giveflow-fundraising-campaigns'
                            ) }
                        </div>
                    </div>
                    <Btn variant="danger" onClick={ onDelete } isBusy={ deleting } disabled={ deleting }>
                        { __( 'Delete campaign', 'giveflow-fundraising-campaigns' ) }
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
        <div className="giveflow-status-pills" role="radiogroup">
            { options.map( ( [ key, label ] ) => (
                <button
                    key={ key }
                    type="button"
                    role="radio"
                    data-state={ key }
                    aria-checked={ value === key }
                    className={ `giveflow-status-pill${ value === key ? ' is-active' : '' }` }
                    onClick={ () => onChange( key ) }
                >
                    <span className="giveflow-status-pill__dot" />
                    { label }
                </button>
            ) ) }
        </div>
    );
}
