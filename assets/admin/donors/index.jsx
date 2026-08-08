// Email is encrypted at rest, so client-side substring search on it is not
// possible: the REST endpoint resolves search via name substring plus exact
// email-hash lookup.

import { createRoot, useState, useEffect, useMemo, useCallback } from '@wordpress/element';
import { DataViews } from '@wordpress/dataviews';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __, _n, sprintf } from '@wordpress/i18n';
import { UserX as RedactIcon, Users as UsersIcon, Trash2 as DeleteIcon } from 'lucide-react';
import Notice from '../_shared/components/Notice';
import Toaster from '../_shared/components/Toaster';

import EmptyState from '../_shared/components/EmptyState';
import ConfirmDialog from '../_shared/components/ConfirmDialog';
import { rowLinkProps } from '../_shared/rowLink';
import { tablistKeyDown } from '../_shared/tablistKeys';
import KpiStrip from '../_shared/components/KpiStrip';
import { formatAmount, formatDate, timeAgo } from '../_shared/format';
import { COUNTRIES } from '../../_shared/countries';
import Insights from './Insights';
import DonorProfile from './DonorProfile';
import './donors.scss';
import '../campaigns/campaigns.scss';

function initials( name ) {
    if ( ! name ) return '?';
    const parts = String( name ).trim().split( /\s+/ ).slice( 0, 2 );
    return parts.map( ( p ) => p[ 0 ] || '' ).join( '' ).toUpperCase() || '?';
}

function donorKpis( stats ) {
    return [
        {
            label: __( 'Total donors', 'dono' ),
            value: stats ? stats.total_count.toLocaleString() : '-',
        },
        {
            label: __( 'With donations', 'dono' ),
            value: stats ? stats.with_donations.toLocaleString() : '-',
        },
        {
            label: __( 'Lifetime given', 'dono' ),
            value: stats && stats.total_donated_cents > 0
                ? formatAmount( stats.total_donated_cents )
                : '-',
        },
        {
            label: __( 'Avg lifetime value', 'dono' ),
            value: stats && stats.avg_ltv_cents > 0
                ? formatAmount( stats.avg_ltv_cents )
                : '-',
        },
    ];
}

