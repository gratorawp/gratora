import { useState, useEffect, useMemo, useCallback } from '@wordpress/element';
import { DataViews } from '@wordpress/dataviews';
import ConfirmDialog from '../_shared/components/ConfirmDialog';
import Notice from '../_shared/components/Notice';
import { useTableView } from '../_shared/useTableView';
import { notify } from '../_shared/notify';
import { dashboardHref } from '../_shared/adminPages';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Pencil, Trash2 as TrashIcon, Star, Power, PowerOff, Wallet, Plus, SearchX } from 'lucide-react';

import { formatAmount } from '../_shared/format';
import Btn from '../_shared/components/Btn';
import { stopRowSelect } from '../_shared/rowLink';
import EmptyState from '../_shared/components/EmptyState';
import { isViewFiltered, clearedView } from '../_shared/viewFilters';
import Dialog from '../_shared/components/Dialog';
import ScheduleFields from '../_shared/components/ScheduleFields';
import { ToggleRow } from '../_shared/components/Switch';
import KpiStrip from '../_shared/components/KpiStrip';
import GoalBar from '../_shared/components/GoalBar';
import SearchableSelect from '../_shared/components/SearchableSelect';

// A column header sorts by its field id; the server sorts by column name and
// silently falls back to sort_order for a name it does not know, so an
// unmapped id turns the arrow without turning the list.
const ORDERBY_COLUMN = { type: 'is_restricted' };

export const orderbyFor = ( field ) => ORDERBY_COLUMN[ field ] || field || 'sort_order';

const STATUS_OPTIONS = [
    { value: 'active',     label: __( 'Active', 'gratora-donation-platform' ) },
    { value: 'inactive',   label: __( 'Inactive', 'gratora-donation-platform' ) },
    { value: 'restricted', label: __( 'Restricted', 'gratora-donation-platform' ) },
];

/**
 * Flatten funds into [parent, ...its children] order with a depth marker so
 * the table can show the fund hierarchy. A child whose parent is not on the
 * current page falls back to top level so nothing gets hidden.
 */
function arrangeTree( items ) {
    const byParent = new Map();
    const ids = new Set( items.map( ( f ) => f.id ) );
    items.forEach( ( f ) => {
        const key = f.parent_fund_id && ids.has( f.parent_fund_id ) ? f.parent_fund_id : 0;
        if ( ! byParent.has( key ) ) byParent.set( key, [] );
        byParent.get( key ).push( f );
    } );

    const out = [];
    const walk = ( parentId, depth ) => {
        ( byParent.get( parentId ) || [] ).forEach( ( f ) => {
            out.push( { ...f, __depth: depth } );
            walk( f.id, depth + 1 );
        } );
    };
    walk( 0, 0 );
    return out;
}

// An active fund outside its own schedule takes no donations, so the row says
// which of the two it is rather than calling it Active.
export const fundIsOpen = ( item ) => !! item.is_active && ! item.schedule_state;

/**
 * The window as the admin picked it. The stored value rather than a formatted
 * one: these are calendar dates with no time, and rendering them through a
 * timezone is how a date lands a day out.
 */
export function fundWindowLabel( item ) {
    const from = ( item.starts_at || '' ).slice( 0, 10 );
    const to   = ( item.ends_at || '' ).slice( 0, 10 );

    if ( from && to ) {
        /* translators: 1: start date, 2: end date */
        return sprintf( __( 'from %1$s to %2$s', 'gratora-donation-platform' ), from, to );
    }
    if ( to ) {
        /* translators: %s: end date */
        return sprintf( __( 'until %s', 'gratora-donation-platform' ), to );
    }

    /* translators: %s: start date */
    return sprintf( __( 'from %s', 'gratora-donation-platform' ), from );
}

/** @since 1.0.0 */
export function fundStatusLabel( item ) {
    if ( ! item.is_active ) return __( 'Inactive', 'gratora-donation-platform' );
    if ( item.schedule_state === 'scheduled' ) return __( 'Scheduled', 'gratora-donation-platform' );
    if ( item.schedule_state === 'ended' ) return __( 'Ended', 'gratora-donation-platform' );
    return __( 'Active', 'gratora-donation-platform' );
}

function fundKpis( stats ) {
    return [
        {
            label: __( 'Total raised', 'gratora-donation-platform' ),
            value: stats ? formatAmount( stats.raised_cents ) : '-',
            sub:   __( 'all funds', 'gratora-donation-platform' ),
        },
        {
            label: __( 'Active funds', 'gratora-donation-platform' ),
            value: stats ? String( stats.active ) : '-',
            sub:   stats ? `${ __( 'of', 'gratora-donation-platform' ) } ${ stats.total }` : null,
        },
        {
            label: __( 'Restricted', 'gratora-donation-platform' ),
            value: stats ? String( stats.restricted ) : '-',
            sub:   __( 'donor-restricted', 'gratora-donation-platform' ),
        },
        {
            label: __( 'Default fund', 'gratora-donation-platform' ),
            value: stats ? ( stats.default ? stats.default.name : __( 'None', 'gratora-donation-platform' ) ) : '-',
        },
    ];
}

