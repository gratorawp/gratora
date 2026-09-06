import { useState, useEffect, useMemo, useCallback } from '@wordpress/element';
import { DataViews } from '@wordpress/dataviews';
import Notice from '../_shared/components/Notice';
import { useTableView } from '../_shared/useTableView';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Copy as CopyIcon, Trash2 as TrashIcon, ExternalLink as ViewIcon, Target, Plus, SearchX } from 'lucide-react';

import { StatusBadge, STATUS_LABEL, formatAmount, formatDate, timeAgo, detailHref } from '../_shared/format';
import { dashboardHref } from '../_shared/adminPages';
import Btn from '../_shared/components/Btn';
import EmptyState from '../_shared/components/EmptyState';
import { isViewFiltered, clearedView } from '../_shared/viewFilters';
import { rowLinkProps } from '../_shared/rowLink';
import ConfirmDialog from '../_shared/components/ConfirmDialog';
import KpiStrip from '../_shared/components/KpiStrip';
import { GoalCell } from '../_shared/components/GoalBar';
import CreateCampaignDrawer from './CreateCampaignDrawer';
import notify from '../_shared/notify';

const STATUS_OPTIONS = Object.entries( STATUS_LABEL ).map( ( [ value, label ] ) => ( { value, label } ) );

/**
 * What deleting this selection destroys, said before the admin agrees to it.
 *
 * Assembled from whole sentences rather than written out per combination: the
 * page warning applies to a subset of a selection, and the pages are what an
 * admin is most likely to have built on. wp_delete_post takes them past the
 * trash, so nothing is left to restore from.
 */
export function campaignsDeleteMessage( items ) {
    const n         = items.length;
    const withPages = items.filter( ( i ) => i.page_id ).length;

    const parts = [ n === 1
        ? __( 'Permanently delete this campaign? Its forms will be deleted too. A campaign that has donations cannot be deleted.', 'fundraising-toolkit' )
        : sprintf(
            /* translators: %d: number of campaigns to delete */
            _n(
                'Permanently delete %d campaign? Forms attached to it will be deleted too. Any campaign that has donations cannot be deleted.',
                'Permanently delete %d campaigns? Forms attached to them will be deleted too. Any campaign that has donations cannot be deleted.',
                n,
                'fundraising-toolkit'
            ),
            n
        ) ];

    // Phrased on the size of the selection, not on the number of pages: with
    // several campaigns selected and one page between them, "the page it
    // created" leaves the admin guessing which campaign "it" is.
    if ( withPages > 0 && n === 1 ) {
        parts.push( __( 'The WordPress page it created is deleted with it, and it does not go to the trash, so any content you built on that page is gone for good.', 'fundraising-toolkit' ) );
    } else if ( withPages > 0 ) {
        parts.push( sprintf(
            /* translators: %d: how many of the selected campaigns have a WordPress page */
            _n(
                '%d of them has a WordPress page, which is deleted with it rather than sent to the trash, so any content you built on it is gone for good.',
                '%d of them have WordPress pages, which are deleted with them rather than sent to the trash, so any content you built on those pages is gone for good.',
                withPages,
                'fundraising-toolkit'
            ),
            withPages
        ) );
    }

    parts.push( __( 'This cannot be undone.', 'fundraising-toolkit' ) );

    return parts.join( ' ' );
}

