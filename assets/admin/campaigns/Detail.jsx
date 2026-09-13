import { useState, useEffect, useCallback, useRef, useMemo, Fragment } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { DataViews } from '@wordpress/dataviews';
import { Modal, Spinner, Button, CheckboxControl } from '@wordpress/components';
import Notice from '../_shared/components/Notice';
import ConfirmDialog from '../_shared/components/ConfirmDialog';
import { notify } from '../_shared/notify';
import { __, _n, sprintf } from '@wordpress/i18n';

import { useGratoraRecord } from '../_shared/useGratoraRecord';
import { rowLinkProps, stopRowSelect } from '../_shared/rowLink';
import { StatusBadge, STATUS_LABEL, formatAmount, defaultCurrency, formatDate, timeAgo, listHref, detailHref, formEditorHref } from '../_shared/format';
import Card from '../_shared/components/Card';
import FormRow from '../_shared/components/FormRow';
import { ToggleRow } from '../_shared/components/Switch';
import Btn from '../_shared/components/Btn';
import { downloadFile } from '../_shared/download';
import TokenEditor from '../_shared/styling/TokenEditor';
import UnshownNotice from '../_shared/styling/UnshownNotice';
import { Copy as CopyIcon, Trash2 as TrashIcon, Coins, HandHeart, Users as UsersIcon, ListChecks, Plus, Download as DownloadIcon, AlertTriangle } from 'lucide-react';
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
import { useGratoraLayout } from '../_shared/widgets/useGratoraLayout';
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
            path:  `/gratora/v1/admin/forms?campaign_id=${ campaign?.id }&per_page=1`,
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
                'gratora-donation-platform'
            ),
            count,
        )
        : __( 'Permanently delete this campaign?', 'gratora-donation-platform' ) ];

    // The page is deleted outright rather than trashed, so an admin who has
    // built it out in the block editor has nothing left to restore from.
    if ( campaign?.page_id ) {
        parts.push( __( 'The WordPress page it created is deleted with it, and it does not go to the trash, so any content you built on that page is gone for good.', 'gratora-donation-platform' ) );
    }

    parts.push( __( 'This cannot be undone.', 'gratora-donation-platform' ) );

    return parts.join( ' ' );
}

/**
 * One gate for every place that offers the delete, so a second caller cannot
 * skip it. The reason is the server's own, not one worked out here from
 * donations_count: that counter ignores pending, failed, test-mode and ticket
 * rows, every one of which still blocks a delete.
 */
export async function campaignDeleteConfirm( campaign, { onArchive, onDelete } ) {
    if ( campaign?.delete_blocked ) {
        return {
            title:        __( 'This campaign cannot be deleted', 'gratora-donation-platform' ),
            message:      campaign.delete_blocked,
            confirmLabel: __( 'Archive instead', 'gratora-donation-platform' ),
            onConfirm:    onArchive,
        };
    }

    return {
        title:        __( 'Delete campaign', 'gratora-donation-platform' ),
        message:      await campaignDeleteMessage( campaign ),
        confirmLabel: __( 'Delete', 'gratora-donation-platform' ),
        destructive:  true,
        onConfirm:    onDelete,
    };
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
        draft:     __( 'This campaign is a draft, so it is not taking donations yet. Anyone who opens its form is turned away.', 'gratora-donation-platform' ),
        archived:  __( 'This campaign is archived and is not taking donations.', 'gratora-donation-platform' ),
        scheduled: __( 'This campaign has not started yet, so it is not taking donations until its start date.', 'gratora-donation-platform' ),
        ended:     __( 'This campaign has ended and is no longer taking donations.', 'gratora-donation-platform' ),
        goal_met:  __( 'This campaign has reached its goal and is set to close when it does, so it is no longer taking donations. Raise the target or turn that setting off in Goal to reopen it.', 'gratora-donation-platform' ),
    };

    return COPY[ reason ] || __( 'This campaign is not taking donations.', 'gratora-donation-platform' );
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
                        { __( 'Publish it now', 'gratora-donation-platform' ) }
                    </Button>
                </>
            ) }
        </Notice>
    );
}

export default function Detail( { id, tab } ) {
    const c = useGratoraRecord( 'campaign', id );
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
            __( 'Duplicated from "%s". Review and rename before publishing.', 'gratora-donation-platform' ),
            from,
        ) );
        params.delete( 'duplicated_from' );
        const url = new URL( window.location.href );
        url.search = params.toString();
        window.history.replaceState( {}, '', url.toString() );
    }, [] );

    if ( c.isLoading || ( ! c.savedRecord && ! c.notFound && ! c.loadError ) ) {
        return <div style={ { padding: 40, textAlign: 'center' } }><Spinner /></div>;
    }
    // A request that failed is not a campaign that is gone, and telling the
    // reader it is leaves them with nothing to do about it.
    if ( c.loadError ) {
        return (
            <div style={ { padding: 24 } }>
                <Notice status="error" isDismissible={ false }>
                    { c.loadError.message || __( 'This campaign could not be loaded.', 'gratora-donation-platform' ) }
                </Notice>
                <p>
                    <Btn variant="secondary" onClick={ c.reload }>{ __( 'Try again', 'gratora-donation-platform' ) }</Btn>
                    { ' ' }
                    <Btn href={ listHref() }>{ __( 'Back to campaigns', 'gratora-donation-platform' ) }</Btn>
                </p>
            </div>
        );
    }
    if ( c.notFound ) {
        return (
            <Notice status="error" isDismissible={ false }>
                { __( 'Campaign not found.', 'gratora-donation-platform' ) }
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
                path: `/gratora/v1/admin/campaigns/${ campaign.id }`,
                method: 'PUT',
                data: { status: nextStatus, ...( cancelRecurring ? { cancel_recurring: true } : {} ) },
            } );
            // Cancellation runs in the background: each plan is a gateway round
            // trip and a campaign can have thousands, so the request cannot
            // know a failure count yet.
            const queued = res?.recurring_cancel?.queued || 0;
            if ( queued > 0 ) {
                // Refresh the record, not the document: the confirmation is a
                // toast in an in-memory store, and a reload throws it away
                // before it paints. The count of cancelled subscriptions is the
                // only place that number is ever said.
                notify.success( sprintf(
                    /* translators: %d: number of subscriptions */
                    _n(
                        'Campaign archived. Cancelling %d subscription in the background.',
                        'Campaign archived. Cancelling %d subscriptions in the background.',
                        queued,
                        'gratora-donation-platform'
                    ),
                    queued
                ) );
                c.reload();
                return;
            }
            notify.success( nextStatus === 'archived'
                ? __( 'Campaign archived.', 'gratora-donation-platform' )
                : __( 'Campaign restored to draft.', 'gratora-donation-platform' ) );
            c.reload();
        } catch ( err ) {
            setError( err?.message || __( 'Update failed.', 'gratora-donation-platform' ) );
        }
    };

    const onHeaderAction = async ( name ) => {
        if ( name === 'duplicate' ) {
            try {
                const dup = await apiFetch( {
                    path: `/gratora/v1/admin/campaigns/${ campaign.id }/duplicate`,
                    method: 'POST',
                } );
                if ( dup?.id ) {
                    const url = addQueryArgs( detailHref( dup.id, 'settings' ), {
                        duplicated_from: campaign.title,
                    } );
                    window.location.href = url;
                }
            } catch ( err ) {
                setError( err?.message || __( 'Duplicate failed.', 'gratora-donation-platform' ) );
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
                    path: `/gratora/v1/admin/campaigns/${ campaign.id }/recurring-summary`,
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
                    path: `/gratora/v1/admin/campaigns/${ campaign.id }`,
                    method: 'PUT',
                    data: { status: nextStatus },
                } );
                notify.success( name === 'publish'
                    ? __( 'Campaign published.', 'gratora-donation-platform' )
                    : __( 'Campaign moved to draft.', 'gratora-donation-platform' ) );
                c.reload();
            } catch ( err ) {
                setError( err?.message || __( 'Update failed.', 'gratora-donation-platform' ) );
            }
            return;
        }
        if ( name === 'delete' ) {
            setConfirm( await campaignDeleteConfirm( campaign, {
                onArchive: () => onHeaderAction( 'archive' ),
                onDelete: async () => {
                    try {
                        await apiFetch( {
                            path: `/gratora/v1/admin/campaigns/${ campaign.id }`,
                            method: 'DELETE',
                        } );
                        window.location.href = listHref();
                    } catch ( err ) {
                        setError( err?.message || __( 'Delete failed.', 'gratora-donation-platform' ) );
                    }
                },
            } ) );
        }
    };

    return (
        <div className="gratora-campaign-detail">
            <Header campaign={ campaign } />

            <NotAcceptingNotice campaign={ campaign } onPublish={ () => onHeaderAction( 'publish' ) } />

            { error && (
                <Notice status="error" onRemove={ () => setError( null ) }>{ error }</Notice>
            ) }

            <div className="gratora-campaign-detail__body">
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
                            onArchive={ () => onHeaderAction( 'archive' ) }
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
                    title={ __( 'Archive campaign', 'gratora-donation-platform' ) }
                    onRequestClose={ () => setArchivePrompt( null ) }
                >
                    <p style={ { marginTop: 0 } }>
                        { sprintf(
                            /* translators: %d: number of live recurring donations */
                            _n(
                                'This campaign has %d live recurring donation.',
                                'This campaign has %d live recurring donations.',
                                archivePrompt.count,
                                'gratora-donation-platform'
                            ),
                            archivePrompt.count
                        ) }
                        { ' ' }
                        { __( 'Live counts active, paused and past-due donations: a paused one resumes and a past-due one is still being retried.', 'gratora-donation-platform' ) }
                        { archivePrompt.mrr_cents > 0 && ' ' + sprintf(
                            /* translators: %s: formatted monthly amount */
                            __( 'About %s a month is at stake.', 'gratora-donation-platform' ),
                            formatAmount( archivePrompt.mrr_cents, archivePrompt.currency )
                        ) }
                    </p>
                    <p>
                        { __( 'Archiving stops new donations. These subscriptions are left as they are and stay credited to this campaign unless you cancel them.', 'gratora-donation-platform' ) }
                    </p>
                    <CheckboxControl
                        label={ __( 'Also cancel these subscriptions (donors will be emailed)', 'gratora-donation-platform' ) }
                        checked={ cancelSubs }
                        onChange={ setCancelSubs }
                        __nextHasNoMarginBottom
                    />
                    <div style={ { display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 20 } }>
                        <Button variant="tertiary" onClick={ () => setArchivePrompt( null ) }>
                            { __( 'Cancel', 'gratora-donation-platform' ) }
                        </Button>
                        <Button
                            variant="primary"
                            isDestructive={ cancelSubs }
                            onClick={ () => {
                                setArchivePrompt( null );
                                runArchive( 'archived', cancelSubs );
                            } }
                        >
                            { __( 'Archive campaign', 'gratora-donation-platform' ) }
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
            <div className="gratora-crumbs">
                <a href={ listHref() }>{ __( 'Campaigns', 'gratora-donation-platform' ) }</a>
                <span className="sep">›</span>
                <span>{ campaign.title }</span>
            </div>
            <div className="gratora-page-head">
                <div className="gratora-page-head__left">
                    <h1>{ campaign.title }</h1>
                    <div className="gratora-page-head__meta-row">
                        { campaign.campaign_type && campaign.campaign_type !== 'standard' && campaign.campaign_type_label && (
                            <span className="gratora-pill gratora-pill--lg gratora-pill--type">
                                { campaign.campaign_type_label }
                            </span>
                        ) }
                        <span className={ `gratora-pill gratora-pill--lg ${ statusPillClass( campaign.status ) }` }>
                            { STATUS_LABEL[ campaign.status ] || campaign.status }
                        </span>
                        { lastSaved && <span className="gratora-page-head__meta">{ lastSaved }</span> }
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
    { id: 'overview', label: __( 'Overview', 'gratora-donation-platform' ), Icon: IconOverview },
    { id: 'forms',    label: __( 'Forms',    'gratora-donation-platform' ), Icon: IconFormsTab },
    { id: 'settings', label: __( 'Settings', 'gratora-donation-platform' ), Icon: IconSettingsTab },
];