export default function List() {
    const [ view, setView, viewReady ] = useTableView( 'funds', {
        type:    'table',
        perPage: 25,
        page:    1,
        sort:    { field: 'sort_order', direction: 'asc' },
        filters: [],
        search:  '',
        fields:  [ 'name', 'type', 'raised', 'goal', 'status' ],
    }, () => fields.map( ( f ) => f.id ) );

    const [ data, setData ]         = useState( [] );
    const [ total, setTotal ]       = useState( 0 );
    const [ testHidden, setTestHidden ] = useState( 0 );
    const [ loading, setLoading ]   = useState( true );
    const [ error, setError ]       = useState( null );
    const [ editing, setEditing ]   = useState( null );
    const [ deleteTarget, setDeleteTarget ] = useState( null );
    const [ confirm, setConfirm ]   = useState( null );
    const [ allFunds, setAllFunds ] = useState( [] );
    // loading | ready | failed. An empty picker is a statement about the org's
    // funds, so the dialog has to know which of the three it is looking at.
    const [ allFundsState, setAllFundsState ] = useState( 'loading' );
    const [ stats, setStats ]       = useState( null );
    const [ statsLoading, setStatsLoading ] = useState( true );
    const [ reload, setReload ]     = useState( 0 );

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
            path: addQueryArgs( '/gratora/v1/admin/funds', {
                page:     view.page,
                per_page: view.perPage,
                orderby:  orderbyFor( view.sort?.field ),
                order:    view.sort?.direction || 'asc',
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
                setTestHidden( parseInt( res.headers.get( 'X-Gratora-Test-Hidden' ) || '0', 10 ) );
            } )
            .catch( ( err ) => {
                if ( aborted ) return;
                setError( err?.message || __( 'Failed to load funds.', 'gratora-donation-platform' ) );
            } )
            .finally( () => ! aborted && setLoading( false ) );

        return () => { aborted = true; };
    }, [ view, statusFilter, viewReady ] );

    // The parent and reassign pickers were fed the table's current page, so a
    // second page of funds was unreachable as a parent and as a reassign
    // target. They need the whole set, filters and pagination aside.
    const loadAll = useCallback( () => {
        const collect = async () => {
            const out = [];
            for ( let page = 1; page <= 20; page++ ) {
                const res = await apiFetch( {
                    path: addQueryArgs( '/gratora/v1/admin/funds', { page, per_page: 100, orderby: 'sort_order', order: 'asc' } ),
                } );
                const items = Array.isArray( res ) ? res : [];
                out.push( ...items );
                if ( items.length < 100 ) break;
            }
            return out;
        };

        setAllFundsState( 'loading' );
        collect()
            .then( ( items ) => {
                setAllFunds( items );
                setAllFundsState( 'ready' );
            } )
            .catch( ( err ) => {
                setAllFundsState( 'failed' );
                window.console?.error( '[gratora] fund picker list failed to load', err );
            } );
    }, [] );

    const loadStats = useCallback( () => {
        setStatsLoading( true );
        apiFetch( { path: '/gratora/v1/admin/funds/stats' } )
            .then( setStats )
            .catch( ( err ) => {
                // Don't strand the KPI strip in a perpetual spinner; settling
                // loading resolves it to em-dashes instead.
                window.console?.error( '[gratora] fund stats failed to load', err );
            } )
            .finally( () => setStatsLoading( false ) );
    }, [] );

    useEffect( () => load(), [ load, reload ] );
    useEffect( () => loadStats(), [ loadStats, reload ] );
    useEffect( () => loadAll(), [ loadAll, reload ] );

    const afterChange = useCallback( () => {
        setReload( ( n ) => n + 1 );
    }, [] );

    const mutate = useCallback( async ( id, payload, done = '' ) => {
        try {
            await apiFetch( { path: `/gratora/v1/admin/funds/${ id }`, method: 'POST', data: payload } );
            if ( done ) notify.success( done );
            afterChange();
        } catch ( err ) {
            setError( err?.message || __( 'Action failed.', 'gratora-donation-platform' ) );
        }
    }, [ afterChange ] );

    // A draft, not a record. Creating the fund up front and opening the editor
    // on it meant Cancel left a live "Untitled fund" behind: active by default,
    // so the fund picker offered it to donors, and invisible in this list until
    // the next page load because Cancel does not refetch.
    const onCreate = () => {
        setError( null );
        setEditing( {
            code:      'fund-' + Date.now().toString( 36 ),
            name:      '',
            is_active: true,
        } );
    };

    const fields = useMemo( () => [
        {
            id:            'name',
            label:         __( 'Fund', 'gratora-donation-platform' ),
            enableSorting: true,
            render: ( { item } ) => (
                <div
                    className={ item.__depth ? 'gratora-fund-name gratora-fund-name--child' : 'gratora-fund-name' }
                    style={ item.__depth ? { paddingLeft: `${ item.__depth * 26 }px` } : undefined }
                >
                    <div style={ { lineHeight: 1.35 } }>
                        <button
                            type="button"
                            className="gratora-row__link gratora-row__link--strong"
                            onMouseDown={ stopRowSelect }
                            onClick={ ( e ) => { stopRowSelect( e ); setEditing( item ); } }
                        >
                            { item.name }
                        </button>
                        { item.is_default && (
                            <span className="gratora-fund-badge gratora-fund-badge--default">
                                { __( 'Default', 'gratora-donation-platform' ) }
                            </span>
                        ) }
                        <div className="gratora-fund-code">{ item.code }</div>
                    </div>
                </div>
            ),
        },
        {
            id:            'type',
            label:         __( 'Type', 'gratora-donation-platform' ),
            enableSorting: true,
            render: ( { item } ) => (
                <span className={ 'gratora-fund-badge ' + ( item.is_restricted
                    ? 'gratora-fund-badge--restricted'
                    : 'gratora-fund-badge--unrestricted' ) }>
                    { item.is_restricted ? __( 'Restricted', 'gratora-donation-platform' ) : __( 'Unrestricted', 'gratora-donation-platform' ) }
                </span>
            ),
        },
        {
            id:    'raised',
            label: __( 'Raised', 'gratora-donation-platform' ),
            // Same reason the goal column is not sortable: a parent's raised is
            // rolled up in PHP after the query.
            enableSorting: false,
            render: ( { item } ) => (
                <span className="gratora-fund-raised">{ formatAmount( item.raised_cents ) }</span>
            ),
        },
        {
            id:            'goal',
            label:         __( 'Goal progress', 'gratora-donation-platform' ),
            // Not sortable: the column shows raised-vs-goal percentage, but a
            // parent's raised is rolled up in PHP after the query, so no DB
            // sort key reflects what's displayed.
            enableSorting: false,
            render: ( { item } ) => {
                if ( ! item.goal_cents ) {
                    return (
                        <GoalBar left={ __( 'No goal set', 'gratora-donation-platform' ) } pct={ 0 } muted />
                    );
                }
                const pct = Math.min( 100, Math.round( ( item.raised_cents / item.goal_cents ) * 100 ) );
                return (
                    <GoalBar
                        left={ formatAmount( item.raised_cents ) }
                        right={ `${ __( 'of', 'gratora-donation-platform' ) } ${ formatAmount( item.goal_cents ) }` }
                        pct={ pct }
                    />
                );
            },
        },
        {
            id:       'status',
            label:    __( 'Status', 'gratora-donation-platform' ),
            elements: STATUS_OPTIONS,
            filterBy: { operators: [ 'is' ] },
            // The cell reads Active / Scheduled / Ended / Inactive, and three
            // of those four come from Fund::scheduleState() weighing the dates
            // against now, after the query. No column carries what is shown.
            enableSorting: false,
            render: ( { item } ) => {
                if ( item.reassign_pending ) {
                    return (
                        <span className="gratora-fund-badge gratora-fund-badge--pending">
                            { __( 'Reassigning…', 'gratora-donation-platform' ) }
                        </span>
                    );
                }
                return (
                    <span className="gratora-fund-status">
                        <span className={ 'gratora-fund-dot ' + ( fundIsOpen( item ) ? 'is-on' : 'is-off' ) } />
                        { fundStatusLabel( item ) }
                    </span>
                );
            },
        },
    ], [] );

    const rows = useMemo( () => arrangeTree( data ), [ data ] );

    const paginationInfo = useMemo( () => ( {
        totalItems: total,
        totalPages: Math.max( 1, Math.ceil( total / view.perPage ) ),
    } ), [ total, view.perPage ] );

    const actions = useMemo( () => [
        {
            id:         'edit',
            label:      __( 'Edit', 'gratora-donation-platform' ),
            icon:       () => <Pencil size={ 16 } strokeWidth={ 1.75 } />,
            isEligible: ( item ) => ! item.reassign_pending,
            callback:   ( [ item ] ) => setEditing( item ),
        },
        {
            id:         'set-default',
            label:      __( 'Set as default', 'gratora-donation-platform' ),
            icon:       () => <Star size={ 16 } strokeWidth={ 1.75 } />,
            isEligible: ( item ) => ! item.is_default && item.is_active && ! item.reassign_pending,
            // The default cannot carry a window, and the server refuses the
            // pairing rather than quietly dropping the dates, so asking for the
            // clear is this action's job and it asks first.
            callback:   ( [ item ] ) => {
                if ( ! item.starts_at && ! item.ends_at ) {
                    return mutate( item.id, { is_default: true } );
                }
                setConfirm( {
                    title:   __( 'Clear the schedule?', 'gratora-donation-platform' ),
                    message: sprintf(
                        /* translators: 1: fund name, 2: the dates that will be cleared */
                        __( '%1$s runs %2$s. The default fund takes every donation with no fund chosen, so it has to stay open: making this one the default clears those dates.', 'gratora-donation-platform' ),
                        item.name,
                        fundWindowLabel( item )
                    ),
                    confirmLabel: __( 'Clear and set as default', 'gratora-donation-platform' ),
                    onConfirm: () => mutate(
                        item.id,
                        { is_default: true, starts_at: null, ends_at: null },
                        sprintf(
                            /* translators: %s: fund name */
                            __( '%s is now the default fund, and its schedule was cleared.', 'gratora-donation-platform' ),
                            item.name
                        )
                    ),
                } );
            },
        },
        {
            id:         'deactivate',
            label:      __( 'Deactivate', 'gratora-donation-platform' ),
            icon:       () => <PowerOff size={ 16 } strokeWidth={ 1.75 } />,
            isEligible: ( item ) => ! item.is_default && item.is_active && ! item.reassign_pending,
            callback:   ( [ item ] ) => mutate( item.id, { is_active: false } ),
        },
        {
            id:         'activate',
            label:      __( 'Activate', 'gratora-donation-platform' ),
            icon:       () => <Power size={ 16 } strokeWidth={ 1.75 } />,
            isEligible: ( item ) => ! item.is_default && ! item.is_active && ! item.reassign_pending,
            callback:   ( [ item ] ) => mutate( item.id, { is_active: true } ),
        },
        {
            id:            'delete',
            label:         __( 'Delete', 'gratora-donation-platform' ),
            icon:          () => <TrashIcon size={ 16 } strokeWidth={ 1.75 } />,
            isDestructive: true,
            isEligible:    ( item ) => ! item.is_default && ! item.reassign_pending,
            callback:      ( [ item ] ) => setDeleteTarget( item ),
        },
    ], [ mutate ] );

    return (
        <div>
            <div className="gratora-crumbs">
                <a href={ dashboardHref( window.location.pathname ) }>{ __( 'Fundraising', 'gratora-donation-platform' ) }</a>
                <span className="sep">›</span>
                <span>{ __( 'Funds', 'gratora-donation-platform' ) }</span>
            </div>
            <div className="gratora-page-head">
                <div className="gratora-page-head__title-row">
                    <h1>{ __( 'Funds', 'gratora-donation-platform' ) }</h1>
                </div>
                <div className="gratora-page-head__right">
                    <span className="gratora-page-head__meta">
                        { sprintf( /* translators: %s: number of funds */ _n( '%s fund', '%s funds', total, 'gratora-donation-platform' ), total.toLocaleString() ) }
                    </span>
                    <Btn variant="primary" onClick={ onCreate }>
                        <Plus size={ 16 } strokeWidth={ 1.75 } />
                        { __( 'New fund', 'gratora-donation-platform' ) }
                    </Btn>
                </div>
            </div>

            <p className="gratora-funds-intro">
                { __( 'Organization-wide designations donations are allocated to.', 'gratora-donation-platform' ) }
            </p>

            <KpiStrip items={ fundKpis( stats ) } loading={ statsLoading } />

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
                            'gratora-donation-platform'
                        ),
                        testHidden
                    ) }
                </Notice>
            ) }


            { error && (
                <Notice status="error" onRemove={ () => setError( null ) }>{ error }</Notice>
            ) }

            { ! loading && total === 0 && ! filtered ? (
                <EmptyState
                    icon={ <Wallet size={ 22 } strokeWidth={ 1.75 } /> }
                    title={ __( 'No funds yet', 'gratora-donation-platform' ) }
                    body={ __( 'Funds route donations to specific causes within your organization. Forms without a fund picker drop into the organization default.', 'gratora-donation-platform' ) }
                    action={
                        <Btn variant="primary" onClick={ onCreate }>
                            { __( 'Create your first fund', 'gratora-donation-platform' ) }
                        </Btn>
                    }
                />
            ) : (
                <div className={ `gratora-dataviews${ ! loading && data.length === 0 && filtered ? ' is-no-results' : '' }` }>
                    <DataViews
                        data={ rows }
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
                            title={ __( 'Nothing matches these filters', 'gratora-donation-platform' ) }
                            body={ __( 'Try a different search, or clear the filters to see everything again.', 'gratora-donation-platform' ) }
                            action={
                                <Btn variant="secondary" onClick={ clearFilters }>
                                    { __( 'Clear filters', 'gratora-donation-platform' ) }
                                </Btn>
                            }
                        />
                    ) }
                </div>
            ) }

            { editing && (
                <FundEditor
                    fund={ editing }
                    allFunds={ allFunds }
                    onClose={ () => setEditing( null ) }
                    onSaved={ () => { setEditing( null ); afterChange(); } }
                />
            ) }

            { deleteTarget && (
                <FundDeleteModal
                    fund={ deleteTarget }
                    funds={ allFunds }
                    fundsState={ allFundsState }
                    onRetryFunds={ loadAll }
                    onClose={ () => setDeleteTarget( null ) }
                    onDone={ ( msg ) => { setDeleteTarget( null ); notify.success( msg ); afterChange(); } }
                />
            ) }

            <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
        </div>
    );
}

