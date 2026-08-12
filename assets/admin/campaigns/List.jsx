import { useState, useEffect, useMemo, useCallback } from '@wordpress/element';
import { DataViews } from '@wordpress/dataviews';
import Notice from '../_shared/components/Notice';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Copy as CopyIcon, Trash2 as TrashIcon, Target, Plus } from 'lucide-react';

import { StatusBadge, STATUS_LABEL, formatAmount, formatDate, timeAgo, detailHref } from '../_shared/format';
import Btn from '../_shared/components/Btn';
import EmptyState from '../_shared/components/EmptyState';
import { rowLinkProps } from '../_shared/rowLink';
import ConfirmDialog from '../_shared/components/ConfirmDialog';
import KpiStrip from '../_shared/components/KpiStrip';
import { GoalCell } from '../_shared/components/GoalBar';
import CreateCampaignDrawer from './CreateCampaignDrawer';
import notify from '../_shared/notify';

const STATUS_OPTIONS = Object.entries( STATUS_LABEL ).map( ( [ value, label ] ) => ( { value, label } ) );

export default function List() {
    const [ view, setView ] = useState( {
        type:    'table',
        perPage: 25,
        page:    1,
        sort:    { field: 'updated_at', direction: 'desc' },
        filters: [],
        search:  '',
        fields:  [ 'title', 'status', 'raised', 'goal', 'donations_count', 'donors_count', 'forms_count', 'updated_at' ],
    } );

    const [ data, setData ]       = useState( [] );
    const [ total, setTotal ]     = useState( 0 );
    const [ loading, setLoading ] = useState( false );
    const [ error, setError ]     = useState( null );
    const [ stats, setStats ]     = useState( null );
    const [ confirm, setConfirm ] = useState( null );
    // Opens straight into the create drawer when reached via the command
    // palette's "New campaign" (admin.php?page=dono-campaigns&action=new).
    const [ drawerOpen, setDrawerOpen ] = useState(
        () => new URLSearchParams( window.location.search ).get( 'action' ) === 'new'
    );

    const statusFilter = view.filters?.find( ( f ) => f.field === 'status' );

    const load = useCallback( () => {
        let aborted = false;
        setLoading( true );
        setError( null );

        apiFetch( {
            path:  addQueryArgs( '/dono/v1/admin/campaigns', {
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
            } )
            .catch( ( err ) => {
                if ( aborted ) return;
                setError( err?.message || __( 'Failed to load campaigns.', 'dono-fundraising-platform' ) );
            } )
            .finally( () => ! aborted && setLoading( false ) );

        // Filter-aware aggregates for the KPI strip. Same filter shape as the
        // list but no pagination / sort - the totals are over the matched set.
        apiFetch( {
            path: addQueryArgs( '/dono/v1/admin/campaigns/stats', {
                search: view.search || undefined,
                status: statusFilter?.value || undefined,
            } ),
        } )
            .then( ( res ) => { if ( ! aborted ) setStats( res || null ); } )
            .catch( () => { if ( ! aborted ) setStats( null ); } );

        return () => { aborted = true; };
    }, [ view, statusFilter ] );

    useEffect( () => load(), [ load ] );


    const fields = useMemo( () => [
        {
            id:            'title',
            label:         __( 'Title', 'dono-fundraising-platform' ),
            enableSorting: true,
            render: ( { item } ) => (
                <div className="dono-row__body">
                    <span style={ { display: 'inline-flex', alignItems: 'center', gap: 8 } }>
                        <a
                            className="dono-row__link dono-row__link--strong"
                            href={ detailHref( item.id ) }
                            { ...rowLinkProps }
                        >
                            { item.title }
                        </a>
                        { item.campaign_type && item.campaign_type !== 'standard' && item.campaign_type_label && (
                            <span className="dono-pill dono-pill--type">
                                { item.campaign_type_label }
                            </span>
                        ) }
                    </span>
                    <div className="dono-row__sub dono-row__sub--mono">{ item.slug }</div>
                </div>
            ),
        },
        {
            id:       'status',
            label:    __( 'Status', 'dono-fundraising-platform' ),
            elements: STATUS_OPTIONS,
            filterBy: { operators: [ 'is' ] },
            render:   ( { item } ) => <StatusBadge status={ item.status } />,
        },
        {
            id:            'raised',
            label:         __( 'Raised', 'dono-fundraising-platform' ),
            enableSorting: true,
            render: ( { item } ) => (
                <span style={ { fontVariantNumeric: 'tabular-nums' } }>
                    { formatAmount( item.raised_cents, item.currency ) }
                </span>
            ),
        },
        {
            id:    'goal',
            label: __( 'Goal', 'dono-fundraising-platform' ),
            render: ( { item } ) => <GoalCell item={ item } />,
        },
        {
            id:            'donations_count',
            label:         __( 'Donations', 'dono-fundraising-platform' ),
            enableSorting: true,
            render: ( { item } ) => (
                <span style={ { fontVariantNumeric: 'tabular-nums', fontSize: '13px' } }>
                    { Number( item.donations_count || 0 ).toLocaleString() }
                </span>
            ),
        },
        {
            id:            'donors_count',
            label:         __( 'Donors', 'dono-fundraising-platform' ),
            enableSorting: true,
            render: ( { item } ) => (
                <span style={ { fontVariantNumeric: 'tabular-nums', fontSize: '13px' } }>
                    { Number( item.donors_count || 0 ).toLocaleString() }
                </span>
            ),
        },
        {
            id:    'forms_count',
            label: __( 'Forms', 'dono-fundraising-platform' ),
            render: ( { item } ) => (
                <span style={ { fontVariantNumeric: 'tabular-nums', fontSize: '13px' } }>
                    { item.forms_count }
                </span>
            ),
        },
        {
            id:            'updated_at',
            label:         __( 'Updated', 'dono-fundraising-platform' ),
            enableSorting: true,
            render: ( { item } ) => (
                <span className="dono-time" title={ formatDate( item.updated_at ) }>
                    <span className="dono-time__rel">{ timeAgo( item.updated_at ) }</span>
                    <span className="dono-time__abs">{ formatDate( item.updated_at ) }</span>
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
            id:           'duplicate',
            label:        __( 'Duplicate', 'dono-fundraising-platform' ),
            icon:         () => <CopyIcon size={ 16 } strokeWidth={ 1.75 } />,
            supportsBulk: true,
            callback: async ( items ) => {
                if ( ! items.length ) return;
                try {
                    await Promise.all( items.map( ( i ) => apiFetch( {
                        path:   `/dono/v1/admin/campaigns/${ i.id }/duplicate`,
                        method: 'POST',
                    } ) ) );
                    load();
                } catch ( err ) {
                    setError( err?.message || __( 'Could not duplicate one or more campaigns.', 'dono-fundraising-platform' ) );
                }
            },
        },
        {
            id:            'delete',
            label:         __( 'Delete', 'dono-fundraising-platform' ),
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
                const message = n === 1
                    ? __( 'Permanently delete this campaign? Its forms will be deleted too. A campaign that has donations cannot be deleted. This cannot be undone.', 'dono-fundraising-platform' )
                    : sprintf(
                        /* translators: %d: number of campaigns to delete */
                        _n(
                            'Permanently delete %d campaign? Forms attached to it will be deleted too. Any campaign that has donations cannot be deleted. This cannot be undone.',
                            'Permanently delete %d campaigns? Forms attached to them will be deleted too. Any campaign that has donations cannot be deleted. This cannot be undone.',
                            n,
                            'dono-fundraising-platform'
                        ),
                        n
                    );
                setConfirm( {
                    title:        _n( 'Delete campaign', 'Delete campaigns', n, 'dono-fundraising-platform' ),
                    message,
                    confirmLabel: __( 'Delete', 'dono-fundraising-platform' ),
                    destructive:  true,
                    onConfirm: async () => {
                        // allSettled, not all: one refusal used to reject the
                        // whole batch, so campaigns that really had been deleted
                        // stayed on screen with a single error above them and no
                        // refetch. Each campaign now reports its own outcome.
                        const results = await Promise.allSettled( items.map( ( i ) => apiFetch( {
                            path:   `/dono/v1/admin/campaigns/${ i.id }`,
                            method: 'DELETE',
                        } ) ) );

                        const refused = items.filter( ( _i, idx ) => results[ idx ].status === 'rejected' );
                        const deleted = items.length - refused.length;

                        if ( deleted > 0 ) {
                            notify.success( sprintf(
                                /* translators: %d: number of campaigns deleted */
                                _n( '%d campaign deleted.', '%d campaigns deleted.', deleted, 'dono-fundraising-platform' ),
                                deleted
                            ) );
                        }
                        if ( refused.length > 0 ) {
                            setError( sprintf(
                                /* translators: %s: comma separated campaign titles */
                                __( 'These campaigns were not deleted, because they have donations: %s', 'dono-fundraising-platform' ),
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
            <div className="dono-crumbs">
                <a href={ addQueryArgs( window.location.pathname, { page: 'dono-fundraising-platform' } ) }>{ __( 'Dono', 'dono-fundraising-platform' ) }</a>
                <span className="sep">›</span>
                <span>{ __( 'Campaigns', 'dono-fundraising-platform' ) }</span>
            </div>
            <div className="dono-page-head">
                <div className="dono-page-head__title-row">
                    <h1>{ __( 'Campaigns', 'dono-fundraising-platform' ) }</h1>
                </div>
                <div className="dono-page-head__right">
                    <span className="dono-page-head__meta">
                        { sprintf( /* translators: %s: number of campaigns */ _n( '%s campaign', '%s campaigns', total, 'dono-fundraising-platform' ), total.toLocaleString() ) }
                    </span>
                    <Btn variant="primary" onClick={ () => setDrawerOpen( true ) }>
                        <Plus size={ 16 } strokeWidth={ 1.75 } />
                        { __( 'Add new campaign', 'dono-fundraising-platform' ) }
                    </Btn>
                </div>
            </div>

            { error && (
                <Notice status="error" onRemove={ () => setError( null ) }>{ error }</Notice>
            ) }

            <KpiStrip items={ campaignKpis( stats ) } loading={ loading && ! stats } />

            { ! loading && total === 0 && ! view.search && ! statusFilter ? (
                <EmptyState
                    icon={ <Target size={ 22 } strokeWidth={ 1.75 } /> }
                    title={ __( 'No campaigns yet', 'dono-fundraising-platform' ) }
                    body={ __( 'A campaign groups one or more donation forms around a single fundraising goal. Create one to get started.', 'dono-fundraising-platform' ) }
                    action={
                        <Btn variant="primary" onClick={ () => setDrawerOpen( true ) }>
                            { __( 'Create your first campaign', 'dono-fundraising-platform' ) }
                        </Btn>
                    }
                />
            ) : (
                <div className="dono-dataviews">
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

            { drawerOpen && (
                <CreateCampaignDrawer onClose={ () => setDrawerOpen( false ) } />
            ) }

            <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
        </div>
    );
}

function campaignKpis( stats ) {
    return [
        {
            label: __( 'Total', 'dono-fundraising-platform' ),
            value: stats ? stats.total_count.toLocaleString() : '-',
        },
        {
            label: __( 'Active', 'dono-fundraising-platform' ),
            value: stats ? stats.active_count.toLocaleString() : '-',
        },
        {
            label: __( 'Raised', 'dono-fundraising-platform' ),
            value: stats && stats.raised_cents > 0
                ? formatAmount( stats.raised_cents, stats.currency || undefined )
                : '-',
            sub: stats?.currency
                ? sprintf( /* translators: %s: currency code */ __( 'in %s', 'dono-fundraising-platform' ), stats.currency )
                : null,
        },
        {
            label: __( 'Donations', 'dono-fundraising-platform' ),
            value: stats ? stats.donations_count.toLocaleString() : '-',
        },
    ];
}