function DonorsApp( { toggleSlot } ) {
    const [ view, setView ] = useState( {
        type:    'table',
        perPage: 25,
        page:    1,
        sort:    { field: 'last_donation_at', direction: 'desc' },
        filters: [],
        search:  '',
        fields:  [ 'name', 'email', 'country', 'donations_count', 'total_donated', 'last_donation_at' ],
    } );

    const [ data, setData ]       = useState( [] );
    const [ total, setTotal ]     = useState( 0 );
    const [ loading, setLoading ] = useState( false );
    const [ error, setError ]     = useState( null );
    const [ stats, setStats ]     = useState( null );
    const [ confirm, setConfirm ] = useState( null );

    const filterValue = ( field ) => view.filters?.find( ( f ) => f.field === field )?.value;

    const load = useCallback( () => {
        let aborted = false;
        setLoading( true );
        setError( null );

        apiFetch( {
            path: addQueryArgs( '/dono/v1/admin/donors', {
                page:       view.page,
                per_page:   view.perPage,
                orderby:    view.sort?.field === 'total_donated'
                    ? 'total_donated_cents'
                    : view.sort?.field || 'last_donation_at',
                order:      view.sort?.direction || 'desc',
                search:     view.search || undefined,
                country:    filterValue( 'country' )    || undefined,
                donor_type: filterValue( 'donor_type' ) || undefined,
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
                setData( [] );
                setTotal( 0 );
                setError( err?.message || __( 'Failed to load donors.', 'dono' ) );
            } )
            .finally( () => ! aborted && setLoading( false ) );

        apiFetch( {
            path: addQueryArgs( '/dono/v1/admin/donors/stats', {
                search:     view.search || undefined,
                country:    filterValue( 'country' )    || undefined,
                donor_type: filterValue( 'donor_type' ) || undefined,
            } ),
        } )
            .then( ( res ) => { if ( ! aborted ) setStats( res || null ); } )
            .catch( () => { if ( ! aborted ) setStats( null ); } );

        return () => {
            aborted = true;
        };
    }, [ view ] );

    useEffect( () => load(), [ load ] );

    const fields = useMemo( () => [
        {
            id:    'name',
            label: __( 'Name', 'dono' ),
            render: ( { item } ) => {
                const name = item.name || __( '(no name)', 'dono' );
                return (
                    <div className="dono-row">
                        <span className="dono-row__avatar" aria-hidden="true">
                            { initials( name ) }
                            { item.avatar_url && (
                                <img className="dono-row__avatar-photo" src={ item.avatar_url } alt="" loading="lazy" decoding="async" />
                            ) }
                        </span>
                        <div className="dono-row__body">
                            <a className="dono-row__link dono-row__link--strong" href={ `#donor/${ item.id }` } { ...rowLinkProps }>
                                { name }
                            </a>
                            { item.donor_type && item.donor_type !== 'individual' && (
                                <div className="dono-row__sub" style={ { textTransform: 'capitalize' } }>
                                    { item.donor_type }
                                </div>
                            ) }
                        </div>
                    </div>
                );
            },
        },
        {
            id:    'email',
            label: __( 'Email', 'dono' ),
            render: ( { item } ) => (
                item.email
                    ? <span className="dono-mono">{ item.email }</span>
                    : <span className="dono-row__sub">-</span>
            ),
        },
        {
            id:    'country',
            label: __( 'Country', 'dono' ),
            elements: COUNTRIES.map( ( c ) => ( { value: c.code, label: `${ c.code } - ${ c.name }` } ) ),
            filterBy: { operators: [ 'is' ] },
            render: ( { item } ) => (
                item.country
                    ? (
                        <span className="dono-country">
                            <span className="dono-country__code">{ item.country }</span>
                        </span>
                    )
                    : <span className="dono-row__sub">-</span>
            ),
        },
        {
            id:    'donor_type',
            label: __( 'Donor type', 'dono' ),
            elements: [
                { value: 'individual',   label: __( 'Individual', 'dono' ) },
                { value: 'organization', label: __( 'Organisation', 'dono' ) },
                { value: 'household',    label: __( 'Household', 'dono' ) },
            ],
            filterBy: { operators: [ 'is' ] },
            getValue: ( { item } ) => item.donor_type || 'individual',
            render:   ( { item } ) => (
                <span className="dono-row__sub" style={ { textTransform: 'capitalize' } }>
                    { item.donor_type || 'individual' }
                </span>
            ),
        },
        {
            id:            'donations_count',
            label:         __( '#', 'dono' ),
            enableSorting: true,
            render: ( { item } ) => (
                <span className="dono-amount dono-amount--num">{ item.donations_count }</span>
            ),
        },
        {
            id:            'total_donated',
            label:         __( 'Total', 'dono' ),
            enableSorting: true,
            render: ( { item } ) => (
                <span className="dono-amount">
                    { formatAmount( item.total_donated_cents ) }
                </span>
            ),
        },
        {
            id:            'last_donation_at',
            label:         __( 'Last donation', 'dono' ),
            enableSorting: true,
            render: ( { item } ) => (
                item.last_donation_at
                    ? (
                        <span className="dono-time" title={ formatDate( item.last_donation_at ) }>
                            <span className="dono-time__rel">{ timeAgo( item.last_donation_at ) }</span>
                            <span className="dono-time__abs">{ formatDate( item.last_donation_at ) }</span>
                        </span>
                    )
                    : <span className="dono-row__sub">-</span>
            ),
        },
    ], [] );

    const paginationInfo = useMemo(
        () => ( {
            totalItems: total,
            totalPages: Math.max( 1, Math.ceil( total / view.perPage ) ),
        } ),
        [ total, view.perPage ]
    );

    const actions = useMemo( () => [
        {
            id:            'delete',
            label:         __( 'Delete', 'dono' ),
            icon:          () => <DeleteIcon size={ 16 } strokeWidth={ 1.75 } />,
            isDestructive: true,
            supportsBulk:  true,
            // A donor who gave has a financial record attached and is redacted
            // instead. The server refuses either way; this keeps the menu from
            // offering something that can only fail.
            isEligible:    ( item ) => ! item.donations_count,
            callback: ( items ) => {
                if ( ! items.length ) return;
                const n = items.length;
                setConfirm( {
                    title:        _n( 'Delete donor', 'Delete donors', n, 'dono' ),
                    message: n === 1
                        ? __( 'Delete this donor? They have no donations, so nothing is kept: the record and anything describing it go for good.', 'dono' )
                        : sprintf(
                            /* translators: %d: number of donors to delete */
                            _n(
                                'Delete %d donor? They have no donations, so nothing is kept.',
                                'Delete %d donors? They have no donations, so nothing is kept.',
                                n,
                                'dono'
                            ),
                            n
                        ),
                    confirmLabel: __( 'Delete', 'dono' ),
                    destructive:  true,
                    onConfirm: async () => {
                        try {
                            await Promise.all( items.map( ( i ) => apiFetch( {
                                path:   `/dono/v1/admin/donors/${ i.id }`,
                                method: 'DELETE',
                            } ) ) );
                        } catch ( err ) {
                            setError( err?.message || __( 'Could not delete one or more donors.', 'dono' ) );
                        } finally {
                            load();
                        }
                    },
                } );
            },
        },
        {
            id:            'redact',
            label:         __( 'Redact (anonymize)', 'dono' ),
            icon:          () => <RedactIcon size={ 16 } strokeWidth={ 1.75 } />,
            isDestructive: true,
            supportsBulk:  true,
            isEligible:    ( item ) => ! item.redacted,
            callback: ( items ) => {
                if ( ! items.length ) return;
                const n = items.length;
                const message = n === 1
                    ? __( 'Redact this donor? Their PII (name, email, address, phone) is wiped from the donor row but their donations stay attached and counted. This cannot be undone.', 'dono' )
                    : sprintf(
                        /* translators: %d: number of donors to redact */
                        _n(
                            'Redact %d donor? Their PII is wiped from the donor rows but donations stay attached and counted. This cannot be undone.',
                            'Redact %d donors? Their PII is wiped from the donor rows but donations stay attached and counted. This cannot be undone.',
                            n,
                            'dono'
                        ),
                        n
                    );
                setConfirm( {
                    title:        _n( 'Redact donor', 'Redact donors', n, 'dono' ),
                    message,
                    confirmLabel: __( 'Redact', 'dono' ),
                    destructive:  true,
                    // The callback fills the server's confirmation from each
                    // row, so nothing else stands between one click and erased
                    // PII here.
                    requireText:  __( 'REDACT', 'dono' ),
                    onConfirm: async () => {
                        try {
                            await Promise.all( items.map( ( i ) => apiFetch( {
                                path:   `/dono/v1/admin/donors/${ i.id }/redact`,
                                method: 'POST',
                                data:   { confirmation: i.email || `DONOR_${ i.id }` },
                            } ) ) );
                        } catch ( err ) {
                            setError( err?.message || __( 'Could not redact one or more donors.', 'dono' ) );
                        } finally {
                            load();
                        }
                    },
                } );
            },
        },
    ], [ load ] );

    return (
        <div>
            <div className="dono-crumbs">
                <a href={ addQueryArgs( window.location.pathname, { page: 'dono' } ) }>{ __( 'Dono', 'dono' ) }</a>
                <span className="sep">›</span>
                <span>{ __( 'Donors', 'dono' ) }</span>
            </div>
            <div className="dono-page-head">
                <div className="dono-page-head__title-row">
                    <h1>{ __( 'Donors', 'dono' ) }</h1>
                </div>
                <div className="dono-page-head__right">
                    <span className="dono-page-head__meta">
                        { sprintf( /* translators: %s: number of donors */ _n( '%s donor', '%s donors', total, 'dono' ), total.toLocaleString() ) }
                    </span>
                    { toggleSlot }
                </div>
            </div>

            { error && (
                <Notice status="error" onRemove={ () => setError( null ) }>{ error }</Notice>
            ) }

            <KpiStrip items={ donorKpis( stats ) } loading={ loading && ! stats } />

            { ! loading && ! error && total === 0 && ! view.search && ! view.filters?.length ? (
                <EmptyState
                    icon={ <UsersIcon size={ 22 } strokeWidth={ 1.75 } /> }
                    title={ __( 'No donors yet', 'dono' ) }
                    body={ __( 'Donor records are created automatically from completed donations. Publish a form to start collecting them.', 'dono' ) }
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

            <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
        </div>
    );
}

function routeFromHash() {
    const h = window.location.hash;
    const donorMatch = h.match( /^#donor\/(\d+)$/ );
    if ( donorMatch ) return { kind: 'donor', id: Number( donorMatch[ 1 ] ) };
    if ( h === '#insights' ) return { kind: 'insights' };
    return { kind: 'list' };
}

function IconList() {
    return (
        <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">
            <path d="M2 3h12v2H2zM2 7h12v2H2zM2 11h12v2H2z" fill="currentColor" />
        </svg>
    );
}

function IconInsights() {
    return (
        <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">
            <path d="M3 13V6h2v7zM7 13V3h2v10zM11 13V8h2v5z" fill="currentColor" />
        </svg>
    );
}

function ViewToggle( { active, onChange } ) {
    return (
        <div
            className="dono-view-toggle"
            role="tablist"
            tabIndex={ -1 }
            aria-label={ __( 'Donor sections', 'dono' ) }
            onKeyDown={ ( e ) => tablistKeyDown( e, [ 'list', 'insights' ], active, onChange ) }
        >
            <button
                type="button"
                role="tab"
                aria-selected={ active === 'list' }
                tabIndex={ active === 'list' ? 0 : -1 }
                className={ `dono-cmp-toggle${ active === 'list' ? ' is-active' : '' }` }
                onClick={ () => onChange( 'list' ) }
            >
                <IconList />
                { __( 'List', 'dono' ) }
            </button>
            <button
                type="button"
                role="tab"
                aria-selected={ active === 'insights' }
                tabIndex={ active === 'insights' ? 0 : -1 }
                className={ `dono-cmp-toggle${ active === 'insights' ? ' is-active' : '' }` }
                onClick={ () => onChange( 'insights' ) }
            >
                <IconInsights />
                { __( 'Insights', 'dono' ) }
            </button>
        </div>
    );
}

function DonorsRoot() {
    const [ route, setRoute ] = useState( routeFromHash );

    useEffect( () => {
        const onHash = () => setRoute( routeFromHash() );
        window.addEventListener( 'hashchange', onHash );
        return () => window.removeEventListener( 'hashchange', onHash );
    }, [] );

    const goto = ( hash ) => {
        window.location.hash = hash;
    };

    const showToggle = route.kind !== 'donor';
    const activeTab = route.kind === 'insights' ? 'insights' : 'list';
    const toggle = showToggle ? (
        <ViewToggle
            active={ activeTab }
            onChange={ ( next ) => goto( next === 'insights' ? '#insights' : '' ) }
        />
    ) : null;

    return (
        <div>
            { route.kind === 'donor'    && <DonorProfile id={ route.id } onBack={ () => goto( '' ) } /> }
            { route.kind === 'insights' && <Insights toggleSlot={ toggle } /> }
            { route.kind === 'list'     && <DonorsApp toggleSlot={ toggle } /> }
        </div>
    );
}

document.addEventListener( 'DOMContentLoaded', () => {
    const root = document.getElementById( 'dono-admin-donors' );
    if ( ! root ) return;
    createRoot( root ).render( <><DonorsRoot /><Toaster /></> );
} );