function FundEditor( { fund, allFunds, onClose, onSaved } ) {
    const [ form, setForm ] = useState( {
        name:            fund.name || '',
        code:            fund.code || '',
        description:     fund.description || '',
        is_restricted:   !! fund.is_restricted,
        is_default:      !! fund.is_default,
        is_active:       fund.is_active !== false,
        parent_fund_id:  fund.parent_fund_id || '',
        sort_order:      fund.sort_order || 0,
        goal:            fund.goal_cents ? String( fund.goal_cents / 100 ) : '',
        starts_at:       fund.starts_at || '',
        ends_at:         fund.ends_at || '',
        accounting_code: fund.accounting_code || '',
    } );
    const [ scheduleOn, setScheduleOn ] = useState( !! ( fund.starts_at || fund.ends_at ) );
    const [ saving, setSaving ] = useState( false );
    const [ saveError, setSaveError ] = useState( null );

    const set = ( key, value ) => setForm( ( s ) => ( { ...s, [ key ]: value } ) );

    // The server refuses a default fund carrying a window, so the form drops
    // the dates here rather than letting the reader fill in a schedule that
    // comes back as a 422 on save.
    const setIsDefault = ( on ) => {
        setForm( ( s ) => ( on ? { ...s, is_default: true, starts_at: '', ends_at: '' } : { ...s, is_default: false } ) );
        if ( on ) setScheduleOn( false );
    };

    const save = async () => {
        setSaving( true );
        setSaveError( null );
        try {
            await apiFetch( {
                // No id means this fund has never been saved, so this is the
                // create rather than an update.
                path:   fund.id ? `/gratora/v1/admin/funds/${ fund.id }` : '/gratora/v1/admin/funds',
                method: 'POST',
                data:   {
                    name:            form.name,
                    code:            form.code,
                    description:     form.description,
                    is_restricted:   form.is_restricted,
                    is_default:      form.is_default,
                    is_active:       form.is_active,
                    parent_fund_id:  form.parent_fund_id === '' ? null : Number( form.parent_fund_id ),
                    sort_order:      Number( form.sort_order ) || 0,
                    goal_cents:      form.goal === '' ? null : Math.round( parseFloat( form.goal ) * 100 ),
                    // Always null on the default: the server refuses a default
                    // carrying a window, and a fund promoted before that rule
                    // existed still has one on the row this form was seeded
                    // from.
                    starts_at:       form.is_default ? null : ( form.starts_at || null ),
                    ends_at:         form.is_default ? null : ( form.ends_at || null ),
                    accounting_code: form.accounting_code || null,
                },
            } );
            onSaved();
        } catch ( err ) {
            setSaveError( err?.message || __( 'Failed to save fund.', 'gratora-donation-platform' ) );
            setSaving( false );
        }
    };

    // A fund queued for reassignment is deleted when the job finishes, and it
    // never carries parent_fund_id along, so offering it here would hand the
    // sub-fund a parent that is about to disappear. The server refuses it too.
    const parents = ( allFunds || [] ).filter( ( f ) => f.id !== fund.id && ! f.reassign_pending );

    return (
        <Dialog
            title={ fund.id
                ? __( 'Edit fund', 'gratora-donation-platform' )
                : __( 'New fund', 'gratora-donation-platform' ) }
            onClose={ onClose }
            foot={ (
                <>
                    <Btn onClick={ onClose }>{ __( 'Cancel', 'gratora-donation-platform' ) }</Btn>
                    <Btn variant="primary" onClick={ save } isBusy={ saving } disabled={ saving }>
                        { fund.id
                            ? __( 'Save fund', 'gratora-donation-platform' )
                            : __( 'Create fund', 'gratora-donation-platform' ) }
                    </Btn>
                </>
            ) }
        >
            { saveError && (
                        <Notice status="error" onRemove={ () => setSaveError( null ) }>{ saveError }</Notice>
                    ) }
                    <fieldset className="gratora-fset">
                        <legend>{ __( 'Identity', 'gratora-donation-platform' ) }</legend>
                        <div className="gratora-fld">
                            <label htmlFor="gratora-fund-name">{ __( 'Name', 'gratora-donation-platform' ) }</label>
                            <input id="gratora-fund-name" className="gratora-input" value={ form.name } onChange={ ( e ) => set( 'name', e.target.value ) } />
                        </div>
                        <div className="gratora-fld">
                            <label htmlFor="gratora-fund-code">{ __( 'Code', 'gratora-donation-platform' ) }</label>
                            <input id="gratora-fund-code" aria-describedby="gratora-fund-code-help" className="gratora-input gratora-input--mono" value={ form.code } onChange={ ( e ) => set( 'code', e.target.value ) } />
                            <p id="gratora-fund-code-help" className="gratora-fld__help">{ __( 'Stable identifier used in exports and accounting. Lowercase, no spaces. Avoid changing once donations exist.', 'gratora-donation-platform' ) }</p>
                        </div>
                        <div className="gratora-fld">
                            <label htmlFor="gratora-fund-description">{ __( 'Description', 'gratora-donation-platform' ) }</label>
                            <textarea id="gratora-fund-description" className="gratora-textarea" rows="3" value={ form.description } onChange={ ( e ) => set( 'description', e.target.value ) } />
                        </div>
                    </fieldset>

                    <fieldset className="gratora-fset">
                        <legend>{ __( 'Classification', 'gratora-donation-platform' ) }</legend>
                        <div className="gratora-fld">
                            <span id="gratora-fund-type-label" className="gratora-fld__label">{ __( 'Type', 'gratora-donation-platform' ) }</span>
                            <div className="gratora-seg2" role="group" aria-labelledby="gratora-fund-type-label">
                                <button type="button" className={ ! form.is_restricted ? 'is-active' : '' } onClick={ () => set( 'is_restricted', false ) }>
                                    { __( 'Unrestricted', 'gratora-donation-platform' ) }
                                </button>
                                <button type="button" className={ form.is_restricted ? 'is-active' : '' } onClick={ () => set( 'is_restricted', true ) }>
                                    { __( 'Restricted', 'gratora-donation-platform' ) }
                                </button>
                            </div>
                            <p className="gratora-fld__help">{ __( 'Restricted funds are donor-designated and reported separately.', 'gratora-donation-platform' ) }</p>
                        </div>
                        <div className="gratora-fld">
                            { /* SearchableSelect takes no id, so the group carries the name instead. */ }
                            <span id="gratora-fund-parent-label" className="gratora-fld__label">{ __( 'Parent fund', 'gratora-donation-platform' ) }</span>
                            <div role="group" aria-labelledby="gratora-fund-parent-label">
                            <SearchableSelect
                                value={ form.parent_fund_id ? String( form.parent_fund_id ) : '' }
                                onChange={ ( v ) => set( 'parent_fund_id', v ) }
                                placeholder={ __( 'None (top-level fund)', 'gratora-donation-platform' ) }
                                options={ [
                                    { value: '', label: __( 'None (top-level fund)', 'gratora-donation-platform' ) },
                                    ...parents.map( ( p ) => ( { value: String( p.id ), label: p.name } ) ),
                                ] }
                            />
                            </div>
                        </div>
                    </fieldset>

                    <fieldset className="gratora-fset">
                        <legend>{ __( 'Targets', 'gratora-donation-platform' ) }</legend>
                        <div className="gratora-fld">
                            <label htmlFor="gratora-fund-goal">{ __( 'Goal amount', 'gratora-donation-platform' ) } <span className="gratora-fld__opt">{ __( 'optional', 'gratora-donation-platform' ) }</span></label>
                            <input id="gratora-fund-goal" className="gratora-input" type="number" min="0" step="0.01" placeholder={ __( 'No goal', 'gratora-donation-platform' ) } value={ form.goal } onChange={ ( e ) => set( 'goal', e.target.value ) } />
                        </div>
                        <ScheduleFields
                            enabled={ scheduleOn }
                            onToggle={ setScheduleOn }
                            startsAt={ form.starts_at ? form.starts_at.slice( 0, 10 ) : '' }
                            onStartsAt={ ( v ) => set( 'starts_at', v || '' ) }
                            endsAt={ form.ends_at ? form.ends_at.slice( 0, 10 ) : '' }
                            onEndsAt={ ( v ) => set( 'ends_at', v || '' ) }
                            disabled={ form.is_default }
                            disabledNote={ __( 'The default fund takes every donation with no fund chosen, so it stays open. Make another fund the default to schedule this one.', 'gratora-donation-platform' ) }
                        />
                    </fieldset>

                    <fieldset className="gratora-fset">
                        <legend>{ __( 'Accounting', 'gratora-donation-platform' ) }</legend>
                        <div className="gratora-fld">
                            <label htmlFor="gratora-fund-accounting">{ __( 'Accounting code', 'gratora-donation-platform' ) } <span className="gratora-fld__opt">{ __( 'optional', 'gratora-donation-platform' ) }</span></label>
                            <input id="gratora-fund-accounting" aria-describedby="gratora-fund-accounting-help" className="gratora-input gratora-input--mono" placeholder={ __( 'Enter accounting code', 'gratora-donation-platform' ) } value={ form.accounting_code } onChange={ ( e ) => set( 'accounting_code', e.target.value ) } />
                            <p id="gratora-fund-accounting-help" className="gratora-fld__help">{ __( 'Maps this fund to a GL account in your bookkeeping. Included in exports.', 'gratora-donation-platform' ) }</p>
                        </div>
                    </fieldset>

                    <fieldset className="gratora-fset">
                        <legend>{ __( 'Behaviour', 'gratora-donation-platform' ) }</legend>
                        <ToggleRow
                            title={ __( 'Default fund', 'gratora-donation-platform' ) }
                            // Off is not a move the server accepts: a site always
                            // has a default, and it changes by promoting another
                            // fund rather than by clearing this one.
                            sub={ fund.is_default
                                ? __( 'Donations with no chosen fund (and campaigns with no default) are allocated here. Promote another fund to move it.', 'gratora-donation-platform' )
                                : __( 'Donations with no chosen fund (and campaigns with no default) are allocated here. The default has no schedule.', 'gratora-donation-platform' ) }
                            checked={ form.is_default }
                            onChange={ setIsDefault }
                            disabled={ !! fund.is_default }
                        />
                        <ToggleRow
                            title={ __( 'Active', 'gratora-donation-platform' ) }
                            sub={ __( 'Inactive funds stay in reports but cannot receive new donations.', 'gratora-donation-platform' ) }
                            checked={ form.is_active }
                            onChange={ ( v ) => set( 'is_active', v ) }
                        />
                    </fieldset>
        </Dialog>
    );
}