export default function List() {
    const [ view, setView, viewReady ] = useTableView( 'campaigns', {
        type:    'table',
        perPage: 25,
        page:    1,
        sort:    { field: 'updated_at', direction: 'desc' },
        filters: [],
        search:  '',
        fields:  [ 'title', 'status', 'raised', 'goal', 'donations_count', 'donors_count', 'forms_count', 'updated_at' ],
    }, () => fields.map( ( f ) => f.id ) );

    const [ data, setData ]       = useState( [] );
    const [ total, setTotal ]     = useState( 0 );
    const [ testHidden, setTestHidden ] = useState( 0 );
    const [ loading, setLoading ] = useState( true );
    const [ error, setError ]     = useState( null );
    const [ stats, setStats ]     = useState( null );
    const [ confirm, setConfirm ] = useState( null );
    // Opens straight into the create drawer when reached via the command
    // palette's "New campaign" (admin.php?page=fundkit-campaigns&action=new).
    const [ drawerOpen, setDrawerOpen ] = useState(
        () => new URLSearchParams( window.location.search ).get( 'action' ) === 'new'
    );

    const statusFilter = view.filters?.find( ( f ) => f.field === 'status' );

    // Which empty this screen shows depends on it. See _shared/viewFilters.
    const filtered = isViewFiltered( view );
    const clearFilters = () => {
        setView( clearedView( view ) );
    };

    const load = useCallback( () => {
        // Nothing until the saved view lands: fetching under the screen's
        // defaults first spends a request on rows the reader's own sort is
        // about to replace.
        if ( ! viewReady ) {
            return undefined;
        }

        let aborted = false;
        setLoading( true );
        setError( null );

        apiFetch( {
            path:  addQueryArgs( '/fundkit/v1/admin/campaigns', {
                page:     view.page,
                per_page: view.perPage,
                orderby:  view.sort?.field === 'raised' ? 'raised_cents' : ( view.sort?.field || 'updated_at' ),
                order:    view.sort?.direction || 'desc',
                search:   view.search || undefined,
                status:   statusFilter?.value || undefined,
            } ),
            parse: false,
        } )
            .then( async ( res ) => {
                if ( aborted ) return;
                const items = await res.json();
                setData( Array.isArray( items ) ? items : [] );
                setTotal( parseInt( res.headers.get( 'X-WP-Total' ) || '0', 10 ) );
                setTestHidden( parseInt( res.headers.get( 'X-FundKit-Test-Hidden' ) || '0', 10 ) );
            } )
            .catch( ( err ) => {
                if ( aborted ) return;
                setError( err?.message || __( 'Failed to load campaigns.', 'fundraising-toolkit' ) );
            } )
            .finally( () => ! aborted && setLoading( false ) );

        // Filter-aware aggregates for the KPI strip. Same filter shape as the
        // list but no pagination / sort - the totals are over the matched set.
        apiFetch( {
            path: addQueryArgs( '/fundkit/v1/admin/campaigns/stats', {
                search: view.search || undefined,
                status: statusFilter?.value || undefined,
            } ),
        } )
            .then( ( res ) => { if ( ! aborted ) setStats( res || null ); } )
            .catch( () => { if ( ! aborted ) setStats( null ); } );

        return () => { aborted = true; };
    }, [ view, statusFilter, viewReady ] );

    useEffect( () => load(), [ load ] );


    const fields = useMemo( () => [
        {
            id:            'title',
            label:         __( 'Title', 'fundraising-toolkit' ),
            enableSorting: true,
            render: ( { item } ) => (
                <div className="fundkit-row__body">
                    <span style={ { display: 'inline-flex', alignItems: 'center', gap: 8 } }>
                        <a
                            className="fundkit-row__link fundkit-row__link--strong"
                            href={ detailHref( item.id ) }
                            { ...rowLinkProps }
                        >
                            { item.title }
                        </a>
                        { item.campaign_type && item.campaign_type !== 'standard' && item.campaign_type_label && (
                            <span className="fundkit-pill fundkit-pill--type">
                                { item.campaign_type_label }
                            </span>
                        ) }
                    </span>
                    <div className="fundkit-row__sub fundkit-row__sub--mono">{ item.slug }</div>
                </div>
            ),
        },
        {
            id:       'status',
            label:    __( 'Status', 'fundraising-toolkit' ),
            elements: STATUS_OPTIONS,
            filterBy: { operators: [ 'is' ] },
            render:   ( { item } ) => <StatusBadge status={ item.not_accepting || item.status } />,
        },
        {
            id:            'raised',
            label:         __( 'Raised', 'fundraising-toolkit' ),
            enableSorting: true,
            render: ( { item } ) => (
                <span style={ { fontVariantNumeric: 'tabular-nums' } }>
                    { formatAmount( item.raised_cents ) }
                </span>
            ),
        },
        {
            id:    'goal',
            label: __( 'Goal', 'fundraising-toolkit' ),
            // DataViews offers sorting on every field that does not opt out,
            // and the server has no orderby for these, so the indicator moved
            // and the rows came back in the same order.
            enableSorting: false,
            render: ( { item } ) => <GoalCell item={ item } />,
        },
        {
            id:            'donations_count',
            label:         __( 'Donations', 'fundraising-toolkit' ),
            enableSorting: true,
            render: ( { item } ) => (
                <span style={ { fontVariantNumeric: 'tabular-nums', fontSize: '13px' } }>
                    { Number( item.donations_count || 0 ).toLocaleString() }
                </span>
            ),
        },
        {
            id:            'donors_count',
            label:         __( 'Donors', 'fundraising-toolkit' ),
            enableSorting: true,
            render: ( { item } ) => (
                <span style={ { fontVariantNumeric: 'tabular-nums', fontSize: '13px' } }>
                    { Number( item.donors_count || 0 ).toLocaleString() }
                </span>
            ),
        },
        {
            id:    'forms_count',
            label: __( 'Forms', 'fundraising-toolkit' ),
            enableSorting: false,
            render: ( { item } ) => (
                <span style={ { fontVariantNumeric: 'tabular-nums', fontSize: '13px' } }>
                    { item.forms_count }
                </span>
            ),
        },
        {
            id:            'updated_at',
            label:         __( 'Updated', 'fundraising-toolkit' ),
            enableSorting: true,
            render: ( { item } ) => (
                <span className="fundkit-time" title={ formatDate( item.updated_at ) }>
                    <span className="fundkit-time__rel">{ timeAgo( item.updated_at ) }</span>
                    <span className="fundkit-time__abs">{ formatDate( item.updated_at ) }</span>
                </span>
            ),
        },
    ], [] );

    const paginationInfo = useMemo( () => ( {
        totalItems: total,
        totalPages: Math.max( 1, Math.ceil( total / view.perPage ) ),
    } ), [ total, view.perPage ] );

    const actions = useMemo( () => [
        {
            id:    'view',
            label: __( 'View campaign', 'fundraising-toolkit' ),
            icon:  () => <ViewIcon size={ 16 } strokeWidth={ 1.75 } />,
            // One page per invocation, so no bulk: opening six tabs at once is
            // not what anyone meant by selecting six campaigns.
            supportsBulk: false,
            // A campaign whose page has been deleted has nothing to open, and
            // an action that goes nowhere is worse than one that is absent.
            isEligible: ( item ) => !! item.permalink,
            callback: ( items ) => {
                const target = items[ 0 ];
                if ( ! target?.permalink ) return;
                window.open( target.permalink, '_blank', 'noopener,noreferrer' );
            },
        },
        {
            id:           'duplicate',
            label:        __( 'Duplicate', 'fundraising-toolkit' ),
            icon:         () => <CopyIcon size={ 16 } strokeWidth={ 1.75 } />,
            supportsBulk: true,
            callback: async ( items ) => {
                if ( ! items.length ) return;
                try {
                    await Promise.all( items.map( ( i ) => apiFetch( {
                        path:   `/fundkit/v1/admin/campaigns/${ i.id }/duplicate`,
                        method: 'POST',
                    } ) ) );
                    load();
                } catch ( err ) {
                    setError( err?.message || __( 'Could not duplicate one or more campaigns.', 'fundraising-toolkit' ) );
                }
            },
        },
        {
            id:            'delete',
            label:         __( 'Delete', 'fundraising-toolkit' ),
            icon:          () => <TrashIcon size={ 16 } strokeWidth={ 1.75 } />,
            isDestructive: true,
            supportsBulk:  true,
            // The server refuses to delete a campaign that has donations, so
            // offering it was offering something that could not happen. The
            // copy said donations stay in your database, which read as a
            // promise that the campaign would go and they would remain.
            isEligible:    ( item ) => ! ( ( item.donations_count ?? 0 ) > 0 ),
            callback: ( items ) => {
                if ( ! items.length ) return;
                const n = items.length;
                const message = campaignsDeleteMessage( items );
                setConfirm( {
                    title:        _n( 'Delete campaign', 'Delete campaigns', n, 'fundraising-toolkit' ),
                    message,
                    confirmLabel: __( 'Delete', 'fundraising-toolkit' ),
                    destructive:  true,
                    onConfirm: async () => {
                        // allSettled, not all: one refusal must not reject the
                        // whole batch, or campaigns that really were deleted
                        // stay on screen under a single error with no refetch.
                        const results = await Promise.allSettled( items.map( ( i ) => apiFetch( {
                            path:   `/fundkit/v1/admin/campaigns/${ i.id }`,
                            method: 'DELETE',
                        } ) ) );

                        const refused = items.filter( ( _i, idx ) => results[ idx ].status === 'rejected' );
                        const deleted = items.length - refused.length;

                        if ( deleted > 0 ) {
                            notify.success( sprintf(
                                /* translators: %d: number of campaigns deleted */
                                _n( '%d campaign deleted.', '%d campaigns deleted.', deleted, 'fundraising-toolkit' ),
                                deleted
                            ) );
                        }
                        if ( refused.length > 0 ) {
                            setError( sprintf(
                                /* translators: %s: comma separated campaign titles */
                                __( 'These campaigns were not deleted, because they have donations: %s', 'fundraising-toolkit' ),
                                refused.map( ( c ) => c.title || `#${ c.id }` ).join( ', ' )
                            ) );
                        }

                        load();
                    },
                } );
            },
        },
    ], [ load ] );

    return (
        <div>
            <div className="fundkit-crumbs">
                <a href={ dashboardHref( window.location.pathname ) }>{ __( 'Fundraising', 'fundraising-toolkit' ) }</a>
                <span className="sep">›</span>
                <span>{ __( 'Campaigns', 'fundraising-toolkit' ) }</span>
            </div>
            <div className="fundkit-page-head">
                <div className="fundkit-page-head__title-row">
                    <h1>{ __( 'Campaigns', 'fundraising-toolkit' ) }</h1>
                </div>
                <div className="fundkit-page-head__right">
                    <span className="fundkit-page-head__meta">
                        { sprintf( /* translators: %s: number of campaigns */ _n( '%s campaign', '%s campaigns', total, 'fundraising-toolkit' ), total.toLocaleString() ) }
                    </span>
                    <Btn variant="primary" onClick={ () => setDrawerOpen( true ) }>
                        <Plus size={ 16 } strokeWidth={ 1.75 } />
                        { __( 'Add new campaign', 'fundraising-toolkit' ) }
                    </Btn>
                </div>
            </div>

            { error && (
                <Notice status="error" onRemove={ () => setError( null ) }>{ error }</Notice>
            ) }

            <KpiStrip items={ campaignKpis( stats ) } loading={ loading && ! stats } />

            { /* These figures read stored rollups, which never contain test
                 money, so there is nothing to switch on. Saying what is missing
                 is the difference between a zero and a broken screen. */ }
            { testHidden > 0 && (
                <Notice status="info" isDismissible={ false }>
                    { sprintf(
                        /* translators: %d: test donations not counted. */
                        _n(
                            '%d test donation is not counted in these figures.',
                            '%d test donations are not counted in these figures.',
                            testHidden,
                            'fundraising-toolkit'
                        ),
                        testHidden
                    ) }
                </Notice>
            ) }


            { ! loading && total === 0 && ! filtered ? (
                <EmptyState
                    icon={ <Target size={ 22 } strokeWidth={ 1.75 } /> }
                    title={ __( 'No campaigns yet', 'fundraising-toolkit' ) }
                    body={ __( 'A campaign groups one or more donation forms around a single fundraising goal. Create one to get started.', 'fundraising-toolkit' ) }
                    action={
                        <Btn variant="primary" onClick={ () => setDrawerOpen( true ) }>
                            { __( 'Create your first campaign', 'fundraising-toolkit' ) }
                        </Btn>
                    }
                />
            ) : (
                <div className={ `fundkit-dataviews${ ! loading && data.length === 0 && filtered ? ' is-no-results' : '' }` }>
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

                    { ! loading && data.length === 0 && filtered && (
                        <EmptyState
                            compact
                            icon={ <SearchX size={ 22 } strokeWidth={ 1.75 } /> }
                            title={ __( 'Nothing matches these filters', 'fundraising-toolkit' ) }
                            body={ __( 'Try a different search, or clear the filters to see everything again.', 'fundraising-toolkit' ) }
                            action={
                                <Btn variant="secondary" onClick={ clearFilters }>
                                    { __( 'Clear filters', 'fundraising-toolkit' ) }
                                </Btn>
                            }
                        />
                    ) }
                </div>
            ) }

            { drawerOpen && (
                <CreateCampaignDrawer onClose={ () => setDrawerOpen( false ) } />
            ) }

            <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
        </div>
    );
}

export function campaignKpis( stats ) {
    return [
        {
            label: __( 'Total', 'fundraising-toolkit' ),
            value: stats ? String( stats.total_count ) : '-',
        },
        {
            label: __( 'Active', 'fundraising-toolkit' ),
            value: stats ? String( stats.active_count ) : '-',
        },
        {
            label: __( 'Raised', 'fundraising-toolkit' ),
            value: stats && stats.raised_cents > 0
                ? formatAmount( stats.raised_cents, stats.currency || undefined )
                : '-',
            sub: stats?.currency
                ? sprintf( /* translators: %s: currency code */ __( 'in %s', 'fundraising-toolkit' ), stats.currency )
                : null,
        },
        {
            label: __( 'Donations', 'fundraising-toolkit' ),
            value: stats ? String( stats.donations_count ) : '-',
        },
    ];
}
