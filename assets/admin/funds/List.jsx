import { useState, useEffect, useMemo, useCallback } from '@wordpress/element';
import { DataViews } from '@wordpress/dataviews';
import Notice from '../_shared/components/Notice';
import { notify } from '../_shared/notify';
import { dashboardHref } from '../_shared/adminPages';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Pencil, Trash2 as TrashIcon, Star, Power, PowerOff, Wallet, Plus } from 'lucide-react';

import { formatAmount } from '../_shared/format';
import Btn from '../_shared/components/Btn';
import { stopRowSelect } from '../_shared/rowLink';
import EmptyState from '../_shared/components/EmptyState';
import Dialog from '../_shared/components/Dialog';
import ScheduleFields from '../_shared/components/ScheduleFields';
import { ToggleRow } from '../_shared/components/Switch';
import KpiStrip from '../_shared/components/KpiStrip';
import GoalBar from '../_shared/components/GoalBar';
import SearchableSelect from '../_shared/components/SearchableSelect';

const STATUS_OPTIONS = [
    { value: 'active',     label: __( 'Active', 'giveflow-fundraising-campaigns' ) },
    { value: 'inactive',   label: __( 'Inactive', 'giveflow-fundraising-campaigns' ) },
    { value: 'restricted', label: __( 'Restricted', 'giveflow-fundraising-campaigns' ) },
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
    if ( ! item.is_active ) return __( 'Inactive', 'giveflow-fundraising-campaigns' );
    if ( item.schedule_state === 'scheduled' ) return __( 'Scheduled', 'giveflow-fundraising-campaigns' );
    if ( item.schedule_state === 'ended' ) return __( 'Ended', 'giveflow-fundraising-campaigns' );
    return __( 'Active', 'giveflow-fundraising-campaigns' );
}

function fundKpis( stats ) {
    return [
        {
            label: __( 'Total raised', 'giveflow-fundraising-campaigns' ),
            value: stats ? formatAmount( stats.raised_cents ) : '-',
            sub:   __( 'all funds', 'giveflow-fundraising-campaigns' ),
        },
        {
            label: __( 'Active funds', 'giveflow-fundraising-campaigns' ),
            value: stats ? String( stats.active ) : '-',
            sub:   stats ? `${ __( 'of', 'giveflow-fundraising-campaigns' ) } ${ stats.total }` : null,
        },
        {
            label: __( 'Restricted', 'giveflow-fundraising-campaigns' ),
            value: stats ? String( stats.restricted ) : '-',
            sub:   __( 'donor-restricted', 'giveflow-fundraising-campaigns' ),
        },
        {
            label: __( 'Default fund', 'giveflow-fundraising-campaigns' ),
            value: stats ? ( stats.default ? stats.default.name : __( 'None', 'giveflow-fundraising-campaigns' ) ) : '-',
        },
    ];
}