function FundDeleteModal( { fund, funds, fundsState = 'ready', onRetryFunds, onClose, onDone } ) {
    const [ choice, setChoice ]   = useState( 'deactivate' );
    const [ targetId, setTargetId ] = useState( '' );
    const [ busy, setBusy ]       = useState( false );
    // Kept here rather than handed up: the page-level notice sits under the
    // dialog's own scrim, so a refused delete read as nothing happening.
    const [ error, setError ]     = useState( null );

    // Reassigning removes this fund, which would orphan its sub-funds, so the
    // server refuses that one outcome. Deactivating a parent is supported.
    const hasChildren = fund.has_children === true;

    const candidates = hasChildren ? [] : ( funds || [] ).filter(
        ( f ) => f.id !== fund.id && f.is_active && ! f.reassign_pending
    );

    const confirm = async () => {
        if ( choice === 'reassign' && ! targetId ) return;
        setError( null );
        setBusy( true );
        try {
            const path = choice === 'reassign'
                ? addQueryArgs( `/gratora/v1/admin/funds/${ fund.id }`, { reassign_to: Number( targetId ) } )
                : `/gratora/v1/admin/funds/${ fund.id }`;
            const res = await apiFetch( { path, method: 'DELETE' } );
            let msg;
            if ( res.action === 'deleted' ) {
                msg = sprintf( /* translators: %s: fund name */ __( 'Fund “%s” was deleted.', 'gratora-donation-platform' ), fund.name );
            } else if ( res.action === 'reassign_queued' ) {
                msg = sprintf( /* translators: %s: fund name */ __( 'Reassigning donations from “%s”. It will be removed once complete.', 'gratora-donation-platform' ), fund.name );
            } else {
                msg = sprintf(
                    /* translators: %s: fund name */
                    __( 'Fund “%s” was deactivated and kept for reporting.', 'gratora-donation-platform' ),
                    fund.name
                );
            }
            onDone( msg );
        } catch ( err ) {
            setError( err?.message || __( 'Could not delete the fund.', 'gratora-donation-platform' ) );
            setBusy( false );
        }
    };

    // The server hard-deletes only a fund with zero references (donations of
    // any status, plus campaigns/forms/plans that point to it); otherwise it
    // deactivates. `deletable` is that authoritative verdict, so the dialog
    // offers the action the server will actually take.
    const deletable = fund.deletable === true;
    const reassignBlocked = choice === 'reassign' && ! targetId;
    const primaryLabel = deletable
        ? __( 'Delete fund', 'gratora-donation-platform' )
        : ( choice === 'reassign' ? __( 'Reassign and delete', 'gratora-donation-platform' ) : __( 'Deactivate fund', 'gratora-donation-platform' ) );

    return (
        <Dialog
            title={ sprintf( /* translators: %s: fund name */ __( 'Delete fund: %s', 'gratora-donation-platform' ), fund.name ) }
            onClose={ onClose }
            foot={ (
                <>
                    <Btn onClick={ onClose } disabled={ busy }>{ __( 'Cancel', 'gratora-donation-platform' ) }</Btn>
                    <Btn
                        variant="primary"
                        isBusy={ busy }
                        disabled={ busy || reassignBlocked }
                        onClick={ confirm }
                    >
                        { primaryLabel }
                    </Btn>
                </>
            ) }
        >
            { error && (
                <Notice status="error" onRemove={ () => setError( null ) }>{ error }</Notice>
            ) }

            { deletable ? (
                <p className="gratora-dialog__help">
                    { __( 'Nothing points to this fund, so deleting it removes it entirely.', 'gratora-donation-platform' ) }
                </p>
            ) : (
                <>
                    <p className="gratora-dialog__help">
                        { __( 'This fund is still referenced, so it is never hard-deleted. Choose what to do:', 'gratora-donation-platform' ) }
                    </p>

                    <label className="gratora-choice" htmlFor="gratora-fund-delete-deactivate">
                        <input
                            id="gratora-fund-delete-deactivate"
                            type="radio"
                            name="gratora-fund-delete"
                            checked={ choice === 'deactivate' }
                            onChange={ () => setChoice( 'deactivate' ) }
                        />
                        <span>
                            <strong>{ __( 'Deactivate', 'gratora-donation-platform' ) }</strong>
                            <span>{ __( 'Keep all donation records. Recommended.', 'gratora-donation-platform' ) }</span>
                        </span>
                    </label>

                    { /* Offer reassignment only when another fund is available. */ }
                    { candidates.length > 0 ? (
                        <label className="gratora-choice" htmlFor="gratora-fund-delete-reassign">
                            <input
                                id="gratora-fund-delete-reassign"
                                type="radio"
                                name="gratora-fund-delete"
                                checked={ choice === 'reassign' }
                                onChange={ () => setChoice( 'reassign' ) }
                            />
                            <span>
                                <strong>{ __( 'Reassign to another fund, then delete', 'gratora-donation-platform' ) }</strong>
                                <span>{ __( 'Moves every donation, campaign and form that points here onto the chosen fund, then removes this one.', 'gratora-donation-platform' ) }</span>
                            </span>
                        </label>
                    ) : (
                        <p className="gratora-dialog__help">
                            { hasChildren
                                ? __( 'This fund has sub-funds under it, so it cannot be removed. Deactivating keeps them; they move up to the top level. To remove it outright, move or delete the sub-funds first.', 'gratora-donation-platform' )
                                : fundsState === 'loading'
                                    ? __( 'Still loading the other funds, so reassigning is not offered yet.', 'gratora-donation-platform' )
                                    : fundsState === 'failed'
                                        ? __( 'The list of other funds could not be loaded, so reassigning is not offered. Deactivating is safe either way.', 'gratora-donation-platform' )
                                        : __( 'There is no other active fund to reassign to, so deactivating is the only option here. Create another fund first if you want to move these donations.', 'gratora-donation-platform' ) }
                            { fundsState === 'failed' && typeof onRetryFunds === 'function' && (
                                <button type="button" className="gratora-linkbtn" onClick={ onRetryFunds }>
                                    { __( 'Try again', 'gratora-donation-platform' ) }
                                </button>
                            ) }
                        </p>
                    ) }

                    { choice === 'reassign' && candidates.length > 0 && (
                        <div className="gratora-fld" style={ { marginTop: 12 } }>
                            <label className="gratora-fld__label">{ __( 'Reassign donations to', 'gratora-donation-platform' ) }</label>
                            <SearchableSelect
                                value={ targetId }
                                onChange={ ( v ) => setTargetId( v ) }
                                placeholder={ __( 'Select a fund', 'gratora-donation-platform' ) }
                                options={ candidates.map( ( f ) => ( { value: String( f.id ), label: f.name } ) ) }
                            />
                        </div>
                    ) }
                </>
            ) }
        </Dialog>
    );
}