function ViewToggle( { active, campaignId, extra = [] } ) {
    const views = [ ...VIEW_TOGGLE_DEFS, ...extra ];
    return (
        <div className="gratora-view-toggle" role="tablist" aria-label={ __( 'Campaign sections', 'gratora-donation-platform' ) }>
            { views.map( ( t ) => (
                <a
                    key={ t.id }
                    role="tab"
                    aria-selected={ active === t.id }
                    href={ detailHref( campaignId, t.id ) }
                    className={ `gratora-cmp-toggle${ active === t.id ? ' is-active' : '' }` }
                >
                    { t.Icon ? <t.Icon /> : null }
                    { t.label }
                    { !! t.badge && <span className="gratora-cmp-toggle__badge">{ t.badge }</span> }
                </a>
            ) ) }
        </div>
    );
}

function DetailNav( { campaign, activeTab, onAction, extraTabs = [] } ) {
    return (
        <div className="gratora-detail-nav">
            <ViewToggle active={ activeTab } campaignId={ campaign.id } extra={ extraTabs } />
            { campaign.page_edit_url && ( ! campaign.campaign_type || campaign.campaign_type === 'standard' ) && (
                // Non-standard types (e.g. peer-to-peer) manage their pages from
                // their own admin tab, so the single-page edit link is redundant.
                <Btn href={ campaign.page_edit_url }>{ __( 'Edit campaign page', 'gratora-donation-platform' ) }</Btn>
            ) }
            { campaign.page_url && (
                <Btn variant="ghost" href={ campaign.page_url } target="_blank" rel="noreferrer">
                    { __( 'View page ↗', 'gratora-donation-platform' ) }
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
        const items = Array.from( ref.current?.querySelectorAll( '.gratora-menu__item' ) || [] );
        if ( ! items.length ) return;
        const idx  = items.indexOf( ref.current.ownerDocument.activeElement );
        const next = e.key === 'ArrowDown'
            ? ( idx + 1 ) % items.length
            : ( idx <= 0 ? items.length - 1 : idx - 1 );
        items[ next ].focus();
    };

    return (
        // eslint-disable-next-line jsx-a11y/no-static-element-interactions -- menu-widget wrapper; onKeyDown coordinates roving focus among the role=menuitem buttons in the popup below
        <div className="gratora-menu" ref={ ref } onKeyDown={ onKeyDown }>
            <button
                type="button"
                ref={ triggerRef }
                className="gratora-menu__trigger"
                aria-label={ __( 'Campaign actions', 'gratora-donation-platform' ) }
                aria-haspopup="menu"
                aria-expanded={ open }
                onClick={ () => setOpen( ( v ) => ! v ) }
            >
                ⋯
            </button>
            { open && (
                <div className="gratora-menu__list" role="menu">
                    { isDraft && (
                        <button type="button" role="menuitem" className="gratora-menu__item is-primary" onClick={ () => fire( 'publish' ) }>
                            { __( 'Publish campaign', 'gratora-donation-platform' ) }
                        </button>
                    ) }
                    { isPublished && (
                        <button type="button" role="menuitem" className="gratora-menu__item" onClick={ () => fire( 'unpublish' ) }>
                            { __( 'Move to draft', 'gratora-donation-platform' ) }
                        </button>
                    ) }
                    <button type="button" role="menuitem" className="gratora-menu__item" onClick={ () => fire( 'duplicate' ) }>
                        { __( 'Duplicate campaign', 'gratora-donation-platform' ) }
                    </button>
                    <button type="button" role="menuitem" className="gratora-menu__item" onClick={ () => fire( isArchived ? 'unarchive' : 'archive' ) }>
                        { isArchived ? __( 'Restore to draft', 'gratora-donation-platform' ) : __( 'Archive campaign', 'gratora-donation-platform' ) }
                    </button>
                    <button type="button" role="menuitem" className="gratora-menu__item is-danger" onClick={ () => fire( 'delete' ) }>
                        { __( 'Delete…', 'gratora-donation-platform' ) }
                    </button>
                </div>
            ) }
        </div>
    );
}

function inputCls( c, key, extra = '' ) {
    return `gratora-input${ c.isEdited( key ) ? ' gratora-input--edited' : '' }${ extra ? ' ' + extra : '' }`;
}
function textareaCls( c, key, extra = '' ) {
    return `gratora-textarea${ c.isEdited( key ) ? ' gratora-textarea--edited' : '' }${ extra ? ' ' + extra : '' }`;
}
function selectCls( c, key ) {
    return `gratora-select${ c.isEdited( key ) ? ' gratora-input--edited' : '' }`;
}

function formatCardNames( names ) {
    if ( names.length === 0 ) return '';
    if ( names.length <= 3 ) return names.join( ', ' );
    return sprintf(
        /* translators: 1: first three section names, 2: count of remaining */
        __( '%1$s, and %2$d more', 'gratora-donation-platform' ),
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
        __( 'Last saved %s', 'gratora-donation-platform' ),
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
    const [ metrics, setMetrics ] = useState( null );
    const [ loading, setLoading ] = useState( true );
    const [ fetchError, setFetchError ] = useState( false );
    const [ reloadKey, setReloadKey ] = useState( 0 );

    // Layout is a UI preference shared across all campaigns, not per-campaign.
    const layout = useGratoraLayout( 'campaign_overview', WIDGET_KEYS );

    const includeKey = useMemo( () => layout.visibleOrder.join( ',' ), [ layout.visibleOrder ] );

    // What the held metrics were fetched for, so showing a widget again asks
    // only for what is missing and hiding one asks for nothing.
    const fetched = useRef( { signature: '', keys: new Set() } );

    useEffect( () => {
        // The saved layout lands after the first render, so asking before it
        // does spends the aggregate on a widget set nobody chose.
        if ( ! layout.loaded ) {
            return undefined;
        }

        const signature = `${ campaign.id }|${ range }|${ compareMode }|${ reloadKey }`;
        const wanted    = includeKey ? includeKey.split( ',' ) : [];
        const fresh     = fetched.current.signature !== signature;

        if ( ! fresh && wanted.every( ( k ) => fetched.current.keys.has( k ) ) ) {
            return undefined;
        }

        const include = fresh
            ? wanted
            : [ ...new Set( [ ...fetched.current.keys, ...wanted ] ) ];

        let aborted = false;
        setLoading( true );
        setFetchError( false );
        const url = `/gratora/v1/admin/campaigns/${ campaign.id }/metrics`
            + `?range=${ range }&compare=${ compareMode }&include=${ encodeURIComponent( include.join( ',' ) ) }`;
        apiFetch( { path: url } )
            .then( ( m ) => {
                if ( aborted ) return;
                // Recorded on arrival, not on request: a layout tweak mid-load
                // aborts this one, and keys recorded up front would make the
                // re-run think it already had them.
                fetched.current = { signature, keys: new Set( include ) };
                setMetrics( ( prev ) => ( { ...( prev || {} ), ...m } ) );
            } )
            // Zero-filled defaults next to a goal card reading the campaign's
            // real lifetime total is a screen that measured nothing and says it
            // measured zero, so a load that never landed shows as itself.
            .catch( ( e ) => {
                if ( aborted ) return;
                setFetchError( true );
                if ( metrics ) {
                    onError?.( e?.message || __( 'Could not load campaign metrics.', 'gratora-donation-platform' ) );
                }
            } )
            .finally( () => { if ( ! aborted ) setLoading( false ); } );
        return () => { aborted = true; };
    }, [ range, compareMode, campaign.id, includeKey, reloadKey, layout.loaded ] ); // eslint-disable-line react-hooks/exhaustive-deps

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
            title: __( 'Key metrics', 'gratora-donation-platform' ),
            span:  'full',
            bare:  true,
            render: () => (
                <div className="gratora-overview__metrics">
                    <GoalProgressCard campaign={ campaign } metrics={ m } />
                    <MetricCard label={ __( 'Amount raised', 'gratora-donation-platform' ) }
                                value={ formatAmount( m.amount_raised_cents ) }
                                changePct={ cmp?.amount_raised_cents }
                                icon={ <IconCoins /> } />
                    <MetricCard label={ __( 'Donations', 'gratora-donation-platform' ) }
                                value={ String( m.donations_count ) }
                                changePct={ cmp?.donations_count }
                                icon={ <IconHeart /> } />
                    <MetricCard label={ __( 'Donors', 'gratora-donation-platform' ) }
                                value={ String( m.donors_count ) }
                                changePct={ cmp?.donors_count }
                                icon={ <IconUsers /> } />
                    <MetricCard label={ __( 'Average donation', 'gratora-donation-platform' ) }
                                value={ formatAmount( m.avg_donation_cents ) }
                                changePct={ cmp?.avg_donation_cents }
                                icon={ <IconActivity /> } />
                </div>
            ),
        },
        revenue: {
            title: __( 'Revenue', 'gratora-donation-platform' ),
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
            title: __( 'Donor cohort', 'gratora-donation-platform' ),
            render: () => <DonorCohort cohort={ m.cohort } />,
        },
        distribution: {
            title: __( 'Donation shape', 'gratora-donation-platform' ),
            render: () => <DistributionHistogram distribution={ m.distribution } currency={ defaultCurrency() } />,
        },
        heatmap: {
            title: __( 'When donors give', 'gratora-donation-platform' ),
            span:  'full',
            render: () => <DowHourHeatmap data={ m.dow_hour } />,
        },
        timeline: {
            title: __( 'Timeline', 'gratora-donation-platform' ),
            render: () => <TimelineCard timeline={ m.timeline } />,
        },
        stories: {
            title: __( 'Stories', 'gratora-donation-platform' ),
            render: () => <Stories rows={ m.notes || [] } />,
        },
        recent: {
            title: __( 'Recent donations', 'gratora-donation-platform' ),
            render: () => <RecentDonations rows={ m.recent_donations } />,
        },
        'top-donors': {
            title: __( 'Top donors', 'gratora-donation-platform' ),
            render: () => <TopDonors rows={ m.top_donors } currency={ defaultCurrency() } />,
        },
        'top-forms': {
            title: __( 'Top forms', 'gratora-donation-platform' ),
            render: () => <TopForms rows={ m.top_forms } currency={ defaultCurrency() } donationsCount={ m.donations_count } />,
        },
        channel: {
            title: __( 'By channel', 'gratora-donation-platform' ),
            render: () => <ChannelBreakdown rows={ m.by_channel } currency={ defaultCurrency() } />,
        },
        gateway: {
            title: __( 'By payment method', 'gratora-donation-platform' ),
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
                        addQueryArgs( `/gratora/v1/reports/campaign/${ campaignId }/pdf`, { range: reportRange } ),
                        `campaign-${ campaignId }.pdf`
                    );
                } catch ( err ) {
                    notify.error( err?.message || __( 'Could not build the report.', 'gratora-donation-platform' ) );
                } finally {
                    setBusy( false );
                }
            } }
        >
            { __( 'Download report', 'gratora-donation-platform' ) }
        </Btn>
    );
}

    return (
        <div className="gratora-overview" data-loading={ loading ? 'true' : undefined }>
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
            { ! metrics && fetchError ? (
                <EmptyState
                    icon={ <AlertTriangle size={ 24 } strokeWidth={ 1.75 } /> }
                    title={ __( 'Could not load these metrics', 'gratora-donation-platform' ) }
                    body={ __( 'Nothing was measured, so nothing is shown. Check your connection and try again.', 'gratora-donation-platform' ) }
                    action={
                        <Btn variant="primary" onClick={ () => setReloadKey( ( n ) => n + 1 ) }>
                            { __( 'Try again', 'gratora-donation-platform' ) }
                        </Btn>
                    }
                />
            ) : (
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
            ) }
        </div>
    );
}


