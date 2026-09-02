import { useState, useEffect, useMemo, useCallback } from '@wordpress/element';
import { DataViews } from '@wordpress/dataviews';
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

const STATUS_OPTIONS = [
    { value: 'active',     label: __( 'Active', 'fundkit-fundraising-campaigns' ) },
    { value: 'inactive',   label: __( 'Inactive', 'fundkit-fundraising-campaigns' ) },
    { value: 'restricted', label: __( 'Restricted', 'fundkit-fundraising-campaigns' ) },
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

/** @since 1.0.0 */
export function fundStatusLabel( item ) {
    if ( ! item.is_active ) return __( 'Inactive', 'fundkit-fundraising-campaigns' );
    if ( item.schedule_state === 'scheduled' ) return __( 'Scheduled', 'fundkit-fundraising-campaigns' );
    if ( item.schedule_state === 'ended' ) return __( 'Ended', 'fundkit-fundraising-campaigns' );
    return __( 'Active', 'fundkit-fundraising-campaigns' );
}

function fundKpis( stats ) {
    return [
        {
            label: __( 'Total raised', 'fundkit-fundraising-campaigns' ),
            value: stats ? formatAmount( stats.raised_cents ) : '-',
            sub:   __( 'all funds', 'fundkit-fundraising-campaigns' ),
        },
        {
            label: __( 'Active funds', 'fundkit-fundraising-campaigns' ),
            value: stats ? String( stats.active ) : '-',
            sub:   stats ? `${ __( 'of', 'fundkit-fundraising-campaigns' ) } ${ stats.total }` : null,
        },
        {
            label: __( 'Restricted', 'fundkit-fundraising-campaigns' ),
            value: stats ? String( stats.restricted ) : '-',
            sub:   __( 'donor-restricted', 'fundkit-fundraising-campaigns' ),
        },
        {
            label: __( 'Default fund', 'fundkit-fundraising-campaigns' ),
            value: stats ? ( stats.default ? stats.default.name : __( 'None', 'fundkit-fundraising-campaigns' ) ) : '-',
        },
    ];
}

export default function List() {
    const [ view, setView ] = useTableView( 'funds', {
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
    const [ loading, setLoading ]   = useState( false );
    const [ error, setError ]       = useState( null );
    const [ editing, setEditing ]   = useState( null );
    const [ deleteTarget, setDeleteTarget ] = useState( null );
    const [ allFunds, setAllFunds ] = useState( [] );
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
        let aborted = false;
        setLoading( true );
        setError( null );

        apiFetch( {
            path: addQueryArgs( '/fundkit/v1/admin/funds', {
                page:     view.page,
                per_page: view.perPage,
                orderby:  view.sort?.field || 'sort_order',
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
                setTestHidden( parseInt( res.headers.get( 'X-FundKit-Test-Hidden' ) || '0', 10 ) );
            } )
            .catch( ( err ) => {
                if ( aborted ) return;
                setError( err?.message || __( 'Failed to load funds.', 'fundkit-fundraising-campaigns' ) );
            } )
            .finally( () => ! aborted && setLoading( false ) );

        return () => { aborted = true; };
    }, [ view, statusFilter ] );

    // The parent and reassign pickers were fed the table's current page, so a
    // second page of funds was unreachable as a parent and as a reassign
    // target. They need the whole set, filters and pagination aside.
    const loadAll = useCallback( () => {
        const collect = async () => {
            const out = [];
            for ( let page = 1; page <= 20; page++ ) {
                const res = await apiFetch( {
                    path: addQueryArgs( '/fundkit/v1/admin/funds', { page, per_page: 100, orderby: 'sort_order', order: 'asc' } ),
                } );
                const items = Array.isArray( res ) ? res : [];
                out.push( ...items );
                if ( items.length < 100 ) break;
            }
            return out;
        };

        collect()
            .then( setAllFunds )
            // The table itself already surfaced any load failure; leaving the
            // pickers empty is better than blocking the dialog on a retry.
            .catch( ( err ) => window.console?.error( '[fundkit] fund picker list failed to load', err ) );
    }, [] );

    const loadStats = useCallback( () => {
        setStatsLoading( true );
        apiFetch( { path: '/fundkit/v1/admin/funds/stats' } )
            .then( setStats )
            .catch( ( err ) => {
                // Don't strand the KPI strip in a perpetual spinner; settling
                // loading resolves it to em-dashes instead.
                window.console?.error( '[fundkit] fund stats failed to load', err );
            } )
            .finally( () => setStatsLoading( false ) );
    }, [] );

    useEffect( () => load(), [ load, reload ] );
    useEffect( () => loadStats(), [ loadStats, reload ] );
    useEffect( () => loadAll(), [ loadAll, reload ] );

    const afterChange = useCallback( () => {
        setReload( ( n ) => n + 1 );
    }, [] );

    const mutate = useCallback( async ( id, payload ) => {
        try {
            await apiFetch( { path: `/fundkit/v1/admin/funds/${ id }`, method: 'POST', data: payload } );
            afterChange();
        } catch ( err ) {
            setError( err?.message || __( 'Action failed.', 'fundkit-fundraising-campaigns' ) );
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
            label:         __( 'Fund', 'fundkit-fundraising-campaigns' ),
            enableSorting: true,
            render: ( { item } ) => (
                <div
                    className={ item.__depth ? 'fundkit-fund-name fundkit-fund-name--child' : 'fundkit-fund-name' }
                    style={ item.__depth ? { paddingLeft: `${ item.__depth * 26 }px` } : undefined }
                >
                    <div style={ { lineHeight: 1.35 } }>
                        <button
                            type="button"
                            className="fundkit-row__link fundkit-row__link--strong"
                            onMouseDown={ stopRowSelect }
                            onClick={ ( e ) => { stopRowSelect( e ); setEditing( item ); } }
                        >
                            { item.name }
                        </button>
                        { item.is_default && (
                            <span className="fundkit-fund-badge fundkit-fund-badge--default">
                                { __( 'Default', 'fundkit-fundraising-campaigns' ) }
                            </span>
                        ) }
                        <div className="fundkit-fund-code">{ item.code }</div>
                    </div>
                </div>
            ),
        },
        {
            id:       'type',
            label:    __( 'Type', 'fundkit-fundraising-campaigns' ),
            render: ( { item } ) => (
                <span className={ 'fundkit-fund-badge ' + ( item.is_restricted
                    ? 'fundkit-fund-badge--restricted'
                    : 'fundkit-fund-badge--unrestricted' ) }>
                    { item.is_restricted ? __( 'Restricted', 'fundkit-fundraising-campaigns' ) : __( 'Unrestricted', 'fundkit-fundraising-campaigns' ) }
                </span>
            ),
        },
        {
            id:    'raised',
            label: __( 'Raised', 'fundkit-fundraising-campaigns' ),
            // Same reason the goal column is not sortable: a parent's raised is
            // rolled up in PHP after the query.
            enableSorting: false,
            render: ( { item } ) => (
                <span className="fundkit-fund-raised">{ formatAmount( item.raised_cents ) }</span>
            ),
        },
        {
            id:            'goal',
            label:         __( 'Goal progress', 'fundkit-fundraising-campaigns' ),
            // Not sortable: the column shows raised-vs-goal percentage, but a
            // parent's raised is rolled up in PHP after the query, so no DB
            // sort key reflects what's displayed.
            enableSorting: false,
            render: ( { item } ) => {
                if ( ! item.goal_cents ) {
                    return (
                        <GoalBar left={ __( 'No goal set', 'fundkit-fundraising-campaigns' ) } pct={ 0 } muted />
                    );
                }
                const pct = Math.min( 100, Math.round( ( item.raised_cents / item.goal_cents ) * 100 ) );
                return (
                    <GoalBar
                        left={ formatAmount( item.raised_cents ) }
                        right={ `${ __( 'of', 'fundkit-fundraising-campaigns' ) } ${ formatAmount( item.goal_cents ) }` }
                        pct={ pct }
                    />
                );
            },
        },
        {
            id:       'status',
            label:    __( 'Status', 'fundkit-fundraising-campaigns' ),
            elements: STATUS_OPTIONS,
            filterBy: { operators: [ 'is' ] },
            render: ( { item } ) => {
                if ( item.reassign_pending ) {
                    return (
                        <span className="fundkit-fund-badge fundkit-fund-badge--pending">
                            { __( 'Reassigning…', 'fundkit-fundraising-campaigns' ) }
                        </span>
                    );
                }
                return (
                    <span className="fundkit-fund-status">
                        <span className={ 'fundkit-fund-dot ' + ( fundIsOpen( item ) ? 'is-on' : 'is-off' ) } />
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
            label:      __( 'Edit', 'fundkit-fundraising-campaigns' ),
            icon:       () => <Pencil size={ 16 } strokeWidth={ 1.75 } />,
            isEligible: ( item ) => ! item.reassign_pending,
            callback:   ( [ item ] ) => setEditing( item ),
        },
        {
            id:         'set-default',
            label:      __( 'Set as default', 'fundkit-fundraising-campaigns' ),
            icon:       () => <Star size={ 16 } strokeWidth={ 1.75 } />,
            isEligible: ( item ) => ! item.is_default && item.is_active && ! item.reassign_pending,
            callback:   ( [ item ] ) => mutate( item.id, { is_default: true } ),
        },
        {
            id:         'deactivate',
            label:      __( 'Deactivate', 'fundkit-fundraising-campaigns' ),
            icon:       () => <PowerOff size={ 16 } strokeWidth={ 1.75 } />,
            isEligible: ( item ) => ! item.is_default && item.is_active && ! item.reassign_pending,
            callback:   ( [ item ] ) => mutate( item.id, { is_active: false } ),
        },
        {
            id:         'activate',
            label:      __( 'Activate', 'fundkit-fundraising-campaigns' ),
            icon:       () => <Power size={ 16 } strokeWidth={ 1.75 } />,
            isEligible: ( item ) => ! item.is_default && ! item.is_active && ! item.reassign_pending,
            callback:   ( [ item ] ) => mutate( item.id, { is_active: true } ),
        },
        {
            id:            'delete',
            label:         __( 'Delete', 'fundkit-fundraising-campaigns' ),
            icon:          () => <TrashIcon size={ 16 } strokeWidth={ 1.75 } />,
            isDestructive: true,
            isEligible:    ( item ) => ! item.is_default && ! item.reassign_pending,
            callback:      ( [ item ] ) => setDeleteTarget( item ),
        },
    ], [ mutate ] );

    return (
        <div>
            <div className="fundkit-crumbs">
                <a href={ dashboardHref( window.location.pathname ) }>{ __( 'FundKit', 'fundkit-fundraising-campaigns' ) }</a>
                <span className="sep">›</span>
                <span>{ __( 'Funds', 'fundkit-fundraising-campaigns' ) }</span>
            </div>
            <div className="fundkit-page-head">
                <div className="fundkit-page-head__title-row">
                    <h1>{ __( 'Funds', 'fundkit-fundraising-campaigns' ) }</h1>
                </div>
                <div className="fundkit-page-head__right">
                    <span className="fundkit-page-head__meta">
                        { sprintf( /* translators: %s: number of funds */ _n( '%s fund', '%s funds', total, 'fundkit-fundraising-campaigns' ), total.toLocaleString() ) }
                    </span>
                    <Btn variant="primary" onClick={ onCreate }>
                        <Plus size={ 16 } strokeWidth={ 1.75 } />
                        { __( 'New fund', 'fundkit-fundraising-campaigns' ) }
                    </Btn>
                </div>
            </div>

            <p className="fundkit-funds-intro">
                { __( 'Organization-wide designations donations are allocated to.', 'fundkit-fundraising-campaigns' ) }
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
                            'fundkit-fundraising-campaigns'
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
                    title={ __( 'No funds yet', 'fundkit-fundraising-campaigns' ) }
                    body={ __( 'Funds route donations to specific causes within your organization. Forms without a fund picker drop into the organization default.', 'fundkit-fundraising-campaigns' ) }
                    action={
                        <Btn variant="primary" onClick={ onCreate }>
                            { __( 'Create your first fund', 'fundkit-fundraising-campaigns' ) }
                        </Btn>
                    }
                />
            ) : (
                <div className={ `fundkit-dataviews${ ! loading && data.length === 0 && filtered ? ' is-no-results' : '' }` }>
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
                            title={ __( 'Nothing matches these filters', 'fundkit-fundraising-campaigns' ) }
                            body={ __( 'Try a different search, or clear the filters to see everything again.', 'fundkit-fundraising-campaigns' ) }
                            action={
                                <Btn variant="secondary" onClick={ clearFilters }>
                                    { __( 'Clear filters', 'fundkit-fundraising-campaigns' ) }
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
                    onClose={ () => setDeleteTarget( null ) }
                    onError={ ( m ) => setError( m ) }
                    onDone={ ( msg ) => { setDeleteTarget( null ); notify.success( msg ); afterChange(); } }
                />
            ) }
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

    const save = async () => {
        setSaving( true );
        setSaveError( null );
        try {
            await apiFetch( {
                // No id means this fund has never been saved, so this is the
                // create rather than an update.
                path:   fund.id ? `/fundkit/v1/admin/funds/${ fund.id }` : '/fundkit/v1/admin/funds',
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
                    starts_at:       form.starts_at || null,
                    ends_at:         form.ends_at || null,
                    accounting_code: form.accounting_code || null,
                },
            } );
            onSaved();
        } catch ( err ) {
            setSaveError( err?.message || __( 'Failed to save fund.', 'fundkit-fundraising-campaigns' ) );
            setSaving( false );
        }
    };

    const parents = ( allFunds || [] ).filter( ( f ) => f.id !== fund.id );

    return (
        <Dialog
            title={ fund.id
                ? __( 'Edit fund', 'fundkit-fundraising-campaigns' )
                : __( 'New fund', 'fundkit-fundraising-campaigns' ) }
            onClose={ onClose }
            foot={ (
                <>
                    <Btn onClick={ onClose }>{ __( 'Cancel', 'fundkit-fundraising-campaigns' ) }</Btn>
                    <Btn variant="primary" onClick={ save } isBusy={ saving } disabled={ saving }>
                        { fund.id
                            ? __( 'Save fund', 'fundkit-fundraising-campaigns' )
                            : __( 'Create fund', 'fundkit-fundraising-campaigns' ) }
                    </Btn>
                </>
            ) }
        >
            { saveError && (
                        <Notice status="error" onRemove={ () => setSaveError( null ) }>{ saveError }</Notice>
                    ) }
                    <fieldset className="fundkit-fset">
                        <legend>{ __( 'Identity', 'fundkit-fundraising-campaigns' ) }</legend>
                        <div className="fundkit-fld">
                            <label>{ __( 'Name', 'fundkit-fundraising-campaigns' ) }</label>
                            <input className="fundkit-input" value={ form.name } onChange={ ( e ) => set( 'name', e.target.value ) } />
                        </div>
                        <div className="fundkit-fld">
                            <label>{ __( 'Code', 'fundkit-fundraising-campaigns' ) }</label>
                            <input className="fundkit-input fundkit-input--mono" value={ form.code } onChange={ ( e ) => set( 'code', e.target.value ) } />
                            <p className="fundkit-fld__help">{ __( 'Stable identifier used in exports and accounting. Lowercase, no spaces. Avoid changing once donations exist.', 'fundkit-fundraising-campaigns' ) }</p>
                        </div>
                        <div className="fundkit-fld">
                            <label>{ __( 'Description', 'fundkit-fundraising-campaigns' ) }</label>
                            <textarea className="fundkit-textarea" rows="3" value={ form.description } onChange={ ( e ) => set( 'description', e.target.value ) } />
                        </div>
                    </fieldset>

                    <fieldset className="fundkit-fset">
                        <legend>{ __( 'Classification', 'fundkit-fundraising-campaigns' ) }</legend>
                        <div className="fundkit-fld">
                            <label>{ __( 'Type', 'fundkit-fundraising-campaigns' ) }</label>
                            <div className="fundkit-seg2">
                                <button type="button" className={ ! form.is_restricted ? 'is-active' : '' } onClick={ () => set( 'is_restricted', false ) }>
                                    { __( 'Unrestricted', 'fundkit-fundraising-campaigns' ) }
                                </button>
                                <button type="button" className={ form.is_restricted ? 'is-active' : '' } onClick={ () => set( 'is_restricted', true ) }>
                                    { __( 'Restricted', 'fundkit-fundraising-campaigns' ) }
                                </button>
                            </div>
                            <p className="fundkit-fld__help">{ __( 'Restricted funds are donor-designated and reported separately.', 'fundkit-fundraising-campaigns' ) }</p>
                        </div>
                        <div className="fundkit-fld">
                            <label>{ __( 'Parent fund', 'fundkit-fundraising-campaigns' ) }</label>
                            <SearchableSelect
                                value={ form.parent_fund_id ? String( form.parent_fund_id ) : '' }
                                onChange={ ( v ) => set( 'parent_fund_id', v ) }
                                placeholder={ __( 'None (top-level fund)', 'fundkit-fundraising-campaigns' ) }
                                options={ [
                                    { value: '', label: __( 'None (top-level fund)', 'fundkit-fundraising-campaigns' ) },
                                    ...parents.map( ( p ) => ( { value: String( p.id ), label: p.name } ) ),
                                ] }
                            />
                        </div>
                    </fieldset>

                    <fieldset className="fundkit-fset">
                        <legend>{ __( 'Targets', 'fundkit-fundraising-campaigns' ) }</legend>
                        <div className="fundkit-fld">
                            <label>{ __( 'Goal amount', 'fundkit-fundraising-campaigns' ) } <span className="fundkit-fld__opt">{ __( 'optional', 'fundkit-fundraising-campaigns' ) }</span></label>
                            <input className="fundkit-input" type="number" min="0" step="0.01" placeholder={ __( 'No goal', 'fundkit-fundraising-campaigns' ) } value={ form.goal } onChange={ ( e ) => set( 'goal', e.target.value ) } />
                        </div>
                        <ScheduleFields
                            enabled={ scheduleOn }
                            onToggle={ setScheduleOn }
                            startsAt={ form.starts_at ? form.starts_at.slice( 0, 10 ) : '' }
                            onStartsAt={ ( v ) => set( 'starts_at', v || '' ) }
                            endsAt={ form.ends_at ? form.ends_at.slice( 0, 10 ) : '' }
                            onEndsAt={ ( v ) => set( 'ends_at', v || '' ) }
                        />
                    </fieldset>

                    <fieldset className="fundkit-fset">
                        <legend>{ __( 'Accounting', 'fundkit-fundraising-campaigns' ) }</legend>
                        <div className="fundkit-fld">
                            <label>{ __( 'Accounting code', 'fundkit-fundraising-campaigns' ) } <span className="fundkit-fld__opt">{ __( 'optional', 'fundkit-fundraising-campaigns' ) }</span></label>
                            <input className="fundkit-input fundkit-input--mono" placeholder={ __( 'Enter accounting code', 'fundkit-fundraising-campaigns' ) } value={ form.accounting_code } onChange={ ( e ) => set( 'accounting_code', e.target.value ) } />
                            <p className="fundkit-fld__help">{ __( 'Maps this fund to a GL account in your bookkeeping. Included in exports.', 'fundkit-fundraising-campaigns' ) }</p>
                        </div>
                    </fieldset>

                    <fieldset className="fundkit-fset">
                        <legend>{ __( 'Behaviour', 'fundkit-fundraising-campaigns' ) }</legend>
                        <ToggleRow
                            title={ __( 'Default fund', 'fundkit-fundraising-campaigns' ) }
                            sub={ __( 'Donations with no chosen fund (and campaigns with no default) are allocated here.', 'fundkit-fundraising-campaigns' ) }
                            checked={ form.is_default }
                            onChange={ ( v ) => set( 'is_default', v ) }
                        />
                        <ToggleRow
                            title={ __( 'Active', 'fundkit-fundraising-campaigns' ) }
                            sub={ __( 'Inactive funds stay in reports but cannot receive new donations.', 'fundkit-fundraising-campaigns' ) }
                            checked={ form.is_active }
                            onChange={ ( v ) => set( 'is_active', v ) }
                        />
                    </fieldset>
        </Dialog>
    );
}

function FundDeleteModal( { fund, funds, onClose, onError, onDone } ) {
    const [ choice, setChoice ]   = useState( 'deactivate' );
    const [ targetId, setTargetId ] = useState( '' );
    const [ busy, setBusy ]       = useState( false );

    const candidates = ( funds || [] ).filter(
        ( f ) => f.id !== fund.id && f.is_active && ! f.reassign_pending
    );

    const confirm = async () => {
        if ( choice === 'reassign' && ! targetId ) return;
        setBusy( true );
        try {
            const path = choice === 'reassign'
                ? addQueryArgs( `/fundkit/v1/admin/funds/${ fund.id }`, { reassign_to: Number( targetId ) } )
                : `/fundkit/v1/admin/funds/${ fund.id }`;
            const res = await apiFetch( { path, method: 'DELETE' } );
            let msg;
            if ( res.action === 'deleted' ) {
                msg = sprintf( /* translators: %s: fund name */ __( 'Fund “%s” was deleted.', 'fundkit-fundraising-campaigns' ), fund.name );
            } else if ( res.action === 'reassign_queued' ) {
                msg = sprintf( /* translators: %s: fund name */ __( 'Reassigning donations from “%s”. It will be removed once complete.', 'fundkit-fundraising-campaigns' ), fund.name );
            } else {
                msg = sprintf(
                    /* translators: %s: fund name */
                    __( 'Fund “%s” was deactivated and kept for reporting.', 'fundkit-fundraising-campaigns' ),
                    fund.name
                );
            }
            onDone( msg );
        } catch ( err ) {
            onError( err?.message || __( 'Could not delete the fund.', 'fundkit-fundraising-campaigns' ) );
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
        ? __( 'Delete fund', 'fundkit-fundraising-campaigns' )
        : ( choice === 'reassign' ? __( 'Reassign and delete', 'fundkit-fundraising-campaigns' ) : __( 'Deactivate fund', 'fundkit-fundraising-campaigns' ) );

    return (
        <Dialog
            title={ sprintf( /* translators: %s: fund name */ __( 'Delete fund: %s', 'fundkit-fundraising-campaigns' ), fund.name ) }
            onClose={ onClose }
            foot={ (
                <>
                    <Btn onClick={ onClose } disabled={ busy }>{ __( 'Cancel', 'fundkit-fundraising-campaigns' ) }</Btn>
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
            { deletable ? (
                <p className="fundkit-dialog__help">
                    { __( 'Nothing points to this fund, so deleting it removes it entirely.', 'fundkit-fundraising-campaigns' ) }
                </p>
            ) : (
                <>
                    <p className="fundkit-dialog__help">
                        { __( 'This fund is still referenced, so it is never hard-deleted. Choose what to do:', 'fundkit-fundraising-campaigns' ) }
                    </p>

                    <label className="fundkit-choice" htmlFor="fundkit-fund-delete-deactivate">
                        <input
                            id="fundkit-fund-delete-deactivate"
                            type="radio"
                            name="fundkit-fund-delete"
                            checked={ choice === 'deactivate' }
                            onChange={ () => setChoice( 'deactivate' ) }
                        />
                        <span>
                            <strong>{ __( 'Deactivate', 'fundkit-fundraising-campaigns' ) }</strong>
                            <span>{ __( 'Keep all donation records. Recommended.', 'fundkit-fundraising-campaigns' ) }</span>
                        </span>
                    </label>

                    { /* Offering this with nothing to reassign to left the
                         author on an empty picker and a permanently disabled
                         button, with nothing saying Deactivate was the only
                         route. A site with one fund is the common shape here,
                         since a fund only reaches this dialog once it has
                         donations. */ }
                    { candidates.length > 0 ? (
                        <label className="fundkit-choice" htmlFor="fundkit-fund-delete-reassign">
                            <input
                                id="fundkit-fund-delete-reassign"
                                type="radio"
                                name="fundkit-fund-delete"
                                checked={ choice === 'reassign' }
                                onChange={ () => setChoice( 'reassign' ) }
                            />
                            <span>
                                <strong>{ __( 'Reassign to another fund, then delete', 'fundkit-fundraising-campaigns' ) }</strong>
                                <span>{ __( 'Moves every donation, campaign and form that points here onto the chosen fund, then removes this one.', 'fundkit-fundraising-campaigns' ) }</span>
                            </span>
                        </label>
                    ) : (
                        <p className="fundkit-dialog__help">
                            { __( 'There is no other active fund to reassign to, so deactivating is the only option here. Create another fund first if you want to move these donations.', 'fundkit-fundraising-campaigns' ) }
                        </p>
                    ) }

                    { choice === 'reassign' && candidates.length > 0 && (
                        <div className="fundkit-fld" style={ { marginTop: 12 } }>
                            <label className="fundkit-fld__label">{ __( 'Reassign donations to', 'fundkit-fundraising-campaigns' ) }</label>
                            <SearchableSelect
                                value={ targetId }
                                onChange={ ( v ) => setTargetId( v ) }
                                placeholder={ __( 'Select a fund', 'fundkit-fundraising-campaigns' ) }
                                options={ candidates.map( ( f ) => ( { value: String( f.id ), label: f.name } ) ) }
                            />
                        </div>
                    ) }
                </>
            ) }
        </Dialog>
    );
}