export default function List() {
    const [ view, setView ] = useState( {
        type:    'table',
        perPage: 25,
        page:    1,
        sort:    { field: 'sort_order', direction: 'asc' },
        filters: [],
        search:  '',
        fields:  [ 'name', 'type', 'raised', 'goal', 'status' ],
    } );

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

    const load = useCallback( () => {
        let aborted = false;
        setLoading( true );
        setError( null );

        apiFetch( {
            path: addQueryArgs( '/giveflow/v1/admin/funds', {
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
                setTestHidden( parseInt( res.headers.get( 'X-GiveFlow-Test-Hidden' ) || '0', 10 ) );
            } )
            .catch( ( err ) => {
                if ( aborted ) return;
                setError( err?.message || __( 'Failed to load funds.', 'giveflow-fundraising-campaigns' ) );
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
                    path: addQueryArgs( '/giveflow/v1/admin/funds', { page, per_page: 100, orderby: 'sort_order', order: 'asc' } ),
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
            .catch( ( err ) => window.console?.error( '[giveflow] fund picker list failed to load', err ) );
    }, [] );

    const loadStats = useCallback( () => {
        setStatsLoading( true );
        apiFetch( { path: '/giveflow/v1/admin/funds/stats' } )
            .then( setStats )
            .catch( ( err ) => {
                // Don't strand the KPI strip in a perpetual spinner; settling
                // loading resolves it to em-dashes instead.
                window.console?.error( '[giveflow] fund stats failed to load', err );
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
            await apiFetch( { path: `/giveflow/v1/admin/funds/${ id }`, method: 'POST', data: payload } );
            afterChange();
        } catch ( err ) {
            setError( err?.message || __( 'Action failed.', 'giveflow-fundraising-campaigns' ) );
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
            label:         __( 'Fund', 'giveflow-fundraising-campaigns' ),
            enableSorting: true,
            render: ( { item } ) => (
                <div
                    className={ item.__depth ? 'giveflow-fund-name giveflow-fund-name--child' : 'giveflow-fund-name' }
                    style={ item.__depth ? { paddingLeft: `${ item.__depth * 26 }px` } : undefined }
                >
                    <div style={ { lineHeight: 1.35 } }>
                        <button
                            type="button"
                            className="giveflow-row__link giveflow-row__link--strong"
                            onMouseDown={ stopRowSelect }
                            onClick={ ( e ) => { stopRowSelect( e ); setEditing( item ); } }
                        >
                            { item.name }
                        </button>
                        { item.is_default && (
                            <span className="giveflow-fund-badge giveflow-fund-badge--default">
                                { __( 'Default', 'giveflow-fundraising-campaigns' ) }
                            </span>
                        ) }
                        <div className="giveflow-fund-code">{ item.code }</div>
                    </div>
                </div>
            ),
        },
        {
            id:       'type',
            label:    __( 'Type', 'giveflow-fundraising-campaigns' ),
            render: ( { item } ) => (
                <span className={ 'giveflow-fund-badge ' + ( item.is_restricted
                    ? 'giveflow-fund-badge--restricted'
                    : 'giveflow-fund-badge--unrestricted' ) }>
                    { item.is_restricted ? __( 'Restricted', 'giveflow-fundraising-campaigns' ) : __( 'Unrestricted', 'giveflow-fundraising-campaigns' ) }
                </span>
            ),
        },
        {
            id:    'raised',
            label: __( 'Raised', 'giveflow-fundraising-campaigns' ),
            // Same reason the goal column is not sortable: a parent's raised is
            // rolled up in PHP after the query.
            enableSorting: false,
            render: ( { item } ) => (
                <span className="giveflow-fund-raised">{ formatAmount( item.raised_cents ) }</span>
            ),
        },
        {
            id:            'goal',
            label:         __( 'Goal progress', 'giveflow-fundraising-campaigns' ),
            // Not sortable: the column shows raised-vs-goal percentage, but a
            // parent's raised is rolled up in PHP after the query, so no DB
            // sort key reflects what's displayed.
            enableSorting: false,
            render: ( { item } ) => {
                if ( ! item.goal_cents ) {
                    return (
                        <GoalBar left={ __( 'No goal set', 'giveflow-fundraising-campaigns' ) } pct={ 0 } muted />
                    );
                }
                const pct = Math.min( 100, Math.round( ( item.raised_cents / item.goal_cents ) * 100 ) );
                return (
                    <GoalBar
                        left={ formatAmount( item.raised_cents ) }
                        right={ `${ __( 'of', 'giveflow-fundraising-campaigns' ) } ${ formatAmount( item.goal_cents ) }` }
                        pct={ pct }
                    />
                );
            },
        },
        {
            id:       'status',
            label:    __( 'Status', 'giveflow-fundraising-campaigns' ),
            elements: STATUS_OPTIONS,
            filterBy: { operators: [ 'is' ] },
            render: ( { item } ) => {
                if ( item.reassign_pending ) {
                    return (
                        <span className="giveflow-fund-badge giveflow-fund-badge--pending">
                            { __( 'Reassigning…', 'giveflow-fundraising-campaigns' ) }
                        </span>
                    );
                }
                return (
                    <span className="giveflow-fund-status">
                        <span className={ 'giveflow-fund-dot ' + ( fundIsOpen( item ) ? 'is-on' : 'is-off' ) } />
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
            label:      __( 'Edit', 'giveflow-fundraising-campaigns' ),
            icon:       () => <Pencil size={ 16 } strokeWidth={ 1.75 } />,
            isEligible: ( item ) => ! item.reassign_pending,
            callback:   ( [ item ] ) => setEditing( item ),
        },
        {
            id:         'set-default',
            label:      __( 'Set as default', 'giveflow-fundraising-campaigns' ),
            icon:       () => <Star size={ 16 } strokeWidth={ 1.75 } />,
            isEligible: ( item ) => ! item.is_default && item.is_active && ! item.reassign_pending,
            callback:   ( [ item ] ) => mutate( item.id, { is_default: true } ),
        },
        {
            id:         'deactivate',
            label:      __( 'Deactivate', 'giveflow-fundraising-campaigns' ),
            icon:       () => <PowerOff size={ 16 } strokeWidth={ 1.75 } />,
            isEligible: ( item ) => ! item.is_default && item.is_active && ! item.reassign_pending,
            callback:   ( [ item ] ) => mutate( item.id, { is_active: false } ),
        },
        {
            id:         'activate',
            label:      __( 'Activate', 'giveflow-fundraising-campaigns' ),
            icon:       () => <Power size={ 16 } strokeWidth={ 1.75 } />,
            isEligible: ( item ) => ! item.is_default && ! item.is_active && ! item.reassign_pending,
            callback:   ( [ item ] ) => mutate( item.id, { is_active: true } ),
        },
        {
            id:            'delete',
            label:         __( 'Delete', 'giveflow-fundraising-campaigns' ),
            icon:          () => <TrashIcon size={ 16 } strokeWidth={ 1.75 } />,
            isDestructive: true,
            isEligible:    ( item ) => ! item.is_default && ! item.reassign_pending,
            callback:      ( [ item ] ) => setDeleteTarget( item ),
        },
    ], [ mutate ] );

    return (
        <div>
            <div className="giveflow-crumbs">
                <a href={ dashboardHref( window.location.pathname ) }>{ __( 'GiveFlow', 'giveflow-fundraising-campaigns' ) }</a>
                <span className="sep">›</span>
                <span>{ __( 'Funds', 'giveflow-fundraising-campaigns' ) }</span>
            </div>
            <div className="giveflow-page-head">
                <div className="giveflow-page-head__title-row">
                    <h1>{ __( 'Funds', 'giveflow-fundraising-campaigns' ) }</h1>
                </div>
                <div className="giveflow-page-head__right">
                    <span className="giveflow-page-head__meta">
                        { sprintf( /* translators: %s: number of funds */ _n( '%s fund', '%s funds', total, 'giveflow-fundraising-campaigns' ), total.toLocaleString() ) }
                    </span>
                    <Btn variant="primary" onClick={ onCreate }>
                        <Plus size={ 16 } strokeWidth={ 1.75 } />
                        { __( 'New fund', 'giveflow-fundraising-campaigns' ) }
                    </Btn>
                </div>
            </div>

            <p className="giveflow-funds-intro">
                { __( 'Organization-wide designations donations are allocated to.', 'giveflow-fundraising-campaigns' ) }
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
                            'giveflow-fundraising-campaigns'
                        ),
                        testHidden
                    ) }
                </Notice>
            ) }


            { error && (
                <Notice status="error" onRemove={ () => setError( null ) }>{ error }</Notice>
            ) }

            { ! loading && total === 0 && ! view.search && ! statusFilter ? (
                <EmptyState
                    icon={ <Wallet size={ 22 } strokeWidth={ 1.75 } /> }
                    title={ __( 'No funds yet', 'giveflow-fundraising-campaigns' ) }
                    body={ __( 'Funds route donations to specific causes within your organization. Forms without a fund picker drop into the organization default.', 'giveflow-fundraising-campaigns' ) }
                    action={
                        <Btn variant="primary" onClick={ onCreate }>
                            { __( 'Create your first fund', 'giveflow-fundraising-campaigns' ) }
                        </Btn>
                    }
                />
            ) : (
                <div className="giveflow-dataviews">
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
                path:   fund.id ? `/giveflow/v1/admin/funds/${ fund.id }` : '/giveflow/v1/admin/funds',
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
            setSaveError( err?.message || __( 'Failed to save fund.', 'giveflow-fundraising-campaigns' ) );
            setSaving( false );
        }
    };

    const parents = ( allFunds || [] ).filter( ( f ) => f.id !== fund.id );

    return (
        <Dialog
            title={ fund.id
                ? __( 'Edit fund', 'giveflow-fundraising-campaigns' )
                : __( 'New fund', 'giveflow-fundraising-campaigns' ) }
            onClose={ onClose }
            foot={ (
                <>
                    <Btn onClick={ onClose }>{ __( 'Cancel', 'giveflow-fundraising-campaigns' ) }</Btn>
                    <Btn variant="primary" onClick={ save } isBusy={ saving } disabled={ saving }>
                        { fund.id
                            ? __( 'Save fund', 'giveflow-fundraising-campaigns' )
                            : __( 'Create fund', 'giveflow-fundraising-campaigns' ) }
                    </Btn>
                </>
            ) }
        >
            { saveError && (
                        <Notice status="error" onRemove={ () => setSaveError( null ) }>{ saveError }</Notice>
                    ) }
                    <fieldset className="giveflow-fset">
                        <legend>{ __( 'Identity', 'giveflow-fundraising-campaigns' ) }</legend>
                        <div className="giveflow-fld">
                            <label>{ __( 'Name', 'giveflow-fundraising-campaigns' ) }</label>
                            <input className="giveflow-input" value={ form.name } onChange={ ( e ) => set( 'name', e.target.value ) } />
                        </div>
                        <div className="giveflow-fld">
                            <label>{ __( 'Code', 'giveflow-fundraising-campaigns' ) }</label>
                            <input className="giveflow-input giveflow-input--mono" value={ form.code } onChange={ ( e ) => set( 'code', e.target.value ) } />
                            <p className="giveflow-fld__help">{ __( 'Stable identifier used in exports and accounting. Lowercase, no spaces. Avoid changing once donations exist.', 'giveflow-fundraising-campaigns' ) }</p>
                        </div>
                        <div className="giveflow-fld">
                            <label>{ __( 'Description', 'giveflow-fundraising-campaigns' ) }</label>
                            <textarea className="giveflow-textarea" rows="3" value={ form.description } onChange={ ( e ) => set( 'description', e.target.value ) } />
                        </div>
                    </fieldset>

                    <fieldset className="giveflow-fset">
                        <legend>{ __( 'Classification', 'giveflow-fundraising-campaigns' ) }</legend>
                        <div className="giveflow-fld">
                            <label>{ __( 'Type', 'giveflow-fundraising-campaigns' ) }</label>
                            <div className="giveflow-seg2">
                                <button type="button" className={ ! form.is_restricted ? 'is-active' : '' } onClick={ () => set( 'is_restricted', false ) }>
                                    { __( 'Unrestricted', 'giveflow-fundraising-campaigns' ) }
                                </button>
                                <button type="button" className={ form.is_restricted ? 'is-active' : '' } onClick={ () => set( 'is_restricted', true ) }>
                                    { __( 'Restricted', 'giveflow-fundraising-campaigns' ) }
                                </button>
                            </div>
                            <p className="giveflow-fld__help">{ __( 'Restricted funds are donor-designated and reported separately.', 'giveflow-fundraising-campaigns' ) }</p>
                        </div>
                        <div className="giveflow-fld">
                            <label>{ __( 'Parent fund', 'giveflow-fundraising-campaigns' ) }</label>
                            <SearchableSelect
                                value={ form.parent_fund_id ? String( form.parent_fund_id ) : '' }
                                onChange={ ( v ) => set( 'parent_fund_id', v ) }
                                placeholder={ __( 'None (top-level fund)', 'giveflow-fundraising-campaigns' ) }
                                options={ [
                                    { value: '', label: __( 'None (top-level fund)', 'giveflow-fundraising-campaigns' ) },
                                    ...parents.map( ( p ) => ( { value: String( p.id ), label: p.name } ) ),
                                ] }
                            />
                        </div>
                    </fieldset>

                    <fieldset className="giveflow-fset">
                        <legend>{ __( 'Targets', 'giveflow-fundraising-campaigns' ) }</legend>
                        <div className="giveflow-fld">
                            <label>{ __( 'Goal amount', 'giveflow-fundraising-campaigns' ) } <span className="giveflow-fld__opt">{ __( 'optional', 'giveflow-fundraising-campaigns' ) }</span></label>
                            <input className="giveflow-input" type="number" min="0" step="0.01" placeholder={ __( 'No goal', 'giveflow-fundraising-campaigns' ) } value={ form.goal } onChange={ ( e ) => set( 'goal', e.target.value ) } />
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

                    <fieldset className="giveflow-fset">
                        <legend>{ __( 'Accounting', 'giveflow-fundraising-campaigns' ) }</legend>
                        <div className="giveflow-fld">
                            <label>{ __( 'Accounting code', 'giveflow-fundraising-campaigns' ) } <span className="giveflow-fld__opt">{ __( 'optional', 'giveflow-fundraising-campaigns' ) }</span></label>
                            <input className="giveflow-input giveflow-input--mono" placeholder={ __( 'Enter accounting code', 'giveflow-fundraising-campaigns' ) } value={ form.accounting_code } onChange={ ( e ) => set( 'accounting_code', e.target.value ) } />
                            <p className="giveflow-fld__help">{ __( 'Maps this fund to a GL account in your bookkeeping. Included in exports.', 'giveflow-fundraising-campaigns' ) }</p>
                        </div>
                    </fieldset>

                    <fieldset className="giveflow-fset">
                        <legend>{ __( 'Behaviour', 'giveflow-fundraising-campaigns' ) }</legend>
                        <ToggleRow
                            title={ __( 'Default fund', 'giveflow-fundraising-campaigns' ) }
                            sub={ __( 'Donations with no chosen fund (and campaigns with no default) are allocated here.', 'giveflow-fundraising-campaigns' ) }
                            checked={ form.is_default }
                            onChange={ ( v ) => set( 'is_default', v ) }
                        />
                        <ToggleRow
                            title={ __( 'Active', 'giveflow-fundraising-campaigns' ) }
                            sub={ __( 'Inactive funds stay in reports but cannot receive new donations.', 'giveflow-fundraising-campaigns' ) }
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
                ? addQueryArgs( `/giveflow/v1/admin/funds/${ fund.id }`, { reassign_to: Number( targetId ) } )
                : `/giveflow/v1/admin/funds/${ fund.id }`;
            const res = await apiFetch( { path, method: 'DELETE' } );
            let msg;
            if ( res.action === 'deleted' ) {
                msg = sprintf( /* translators: %s: fund name */ __( 'Fund “%s” was deleted.', 'giveflow-fundraising-campaigns' ), fund.name );
            } else if ( res.action === 'reassign_queued' ) {
                msg = sprintf( /* translators: %s: fund name */ __( 'Reassigning donations from “%s”. It will be removed once complete.', 'giveflow-fundraising-campaigns' ), fund.name );
            } else {
                msg = sprintf(
                    /* translators: %s: fund name */
                    __( 'Fund “%s” was deactivated and kept for reporting.', 'giveflow-fundraising-campaigns' ),
                    fund.name
                );
            }
            onDone( msg );
        } catch ( err ) {
            onError( err?.message || __( 'Could not delete the fund.', 'giveflow-fundraising-campaigns' ) );
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
        ? __( 'Delete fund', 'giveflow-fundraising-campaigns' )
        : ( choice === 'reassign' ? __( 'Reassign and delete', 'giveflow-fundraising-campaigns' ) : __( 'Deactivate fund', 'giveflow-fundraising-campaigns' ) );

    return (
        <Dialog
            title={ sprintf( /* translators: %s: fund name */ __( 'Delete fund: %s', 'giveflow-fundraising-campaigns' ), fund.name ) }
            onClose={ onClose }
            foot={ (
                <>
                    <Btn onClick={ onClose } disabled={ busy }>{ __( 'Cancel', 'giveflow-fundraising-campaigns' ) }</Btn>
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
                <p className="giveflow-dialog__help">
                    { __( 'Nothing points to this fund, so deleting it removes it entirely.', 'giveflow-fundraising-campaigns' ) }
                </p>
            ) : (
                <>
                    <p className="giveflow-dialog__help">
                        { __( 'This fund is still referenced, so it is never hard-deleted. Choose what to do:', 'giveflow-fundraising-campaigns' ) }
                    </p>

                    <label className="giveflow-choice" htmlFor="giveflow-fund-delete-deactivate">
                        <input
                            id="giveflow-fund-delete-deactivate"
                            type="radio"
                            name="giveflow-fund-delete"
                            checked={ choice === 'deactivate' }
                            onChange={ () => setChoice( 'deactivate' ) }
                        />
                        <span>
                            <strong>{ __( 'Deactivate', 'giveflow-fundraising-campaigns' ) }</strong>
                            <span>{ __( 'Keep all donation records. Recommended.', 'giveflow-fundraising-campaigns' ) }</span>
                        </span>
                    </label>

                    { /* Offering this with nothing to reassign to left the
                         author on an empty picker and a permanently disabled
                         button, with nothing saying Deactivate was the only
                         route. A site with one fund is the common shape here,
                         since a fund only reaches this dialog once it has
                         donations. */ }
                    { candidates.length > 0 ? (
                        <label className="giveflow-choice" htmlFor="giveflow-fund-delete-reassign">
                            <input
                                id="giveflow-fund-delete-reassign"
                                type="radio"
                                name="giveflow-fund-delete"
                                checked={ choice === 'reassign' }
                                onChange={ () => setChoice( 'reassign' ) }
                            />
                            <span>
                                <strong>{ __( 'Reassign to another fund, then delete', 'giveflow-fundraising-campaigns' ) }</strong>
                                <span>{ __( 'Moves every donation, campaign and form that points here onto the chosen fund, then removes this one.', 'giveflow-fundraising-campaigns' ) }</span>
                            </span>
                        </label>
                    ) : (
                        <p className="giveflow-dialog__help">
                            { __( 'There is no other active fund to reassign to, so deactivating is the only option here. Create another fund first if you want to move these donations.', 'giveflow-fundraising-campaigns' ) }
                        </p>
                    ) }

                    { choice === 'reassign' && candidates.length > 0 && (
                        <div className="giveflow-fld" style={ { marginTop: 12 } }>
                            <label className="giveflow-fld__label">{ __( 'Reassign donations to', 'giveflow-fundraising-campaigns' ) }</label>
                            <SearchableSelect
                                value={ targetId }
                                onChange={ ( v ) => setTargetId( v ) }
                                placeholder={ __( 'Select a fund', 'giveflow-fundraising-campaigns' ) }
                                options={ candidates.map( ( f ) => ( { value: String( f.id ), label: f.name } ) ) }
                            />
                        </div>
                    ) }
                </>
            ) }
        </Dialog>
    );
}