function TimelineCard( { timeline } ) {
    if ( ! timeline ) return null;

    const { kind, days, total_days } = timeline;
    const label = kind === 'remaining'
        ? __( 'Days remaining', 'gratora-donation-platform' )
        : kind === 'ended'
            ? __( 'Days since ended', 'gratora-donation-platform' )
            : __( 'Days running', 'gratora-donation-platform' );

    const pct = total_days
        ? Math.min( 100, Math.round( ( ( total_days - days ) / total_days ) * 100 ) )
        : null;

    return (
        <div className="gratora-timeline">
            <div className="gratora-timeline__label">{ label }</div>
            <div className="gratora-timeline__value">{ days }</div>
            <div className="gratora-timeline__sub">
                { kind === 'remaining' && total_days &&
                    sprintf( /* translators: %d: total days */ __( 'of %d total', 'gratora-donation-platform' ), total_days ) }
            </div>
            { pct !== null && (
                <div className="gratora-timeline__bar">
                    <div className="gratora-timeline__bar-fill" style={ { width: `${ pct }%` } } />
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
                title={ __( 'No donations yet', 'gratora-donation-platform' ) }
                body={ __( 'Recent donor activity will appear here once your form is live and the first donation lands.', 'gratora-donation-platform' ) }
            />
        );
    }
    return (
        <table className="gratora-table">
            <tbody>
                { rows.map( ( r ) => (
                    <tr key={ r.id }>
                        <td>
                            <div className="gratora-table__primary">{ r.donor_name }</div>
                            { r.form_title && <div className="gratora-table__sub">{ r.form_title }</div> }
                        </td>
                        <td className="gratora-table__right">
                            <div className="gratora-table__primary">{ formatAmount( r.amount_cents, r.currency ) }</div>
                            <div className="gratora-table__sub" title={ formatDate( r.paid_at ) }>{ timeAgo( r.paid_at ) }</div>
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
                    ? __( 'No donations through a form yet', 'gratora-donation-platform' )
                    : __( 'No form data yet', 'gratora-donation-platform' ) }
                body={ gotDonations
                    ? __( 'This campaign\'s donations were recorded without a form, so there is nothing to rank. Donations made through a donation form appear here.', 'gratora-donation-platform' )
                    : __( 'Once donations come in, this card ranks your forms by total raised.', 'gratora-donation-platform' ) }
            />
        );
    }
    return (
        <table className="gratora-table">
            <tbody>
                { rows.map( ( r ) => {
                    const pct = total > 0 ? Math.round( ( r.amount_cents / total ) * 100 ) : 0;
                    return (
                        <tr key={ r.form_id }>
                            <td>
                                <div className="gratora-table__primary">
                                    <a href={ formEditorHref( r.form_id ) }>{ r.form_title }</a>
                                </div>
                                <div className="gratora-table__bar">
                                    <div className="gratora-table__bar-fill" style={ { width: `${ pct }%` } } />
                                </div>
                            </td>
                            <td className="gratora-table__right">
                                <div className="gratora-table__primary">{ formatAmount( r.amount_cents, currency ) }</div>
                                <div className="gratora-table__sub">
                                    { sprintf( /* translators: %d: number of donations */ _n( '%d donation', '%d donations', r.donations_count, 'gratora-donation-platform' ), r.donations_count ) }
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
        'var(--gratora-chart-1, #2271b1)',
        'var(--gratora-chart-2, #6f5ce6)',
        'var(--gratora-chart-3, #d63384)',
        'var(--gratora-chart-4, #856a1d)',
        'var(--gratora-chart-5, #7c2222)',
    ];

    if ( rows.length === 0 || total === 0 ) {
        return (
            <EmptyState
                compact
                icon={ <HandHeart size={ 22 } strokeWidth={ 1.75 } /> }
                title={ __( 'No payments yet', 'gratora-donation-platform' ) }
                body={ __( 'Gateway breakdown shows up after the first paid donation.', 'gratora-donation-platform' ) }
            />
        );
    }
    return (
        <div className="gratora-gateway">
            <StackedBar segments={ rows.map( ( r, i ) => ( {
                value: r.amount_cents,
                color: colors[ i % colors.length ],
            } ) ) } total={ total } />
            <ul className="gratora-gateway__legend">
                { rows.map( ( r, i ) => {
                    const pct = total > 0 ? Math.round( ( r.amount_cents / total ) * 100 ) : 0;
                    return (
                        <li key={ r.gateway }>
                            <span className="gratora-gateway__dot" style={ { background: colors[ i % colors.length ] } } />
                            <span className="gratora-gateway__label">{ r.gateway_label || r.gateway }</span>
                            <span className="gratora-gateway__value">{ formatAmount( r.amount_cents, currency ) }</span>
                            <span className="gratora-gateway__pct">{ pct }%</span>
                        </li>
                    );
                } ) }
            </ul>
        </div>
    );
}

function StackedBar( { segments, total } ) {
    return (
        <div className="gratora-stackbar">
            { segments.map( ( s, i ) => {
                const pct = total > 0 ? ( s.value / total ) * 100 : 0;
                return (
                    <div key={ i }
                         className="gratora-stackbar__seg"
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
                title={ __( 'No donors yet', 'gratora-donation-platform' ) }
                body={ __( 'Top supporters appear here after the first donation completes.', 'gratora-donation-platform' ) }
            />
        );
    }
    return (
        <table className="gratora-table">
            <tbody>
                { rows.map( ( r ) => (
                    <tr key={ r.donor_id }>
                        <td>
                            <div className="gratora-table__primary">{ r.name }</div>
                            <div className="gratora-table__sub">
                                { sprintf(
                                    /* translators: %d: number of donations */
                                    _n( '%d donation', '%d donations', r.donations_count, 'gratora-donation-platform' ),
                                    r.donations_count
                                ) }
                            </div>
                        </td>
                        <td className="gratora-table__right">
                            <div className="gratora-table__primary">{ formatAmount( r.total_cents, currency ) }</div>
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
        <div className="gratora-metric">
            <div className="gratora-metric__head">
                <span className="gratora-metric__icon">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round">
                        <circle cx="12" cy="12" r="9" />
                        <circle cx="12" cy="12" r="5" />
                        <circle cx="12" cy="12" r="1.5" fill="currentColor" />
                    </svg>
                </span>
            </div>
            <div className="gratora-metric__label">{ __( 'Goal progress', 'gratora-donation-platform' ) }</div>
            <div className="gratora-metric__row">
                <div className="gratora-metric__value">{ `${ pct }%` }</div>
            </div>
            <div className="gratora-metric__sub">{ `${ fmt( current ) } / ${ fmt( target ) }` }</div>
            <div className="gratora-metric__bar" aria-hidden="true">
                <div className="gratora-metric__bar-fill" style={ { width: `${ pct }%` } } />
            </div>
        </div>
    );
}

const FORM_STATUS_OPTIONS = Object.entries( STATUS_LABEL ).map( ( [ value, label ] ) => ( { value, label } ) );

function ShortcodeCell( { slug } ) {
    const [ copied, setCopied ] = useState( false );
    if ( ! slug ) {
        return <span className="gratora-row__sub">-</span>;
    }
    const code = `[gratora_donation_form slug="${ slug }"]`;
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
            className="gratora-shortcode-copy"
            onMouseDown={ stopRowSelect }
            onClick={ copy }
            title={ __( 'Copy shortcode', 'gratora-donation-platform' ) }
            aria-label={ __( 'Copy shortcode', 'gratora-donation-platform' ) }
        >
            <code className="gratora-shortcode-copy__code">{ code }</code>
            <span className="gratora-shortcode-copy__hint">
                { copied
                    ? __( 'Copied', 'gratora-donation-platform' )
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
            path: addQueryArgs( '/gratora/v1/admin/forms', {
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
                setError( err?.message || __( 'Failed to load forms.', 'gratora-donation-platform' ) );
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
                    : __( 'Untitled donation form', 'gratora-donation-platform' ),
                campaign_id: campaign.id,
                blocks:      template?.blocks || '',
            };
            if ( template?.settings ) {
                payload.settings = template.settings;
            }
            const f = await apiFetch( {
                path:   '/gratora/v1/admin/forms',
                method: 'POST',
                data:   payload,
            } );
            window.location.href = formEditorHref( f.id );
        } catch ( err ) {
            setError( err?.message || __( 'Could not create form.', 'gratora-donation-platform' ) );
            setCreating( false );
            setPickerOpen( false );
        }
    };

    const fields = useMemo( () => [
        {
            id:            'title',
            label:         __( 'Title', 'gratora-donation-platform' ),
            enableSorting: true,
            render: ( { item } ) => (
                <div style={ { lineHeight: 1.3 } }>
                    <a className="gratora-row__link gratora-row__link--strong" href={ formEditorHref( item.id ) } { ...rowLinkProps }>
                        { item.title }
                    </a>
                    { item.id === defaultFormId && (
                        <span className="gratora-default-pill">
                            { __( 'Default', 'gratora-donation-platform' ) }
                        </span>
                    ) }
                    <div className="gratora-row__sub gratora-row__sub--mono">
                        { item.slug }
                    </div>
                </div>
            ),
        },
        {
            id:       'status',
            label:    __( 'Status', 'gratora-donation-platform' ),
            elements: FORM_STATUS_OPTIONS,
            filterBy: { operators: [ 'is' ] },
            render:   ( { item } ) => <StatusBadge status={ item.status } />,
        },
        {
            id:            'updated_at',
            label:         __( 'Updated', 'gratora-donation-platform' ),
            enableSorting: true,
            render: ( { item } ) => (
                <span className="gratora-time" title={ formatDate( item.updated_at ) }>
                    <span className="gratora-time__rel">{ timeAgo( item.updated_at ) }</span>
                    <span className="gratora-time__abs">{ formatDate( item.updated_at ) }</span>
                </span>
            ),
        },
        {
            id:    'goal',
            label: __( 'Goal', 'gratora-donation-platform' ),
            // No orderby for these on the server, and DataViews offers sorting
            // on anything that does not opt out.
            enableSorting: false,
            render: ( { item } ) => <GoalCell item={ item } />,
        },
        {
            id:     'shortcode',
            label:  __( 'Shortcode', 'gratora-donation-platform' ),
            enableSorting: false,
            render: ( { item } ) => <ShortcodeCell slug={ item.slug } />,
        },
    ], [ defaultFormId ] );

    const setAsDefault = useCallback( async ( formId ) => {
        try {
            await apiFetch( {
                path:   `/gratora/v1/admin/campaigns/${ campaign.id }`,
                method: 'PATCH',
                data:   { default_form_id: formId },
            } );
            setDefaultFormId( formId );
        } catch ( err ) {
            setError( err?.message || __( 'Could not set default form.', 'gratora-donation-platform' ) );
        }
    }, [ campaign.id ] );

    const actions = useMemo( () => [
        {
            id:       'edit',
            label:    __( 'Edit', 'gratora-donation-platform' ),
            isPrimary: true,
            callback: ( items ) => {
                if ( items[ 0 ] ) window.location.href = formEditorHref( items[ 0 ].id );
            },
        },
        {
            id:       'set-default',
            label:    __( 'Set as default', 'gratora-donation-platform' ),
            isEligible: ( item ) =>
                item.id !== defaultFormId && item.status === 'published',
            callback: ( items ) => {
                if ( items[ 0 ] ) setAsDefault( items[ 0 ].id );
            },
        },
        {
            id:    'duplicate',
            label: __( 'Duplicate', 'gratora-donation-platform' ),
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
                            path:   `/gratora/v1/admin/forms/${ items[ 0 ].id }/duplicate`,
                            method: 'POST',
                        } );
                        window.location.href = formEditorHref( copy.id );
                        return;
                    }
                    await Promise.all( items.map( ( i ) => apiFetch( {
                        path:   `/gratora/v1/admin/forms/${ i.id }/duplicate`,
                        method: 'POST',
                    } ) ) );
                    load();
                } catch ( err ) {
                    setError( err?.message || __( 'Could not duplicate one or more forms.', 'gratora-donation-platform' ) );
                    // Some of the batch may have gone through. Leaving the
                    // table as it was makes the author reload to find out
                    // which.
                    load();
                }
            },
        },
        {
            id:     'delete',
            label:  __( 'Delete', 'gratora-donation-platform' ),
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
                    ? __( 'Permanently delete this form? This cannot be undone.', 'gratora-donation-platform' )
                    : sprintf(
                        /* translators: %d: number of forms to delete */
                        _n(
                            'Permanently delete %d form? This cannot be undone.',
                            'Permanently delete %d forms? This cannot be undone.',
                            targets.length,
                            'gratora-donation-platform'
                        ),
                        targets.length
                    );
                setConfirm( {
                    title:        _n( 'Delete form', 'Delete forms', targets.length, 'gratora-donation-platform' ),
                    message,
                    confirmLabel: __( 'Delete', 'gratora-donation-platform' ),
                    destructive:  true,
                    onConfirm: async () => {
                        try {
                            await Promise.all( targets.map( ( i ) => apiFetch( {
                                path:   `/gratora/v1/admin/forms/${ i.id }`,
                                method: 'DELETE',
                            } ) ) );
                            load();
                        } catch ( err ) {
                            setError( err?.message || __( 'Could not delete one or more forms.', 'gratora-donation-platform' ) );
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
                    { __( 'Forms', 'gratora-donation-platform' ) }
                    <span style={ { color: '#666', fontWeight: 400, marginLeft: 8 } }>({ total })</span>
                </h2>
                <Btn variant="primary" onClick={ () => setPickerOpen( true ) } disabled={ creating }>
                    <Plus size={ 16 } strokeWidth={ 1.75 } />
                    { __( 'Add new form', 'gratora-donation-platform' ) }
                </Btn>
            </div>

            { error && (
                <Notice status="error" onRemove={ () => setError( null ) }>{ error }</Notice>
            ) }

            { ! loading && total === 0 && ! view.search && ! statusFilter ? (
                <EmptyState
                    icon={ <ListChecks size={ 22 } strokeWidth={ 1.75 } /> }
                    title={ __( 'No forms yet', 'gratora-donation-platform' ) }
                    body={ __( 'Donation forms collect the actual donations for this campaign. Pick a template to get started, then customize everything inside.', 'gratora-donation-platform' ) }
                    action={
                        <Btn variant="primary" onClick={ () => setPickerOpen( true ) } disabled={ creating }>
                            { __( 'Add your first form', 'gratora-donation-platform' ) }
                        </Btn>
                    }
                />
            ) : (
                <div style={ { padding: '8px 0' } } className="gratora-dataviews">
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
    { value: 'none',      label: __( 'No goal', 'gratora-donation-platform' ) },
    { value: 'amount',    label: __( 'Amount raised', 'gratora-donation-platform' ) },
    { value: 'donations', label: __( 'Number of donations', 'gratora-donation-platform' ) },
    { value: 'donors',    label: __( 'Number of donors', 'gratora-donation-platform' ) },
];

const SUB_TABS = [
    { key: 'general',    label: __( 'General', 'gratora-donation-platform' ),    Icon: IconGeneral },
    { key: 'goal',       label: __( 'Goal', 'gratora-donation-platform' ),       Icon: IconGoal },
    { key: 'appearance', label: __( 'Appearance', 'gratora-donation-platform' ), Icon: IconAppearance },
    { key: 'defaults',   label: __( 'Defaults', 'gratora-donation-platform' ),   Icon: IconDefaults },
    { key: 'advanced',   label: __( 'Advanced', 'gratora-donation-platform' ),   Icon: IconAdvanced },
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
    title:                __( 'Identity', 'gratora-donation-platform' ),
    description:          __( 'Identity', 'gratora-donation-platform' ),
    slug:                 __( 'Public address', 'gratora-donation-platform' ),
    image_attachment_id:  __( 'Cover image', 'gratora-donation-platform' ),
    image_url:            __( 'Cover image', 'gratora-donation-platform' ),
    status:               __( 'Status & schedule', 'gratora-donation-platform' ),
    starts_at:            __( 'Status & schedule', 'gratora-donation-platform' ),
    ends_at:              __( 'Status & schedule', 'gratora-donation-platform' ),
    goal_type:            __( 'Goal', 'gratora-donation-platform' ),
    goal_cents:           __( 'Goal', 'gratora-donation-platform' ),
    goal_count:           __( 'Goal', 'gratora-donation-platform' ),
    style:                __( 'Appearance', 'gratora-donation-platform' ),
    hide_header:          __( 'Page header & footer', 'gratora-donation-platform' ),
    hide_footer:          __( 'Page header & footer', 'gratora-donation-platform' ),
    default_form_id:      __( 'Default form', 'gratora-donation-platform' ),
    default_fund_id:      __( 'Default fund', 'gratora-donation-platform' ),
} );

function SettingsTab( { campaign, onArchive, onError } ) {
    const c = useGratoraRecord( 'campaign', campaign.id );
    const extSubTabs = useExtensionTabs( 'campaign-settings' );

    // Sub-tabs are addressable: the form editor's Goal block links straight at
    // #goal, and landing on General with no goal field in sight reads as a
    // broken link.
    const [ subTab, setSubTab ] = useState( () => {
        const hash = ( typeof window !== 'undefined' ? window.location.hash : '' ).replace( '#', '' );
        return SUB_TABS.some( ( t ) => t.key === hash ) ? hash : 'general';
    } );
    const [ funds, setFunds ]   = useState( [] );
    const [ forms, setForms ]   = useState( [] );

    useEffect( () => {
        apiFetch( { path: addQueryArgs( '/gratora/v1/admin/campaigns/funds', {
            include: campaign?.default_fund_id || undefined,
        } ) } )
            .then( setFunds )
            .catch( () => onError?.( __( 'Could not load funds.', 'gratora-donation-platform' ) ) );
        apiFetch( { path: `/gratora/v1/admin/forms?campaign_id=${ campaign.id }&per_page=100` } )
            .then( ( res ) => setForms( Array.isArray( res ) ? res : ( res?.items || [] ) ) )
            .catch( () => onError?.( __( 'Could not load forms.', 'gratora-donation-platform' ) ) );
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
            notify.success( __( 'Campaign saved.', 'gratora-donation-platform' ) );
        } catch ( err ) {
            onError( err?.message || __( 'Save failed.', 'gratora-donation-platform' ) );
        }
    };

    const onDiscard = () => {
        if ( c.discard ) c.discard();
        else if ( c.reset ) c.reset();
    };

    return (
        <div>
            <div className="gratora-subtabs" role="tablist">
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
                            { isDirty && <span className="gratora-subtabs__dot" title={ __( 'Unsaved changes', 'gratora-donation-platform' ) } /> }
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
                    <div className={ `gratora-settings-layout${ showRail ? '' : ' gratora-settings-layout--no-rail' }` }>
                        <div className="gratora-settings-layout__main">
                            <div hidden={ subTab !== 'general' }><GeneralPanel c={ c } campaign={ campaign } /></div>
                            <div hidden={ subTab !== 'goal' }><GoalPanel c={ c } /></div>
                            <div hidden={ subTab !== 'appearance' }><AppearancePanel c={ c } /></div>
                            <div hidden={ subTab !== 'defaults' }><DefaultsPanel c={ c } forms={ forms } funds={ funds } /></div>
                            <div hidden={ subTab !== 'advanced' }><AdvancedPanel campaign={ campaign } onArchive={ onArchive } onError={ onError } /></div>
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
                                <aside className="gratora-settings-layout__rail">
                                    <StylePreview
                                        tokens={ inline }
                                        presetId={ presetId }
                                        campaign={ c.record }
                                        layer="campaign"
                                        styling={ window.gratora?.styling || {} }
                                    />
                                </aside>
                            );
                        } )() }
                    </div>
                );
            } )() }

            { c.isDirty && (
                <div className="gratora-save-bar" role="status" aria-live="polite">
                    <span className="gratora-save-bar__dot" aria-hidden="true" />
                    <span className="gratora-save-bar__count">
                        <strong>{ editsCount }</strong>{ ' ' }
                        { _n( 'unsaved change', 'unsaved changes', editsCount, 'gratora-donation-platform' ) }
                        { dirtyCardNames.length > 0 && (
                            <em>{ ' ' }{ __( 'in', 'gratora-donation-platform' ) } { formatCardNames( dirtyCardNames ) }</em>
                        ) }
                    </span>
                    <button
                        type="button"
                        className="gratora-save-bar__btn gratora-save-bar__btn--ghost"
                        onClick={ onDiscard }
                        disabled={ c.isSaving }
                    >
                        { __( 'Discard', 'gratora-donation-platform' ) }
                    </button>
                    <button
                        type="button"
                        className="gratora-save-bar__btn gratora-save-bar__btn--primary"
                        onClick={ onSave }
                        disabled={ c.isSaving }
                    >
                        { __( 'Save changes', 'gratora-donation-platform' ) }
                    </button>
                </div>
            ) }
        </div>
    );
}

function CampaignTypeCard( { campaign } ) {
    const types   = window.gratora?.campaign_types || {};
    const notices = window.gratora?.campaign_type_notices || {};
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
                path:   `/gratora/v1/admin/campaigns/${ campaign.id }`,
                method: 'PUT',
                data:   { campaign_type: target },
            } );
            window.location.reload();
        } catch ( err ) {
            setBusy( false );
            setTarget( null );
            notify.error( err?.message || __( 'Conversion failed.', 'gratora-donation-platform' ) );
        }
    };

    return (
        <Card
            title={ __( 'Campaign type', 'gratora-donation-platform' ) }
            sub={ __( 'Set when the campaign is created. A standard campaign can be converted to a richer type, but not back.', 'gratora-donation-platform' ) }
        >
            <FormRow label={ __( 'Type', 'gratora-donation-platform' ) }>
                <div style={ { display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' } }>
                    <strong>{ types[ current ] || __( 'Standard', 'gratora-donation-platform' ) }</strong>
                    { convertible.map( ( t ) => (
                        <Btn key={ t } variant="secondary" onClick={ () => setTarget( t ) }>
                            { sprintf( /* translators: %s: campaign type label */ __( 'Convert to %s', 'gratora-donation-platform' ), types[ t ] ) }
                        </Btn>
                    ) ) }
                </div>
            </FormRow>

            { target && (
                <Modal
                    title={ sprintf( /* translators: %s: campaign type label */ __( 'Convert to %s', 'gratora-donation-platform' ), types[ target ] ) }
                    onRequestClose={ () => ! busy && setTarget( null ) }
                >
                    { notices[ target ] && <p>{ notices[ target ] }</p> }
                    <p style={ { fontWeight: 600 } }>{ __( "This can't be undone.", 'gratora-donation-platform' ) }</p>
                    <div style={ { display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 16 } }>
                        <Btn variant="tertiary" onClick={ () => setTarget( null ) } disabled={ busy }>
                            { __( 'Cancel', 'gratora-donation-platform' ) }
                        </Btn>
                        <Btn variant="primary" onClick={ convert } disabled={ busy }>
                            { busy ? __( 'Converting…', 'gratora-donation-platform' ) : __( 'Convert', 'gratora-donation-platform' ) }
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
            title={ __( 'Public address', 'gratora-donation-platform' ) }
            sub={ __( 'The URL donors land on. Changing this may break inbound links.', 'gratora-donation-platform' ) }
            edited={ edited ? 1 : 0 }
        >
            <FormRow
                label={ __( 'Slug', 'gratora-donation-platform' ) }
                help={ __( 'Letters, numbers, and hyphens only.', 'gratora-donation-platform' ) }
            >
                <div className={ `gratora-input-prefixed${ edited ? ' is-edited' : '' }` }>
                    <span className="gratora-input-prefixed__prefix">{ addr.prefix || `${ origin }/campaigns/` }</span>
                    <input type="text" className="gratora-input" { ...c.bind( 'slug' ) } />
                </div>
                { addr.url && (
                    <div className="gratora-url-preview">
                        <span className="lbl">{ __( 'Public URL', 'gratora-donation-platform' ) }</span>
                        <span className="url">{ addr.prefix }<em>{ addr.segment }</em></span>
                        <a href={ addr.url } target="_blank" rel="noreferrer">{ __( 'Visit page ↗', 'gratora-donation-platform' ) }</a>
                    </div>
                ) }
                { addr.url && edited && (
                    <div className="gratora-form-row__field-help">
                        { __( 'Saving moves the page. WordPress adds a suffix if another page already holds the slug, so check this address again afterwards.', 'gratora-donation-platform' ) }
                    </div>
                ) }
                { ! addr.url && !! slug && (
                    <div className="gratora-form-row__field-help">
                        { __( 'This campaign has no page yet, so it has no public address. One is created when the campaign is published.', 'gratora-donation-platform' ) }
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
        <div className="gratora-section-block">
            <Card
                title={ __( 'Identity', 'gratora-donation-platform' ) }
                sub={ __( 'Title and short description, used everywhere this campaign appears.', 'gratora-donation-platform' ) }
                edited={ editedCount( [ 'title', 'description' ] ) }
            >
                <FormRow
                    label={ __( 'Campaign title', 'gratora-donation-platform' ) }
                    help={ __( 'Donor-facing. Appears in the page header, on cards, in receipts.', 'gratora-donation-platform' ) }
                    fieldHelp={ __( 'Try to keep it under 6 words.', 'gratora-donation-platform' ) }
                >
                    <input type="text" className={ inputCls( c, 'title' ) } { ...c.bind( 'title' ) } />
                </FormRow>

                <FormRow
                    label={ __( 'Short description', 'gratora-donation-platform' ) }
                    help={ __( 'One or two sentences. Shows on campaign cards and the page hero.', 'gratora-donation-platform' ) }
                >
                    <div className="gratora-input-counter">
                        <textarea
                            className={ textareaCls( c, 'description' ) }
                            rows={ 4 }
                            maxLength={ DESCRIPTION_MAX + 50 }
                            { ...c.bind( 'description' ) }
                        />
                        <span className={ `gratora-input-counter__count${ overLimit ? ' is-over' : ( nearLimit ? ' is-warn' : '' ) }` }>
                            { desc.length } / { DESCRIPTION_MAX }
                        </span>
                    </div>
                    { teaserSrc && (
                        <div className="gratora-form-row__field-help">
                            <strong style={ { color: '#111827', fontWeight: 500 } }>{ __( 'How donors will see this:', 'gratora-donation-platform' ) }</strong>{ ' ' }
                            { teaserSrc }
                        </div>
                    ) }
                </FormRow>
            </Card>

            <CampaignTypeCard campaign={ campaign } />

            <PublicAddressCard c={ c } pageUrl={ campaign?.page_url || r.page_url || null } />

            <Card
                title={ __( 'Cover image', 'gratora-donation-platform' ) }
                sub={ __( 'Recommended 1600 × 900 (16:9). Shows on the page hero and campaign grid.', 'gratora-donation-platform' ) }
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
                title={ __( 'Status & schedule', 'gratora-donation-platform' ) }
                sub={ __( "Whether donors can give right now, and when the campaign runs.", 'gratora-donation-platform' ) }
                edited={ editedCount( [ 'status', 'starts_at', 'ends_at' ] ) }
            >
                <FormRow
                    label={ __( 'Status', 'gratora-donation-platform' ) }
                    help={ __( "Active campaigns accept donations. Drafts and archived campaigns don't. Archive from the campaign menu, which handles any recurring donations first.", 'gratora-donation-platform' ) }
                >
                    <StatusPillGroup value={ c.value( 'status', 'draft' ) } onChange={ c.setValue( 'status' ) } />
                </FormRow>
                <FormRow
                    label={ __( 'Schedule', 'gratora-donation-platform' ) }
                    help={ __( 'Optional. Leave blank for an open-ended campaign.', 'gratora-donation-platform' ) }
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
        <div className="gratora-section-block">
            <Card
                title={ __( 'Campaign goal', 'gratora-donation-platform' ) }
                sub={ __( 'Drives the progress widget on the campaign page and the goal donut on the overview.', 'gratora-donation-platform' ) }
                edited={ editedCount }
            >
                <FormRow label={ __( 'Goal type', 'gratora-donation-platform' ) }>
                    <select
                        className={ `gratora-select${ [ 'goal_type', 'goal_cents', 'goal_count' ].some( ( k ) => c.isEdited( k ) ) ? ' gratora-input--edited' : '' }` }
                        value={ mode }
                        onChange={ ( e ) => chooseMode( e.target.value ) }
                    >
                        { GOAL_TYPES.map( ( t ) => (
                            <option key={ t.value } value={ t.value }>{ t.label }</option>
                        ) ) }
                    </select>
                </FormRow>

                { mode === 'amount' && (
                    <FormRow label={ __( 'Target amount', 'gratora-donation-platform' ) }>
                        <AmountInput
                            currency={ defaultCurrency() }
                            min={ 0 }
                            value={ r.goal_cents == null || r.goal_cents === '' ? '' : Number( r.goal_cents ) / 100 }
                            onChange={ ( v ) => c.edit( { goal_cents: v ? Math.round( v * 100 ) : null } ) }
                        />
                    </FormRow>
                ) }

                { mode === 'donations' && (
                    <FormRow label={ __( 'Target donations', 'gratora-donation-platform' ) }>
                        <input type="number" className={ inputCls( c, 'goal_count' ) } min="0" { ...c.bindNumber( 'goal_count' ) } />
                    </FormRow>
                ) }

                { mode === 'donors' && (
                    <FormRow label={ __( 'Target donors', 'gratora-donation-platform' ) }>
                        <input type="number" className={ inputCls( c, 'goal_count' ) } min="0" { ...c.bindNumber( 'goal_count' ) } />
                    </FormRow>
                ) }

                { mode !== 'none' && (
                    <ToggleRow
                        title={ __( 'Close when the goal is met', 'gratora-donation-platform' ) }
                        sub={ hasGoal
                            ? __( 'The campaign stops accepting donations as soon as it reaches the target. Reopen it by raising the target or turning this off.', 'gratora-donation-platform' )
                            : __( 'Set a target above first. Without one there is nothing to reach.', 'gratora-donation-platform' ) }
                        disabled={ ! hasGoal }
                        checked={ !! r.close_at_goal }
                        onChange={ ( v ) => c.edit( { close_at_goal: !! v } ) }
                    />
                ) }

                { r.close_at_goal && r.goal_met && (
                    <p className="gratora-muted">
                        { __( 'This campaign has reached its goal and is not accepting donations.', 'gratora-donation-platform' ) }
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

export function AppearancePanel( { c } ) {
    // style: null = org default; { preset_id } = named preset; { preset_id, tokens } = preset + inline overrides.
    const rawStyle = c.record?.style ?? null;
    const style    = rawStyle && typeof rawStyle === 'object' ? rawStyle : {};
    const presetId = String( style.preset_id || '' );
    const inline   = ( style.tokens && typeof style.tokens === 'object' ) ? style.tokens : {};
    // Keyed on the presence of `tokens`, not its content, so the editor stays
    // open before the first override.
    const isCustomizing = !! ( style.tokens && typeof style.tokens === 'object' );
    const editedCount   = c.edits?.style !== undefined ? 1 : 0;

    const presets   = Array.isArray( window.gratora?.styling?.presets ) ? window.gratora.styling.presets : [];
    const defaultId = String( window.gratora?.styling?.default_id || '' );
    // Baseline is global defaults plus the selected preset's tokens, so each
    // control shows the chosen theme's value with inline overrides on top.
    const presetBase = resolveEffectiveTokens( { tokens: {}, presetId, layer: 'campaign', styling: window.gratora?.styling || {} } );

    const [ confirm, setConfirm ] = useState( null );

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

    // No preset either, so drop the field and let the brand default win.
    const dropInline = () => writeStyle( presetId === '' ? null : { preset_id: presetId } );

    const toggleCustomizing = ( on ) => {
        if ( on ) {
            writeStyle( { ...style, tokens: { ...inline } } );
            return;
        }
        const count = Object.keys( inline ).length;
        if ( count === 0 ) {
            dropInline();
            return;
        }
        // Discard is shared with the whole settings tab, so taking these back
        // any other way costs every other unsaved change on it.
        setConfirm( {
            title:   __( 'Discard token overrides', 'gratora-donation-platform' ),
            message: sprintf(
                /* translators: %d: number of tokens this campaign overrides */
                _n(
                    'Turning this off drops the %d token this campaign overrides. Saving makes that permanent.',
                    'Turning this off drops the %d tokens this campaign overrides. Saving makes that permanent.',
                    count,
                    'gratora-donation-platform'
                ),
                count
            ),
            confirmLabel: __( 'Discard overrides', 'gratora-donation-platform' ),
            destructive:  true,
            onConfirm:    dropInline,
        } );
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
        <div className="gratora-section-block">
            <Card
                title={ __( 'Campaign appearance', 'gratora-donation-platform' ) }
                sub={ __( "Pick which brand preset this campaign uses. Optionally tweak individual tokens for a one-off look.", 'gratora-donation-platform' ) }
                edited={ editedCount }
            >
                <FormRow label={ __( 'Style preset', 'gratora-donation-platform' ) }>
                    <select
                        className="gratora-select"
                        value={ presetId }
                        onChange={ ( e ) => selectPreset( e.target.value ) }
                    >
                        <option value="">
                            { __( 'Use org default', 'gratora-donation-platform' ) +
                                ( defaultId ? ` (${ presets.find( ( p ) => p.id === defaultId )?.name || defaultId })` : '' ) }
                        </option>
                        { presets.map( ( p ) => (
                            <option key={ p.id } value={ p.id }>{ p.name }</option>
                        ) ) }
                    </select>
                </FormRow>

                <div className="gratora-custom-style-toggle" style={ { marginTop: 16 } }>
                    <ToggleRow
                        title={ __( 'Customize tokens for this campaign', 'gratora-donation-platform' ) }
                        sub={ isCustomizing
                            ? __( 'Inline overrides applied on top of the chosen preset.', 'gratora-donation-platform' )
                            : __( 'Toggle on to tweak individual tokens without creating a new brand preset.', 'gratora-donation-platform' )
                        }
                        checked={ isCustomizing }
                        onChange={ toggleCustomizing }
                    />
                </div>

                { isCustomizing && (
                    <div className="gratora-custom-style-body">
                        <UnshownNotice
                            tokens={ { ...presetBase, ...inline } }
                            catalogue={ window.gratora?.styling?.catalogue || {} }
                        />
                        <TokenEditor
                            value={ inline }
                            defaults={ presetBase }
                            onChange={ setInline }
                            catalogue={ window.gratora?.styling?.catalogue || {} }
                            groups={ window.gratora?.styling?.groups || {} }
                        />
                    </div>
                ) }
            </Card>

            <Card
                title={ __( 'Page header & footer', 'gratora-donation-platform' ) }
                sub={ __( "Hide the theme's header or footer on every page this campaign renders on.", 'gratora-donation-platform' ) }
                edited={ ( c.edits?.hide_header !== undefined ? 1 : 0 ) + ( c.edits?.hide_footer !== undefined ? 1 : 0 ) }
            >
                <ToggleRow
                    title={ __( 'Hide theme header', 'gratora-donation-platform' ) }
                    checked={ !! c.value( 'hide_header', false ) }
                    onChange={ ( v ) => c.edit( { hide_header: v } ) }
                />
                <div style={ { marginTop: 16 } }>
                    <ToggleRow
                        title={ __( 'Hide theme footer', 'gratora-donation-platform' ) }
                        checked={ !! c.value( 'hide_footer', false ) }
                        onChange={ ( v ) => c.edit( { hide_footer: v } ) }
                    />
                </div>
            </Card>

            <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
        </div>
    );
}

function DefaultsPanel( { c, forms, funds } ) {
    const r = c.record;
    const hasFormEdit = c.edits?.default_form_id !== undefined;
    const hasFundEdit = c.edits?.default_fund_id !== undefined;
    return (
        <div className="gratora-section-block">
            <Card
                title={ __( 'Default form', 'gratora-donation-platform' ) }
                sub={ __( 'The form the campaign page and donate-button block submit to by default.', 'gratora-donation-platform' ) }
                edited={ hasFormEdit ? 1 : 0 }
            >
                <FormRow
                    label={ __( 'Form', 'gratora-donation-platform' ) }
                    help={ __( 'Only published forms that belong to this campaign appear here. A draft cannot be the default: the page would fall back to a different form without saying so.', 'gratora-donation-platform' ) }
                >
                    <div className="gratora-grid-2-eq" style={ { gridTemplateColumns: '1fr auto', alignItems: 'center' } }>
                        <select className={ selectCls( c, 'default_form_id' ) } { ...c.bindNumber( 'default_form_id' ) }>
                            <option value="">{ __( '( None )', 'gratora-donation-platform' ) }</option>
                            { /* The Forms tab's own "Set as default" already
                                 guards on published, and every runtime reader
                                 takes this id only when the form is published.
                                 A draft here reads as set and does nothing. */ }
                            { forms
                                .filter( ( f ) => f.status === 'published'
                                    // Keep an ineligible saved default visible rather than
                                    // silently selecting another form.
                                    || Number( f.id ) === Number( c.value( 'default_form_id', 0 ) ) )
                                .map( ( f ) => (
                                    <option key={ f.id } value={ f.id }>
                                        { f.status === 'published'
                                            ? f.title
                                            : sprintf(
                                                /* translators: %s: form title */
                                                __( '%s (not published)', 'gratora-donation-platform' ),
                                                f.title
                                            ) }
                                    </option>
                                ) ) }
                        </select>
                        { r.default_form_id && (
                            <Btn variant="ghost" size="sm" href={ formEditorHref( Number( r.default_form_id ) ) }>
                                { __( 'Edit form', 'gratora-donation-platform' ) } →
                            </Btn>
                        ) }
                    </div>
                </FormRow>
            </Card>

            <Card
                title={ __( 'Default fund', 'gratora-donation-platform' ) }
                sub={ __( 'Where donations from this campaign are routed. Useful when separating restricted donations from general operations.', 'gratora-donation-platform' ) }
                edited={ hasFundEdit ? 1 : 0 }
            >
                <FormRow label={ __( 'Fund', 'gratora-donation-platform' ) }>
                    <select className={ selectCls( c, 'default_fund_id' ) } { ...c.bindNumber( 'default_fund_id' ) }>
                        <option value="">{ __( '( Unassigned )', 'gratora-donation-platform' ) }</option>
                        { funds.map( ( f ) => (
                            <option key={ f.id } value={ f.id }>
                                { f.is_active === false
                                    ? sprintf(
                                        /* translators: %s: fund name */
                                        __( '%s (inactive)', 'gratora-donation-platform' ),
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

function AdvancedPanel( { campaign, onArchive, onError } ) {
    const [ deleting, setDeleting ] = useState( false );
    const [ confirm, setConfirm ] = useState( null );

    const onDelete = async () => {
        setConfirm( await campaignDeleteConfirm( campaign, {
            onArchive,
            onDelete: async () => {
                setDeleting( true );
                try {
                    await apiFetch( {
                        path:   `/gratora/v1/admin/campaigns/${ campaign.id }`,
                        method: 'DELETE',
                    } );
                    window.location.href = listHref();
                } catch ( err ) {
                    onError( err?.message || __( 'Delete failed.', 'gratora-donation-platform' ) );
                    setDeleting( false );
                }
            },
        } ) );
    };

    return (
        <div className="gratora-section-block">
            <Card
                title={ __( 'Danger zone', 'gratora-donation-platform' ) }
                sub={ __( 'Irreversible actions. Use with care.', 'gratora-donation-platform' ) }
            >
                <div className="gratora-danger">
                    <div className="gratora-danger__copy">
                        <div className="gratora-danger__title">{ __( 'Delete this campaign', 'gratora-donation-platform' ) }</div>
                        <div className="gratora-danger__help">
                            { __(
                                'Removes the campaign, its forms, and the WordPress page it created. A campaign that has any donations or recurring plans is never deleted: archive it instead to keep its records.',
                                'gratora-donation-platform'
                            ) }
                        </div>
                    </div>
                    <Btn variant="danger" onClick={ onDelete } isBusy={ deleting } disabled={ deleting }>
                        { __( 'Delete campaign', 'gratora-donation-platform' ) }
                    </Btn>
                </div>
            </Card>

            <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
        </div>
    );
}

// The only statuses a campaign stores. STATUS_LABEL carries three more, but
// scheduled, ended and goal_met are derived on the server from the dates and
// the goal, and the writer coerces anything outside this list to draft. Offered
// as choices they did not fail loudly: picking "Ended" on a live campaign
// silently sent it back to draft and stopped it taking donations.
const SETTABLE_STATUSES = [ 'draft', 'published', 'archived' ];

export function StatusPillGroup( { value, onChange } ) {
    // Archiving runs its own flow from the menu: it asks the server how many
    // live recurring donations the campaign carries and, when there are any,
    // names the count and the amount at stake and offers to cancel them. This
    // pill wrote the same state change with none of that, so it is offered
    // only when the campaign already is archived, to keep the state visible.
    const options = SETTABLE_STATUSES
        .filter( ( key ) => key !== 'archived' || value === 'archived' )
        .map( ( key ) => [ key, STATUS_LABEL[ key ] ] );

    return (
        <div className="gratora-status-pills" role="radiogroup">
            { options.map( ( [ key, label ] ) => (
                <button
                    key={ key }
                    type="button"
                    role="radio"
                    data-state={ key }
                    aria-checked={ value === key }
                    className={ `gratora-status-pill${ value === key ? ' is-active' : '' }` }
                    onClick={ () => onChange( key ) }
                >
                    <span className="gratora-status-pill__dot" />
                    { label }
                </button>
            ) ) }
        </div>
    );
}
