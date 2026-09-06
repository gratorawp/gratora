// Email is encrypted at rest, so client-side substring search on it is not
// possible: the REST endpoint resolves search via name substring plus exact
// email-hash lookup.

import { createRoot, useState, useEffect, useMemo, useCallback } from '@wordpress/element';
import { DataViews } from '@wordpress/dataviews';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __, _n, sprintf } from '@wordpress/i18n';
import { UserX as RedactIcon, Users as UsersIcon, Trash2 as DeleteIcon, SearchX } from 'lucide-react';
import Notice from '../_shared/components/Notice';
import { useTableView } from '../_shared/useTableView';
import Toaster from '../_shared/components/Toaster';
import notify from '../_shared/notify';

import Btn from '../_shared/components/Btn';
import EmptyState from '../_shared/components/EmptyState';
import { isViewFiltered, clearedView } from '../_shared/viewFilters';
import ConfirmDialog from '../_shared/components/ConfirmDialog';
import { rowLinkProps } from '../_shared/rowLink';
import { dashboardHref } from '../_shared/adminPages';
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

export function donorKpis( stats ) {
    return [
        {
            label: __( 'Total donors', 'fundraising-toolkit' ),
            value: stats ? String( stats.total_count ) : '-',
        },
        {
            label: __( 'With donations', 'fundraising-toolkit' ),
            value: stats ? String( stats.with_donations ) : '-',
        },
        {
            label: __( 'Lifetime given', 'fundraising-toolkit' ),
            value: stats && stats.total_donated_cents > 0
                ? formatAmount( stats.total_donated_cents )
                : '-',
        },
        {
            label: __( 'Avg lifetime value', 'fundraising-toolkit' ),
            value: stats && stats.avg_ltv_cents > 0
                ? formatAmount( stats.avg_ltv_cents )
                : '-',
        },
    ];
}

/**
 * What a bulk action did, all of it. Reporting only the first failure left an
 * admin unable to tell which donors had been processed.
 */
function report( results, done, failed ) {
    const ok = results.filter( ( r ) => r.status === 'fulfilled' ).length;
    const no = results.length - ok;

    if ( ok > 0 ) notify.success( done( ok ) );
    if ( no > 0 ) notify.error( failed( no ) );
}

export function DonorsApp( { toggleSlot } ) {
    const [ view, setView, viewReady ] = useTableView( 'donors', {
        type:    'table',
        perPage: 25,
        page:    1,
        sort:    { field: 'last_donation_at', direction: 'desc' },
        filters: [],
        search:  '',
        fields:  [ 'id', 'name', 'email', 'country', 'donations_count', 'total_donated', 'last_donation_at' ],
        // The table reads column widths from here, not from the field, and
        // without them the first column is treated as the primary one and takes
        // room from the name. The name is what the screen is for.
        layout: {
            styles: {
                id:        { width: '80px' },
                name:      { width: '32%', minWidth: '260px' },
                email:     { maxWidth: '230px' },
            },
        },
    }, () => fields.map( ( f ) => f.id ) );

    // Which empty this screen shows depends on it. See _shared/viewFilters.
    const filtered = isViewFiltered( view );
    const clearFilters = () => setView( clearedView( view ) );

    const [ data, setData ]       = useState( [] );
    const [ total, setTotal ]     = useState( 0 );
    const [ loading, setLoading ] = useState( true );
    const [ error, setError ]     = useState( null );
    const [ stats, setStats ]     = useState( null );
    const [ confirm, setConfirm ] = useState( null );

    const filterValue = ( field ) => view.filters?.find( ( f ) => f.field === field )?.value;

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
            path: addQueryArgs( '/fundkit/v1/admin/donors', {
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
                setError( err?.message || __( 'Failed to load donors.', 'fundraising-toolkit' ) );
            } )
            .finally( () => ! aborted && setLoading( false ) );

        apiFetch( {
            path: addQueryArgs( '/fundkit/v1/admin/donors/stats', {
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
    }, [ view, viewReady ] );

    useEffect( () => load(), [ load ] );

    const fields = useMemo( () => [
        {
            id:    'id',
            label: __( 'ID', 'fundraising-toolkit' ),
            render: ( { item } ) => (
                <span className="fundkit-ref-cell">
                    <a className="fundkit-mono-link" href={ `#donor/${ item.id }` } { ...rowLinkProps }>
                        { item.id }
                    </a>
                </span>
            ),
        },
        {
            id:    'name',
            label: __( 'Name', 'fundraising-toolkit' ),
            render: ( { item } ) => {
                const name = item.name || __( '(no name)', 'fundraising-toolkit' );
                return (
                    <div className="fundkit-row">
                        { ! item.redacted && (
                            <span className="fundkit-row__avatar" aria-hidden="true">
                                { initials( name ) }
                                { item.avatar_url && (
                                    <img className="fundkit-row__avatar-photo" src={ item.avatar_url } alt="" loading="lazy" decoding="async" />
                                ) }
                            </span>
                        ) }
                        <div className="fundkit-row__body">
                            <span className="fundkit-ref-cell">
                                <a className="fundkit-row__link fundkit-row__link--strong" href={ `#donor/${ item.id }` } { ...rowLinkProps }>
                                    { name }
                                </a>
                                { item.is_test_only && (
                                    <span className="fundkit-pill fundkit-pill--test">{ __( 'Test', 'fundraising-toolkit' ) }</span>
                                ) }
                            </span>
                            { item.donor_type && item.donor_type !== 'individual' && (
                                <div className="fundkit-row__sub" style={ { textTransform: 'capitalize' } }>
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
            label: __( 'Email', 'fundraising-toolkit' ),
            render: ( { item } ) => (
                item.email
                    ? <span className="fundkit-mono">{ item.email }</span>
                    : <span className="fundkit-row__sub">-</span>
            ),
        },
        {
            id:    'country',
            label: __( 'Country', 'fundraising-toolkit' ),
            elements: COUNTRIES.map( ( c ) => ( { value: c.code, label: `${ c.code } - ${ c.name }` } ) ),
            filterBy: { operators: [ 'is' ] },
            render: ( { item } ) => (
                item.country
                    ? (
                        <span className="fundkit-country">
                            <span className="fundkit-country__code">{ item.country }</span>
                        </span>
                    )
                    : <span className="fundkit-row__sub">-</span>
            ),
        },
        {
            id:    'donor_type',
            label: __( 'Donor type', 'fundraising-toolkit' ),
            elements: [
                { value: 'individual',   label: __( 'Individual', 'fundraising-toolkit' ) },
                { value: 'organization', label: __( 'Organization', 'fundraising-toolkit' ) },
                { value: 'household',    label: __( 'Household', 'fundraising-toolkit' ) },
            ],
            filterBy: { operators: [ 'is' ] },
            getValue: ( { item } ) => item.donor_type || 'individual',
            render:   ( { item } ) => (
                <span className="fundkit-row__sub" style={ { textTransform: 'capitalize' } }>
                    { item.donor_type || 'individual' }
                </span>
            ),
        },
        {
            id:            'donations_count',
            label:         __( 'Donations', 'fundraising-toolkit' ),
            enableSorting: true,
            render: ( { item } ) => (
                <span className="fundkit-amount fundkit-amount--num">{ item.donations_count }</span>
            ),
        },
        {
            id:            'total_donated',
            label:         __( 'Total donated', 'fundraising-toolkit' ),
            enableSorting: true,
            render: ( { item } ) => (
                <span className="fundkit-amount">
                    { formatAmount( item.total_donated_cents ) }
                </span>
            ),
        },
        {
            id:            'last_donation_at',
            label:         __( 'Last donation', 'fundraising-toolkit' ),
            enableSorting: true,
            render: ( { item } ) => (
                item.last_donation_at
                    ? (
                        <span className="fundkit-time" title={ formatDate( item.last_donation_at ) }>
                            <span className="fundkit-time__rel">{ timeAgo( item.last_donation_at ) }</span>
                            <span className="fundkit-time__abs">{ formatDate( item.last_donation_at ) }</span>
                        </span>
                    )
                    : <span className="fundkit-row__sub">-</span>
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
            label:         __( 'Delete', 'fundraising-toolkit' ),
            icon:          () => <DeleteIcon size={ 16 } strokeWidth={ 1.75 } />,
            isDestructive: true,
            supportsBulk:  true,
            // A donor with any donation row has a financial record attached and
            // is redacted instead. The row carries the delete gate's own
            // answer: the counters here are live and paid only, so they say yes
            // to a donor whose only donation is a rehearsal, a refund or an
            // attempt that never completed, and the server would 409.
            isEligible:    ( item ) => !! item.deletable,
            // DataViews hands a bulk callback the whole selection, not the
            // eligible subset, so isEligible only decides whether the button is
            // drawn. Without re-filtering, the count in the sentence is wrong
            // and the requests reach donors the gate exists to exclude.
            callback: ( selection ) => {
                const items = selection.filter( ( i ) => !! i.deletable );
                if ( ! items.length ) return;
                const n = items.length;
                setConfirm( {
                    title:        _n( 'Delete donor', 'Delete donors', n, 'fundraising-toolkit' ),
                    message: n === 1
                        ? __( 'Delete this donor? They have no donations, so nothing is kept: the record and anything describing it go for good.', 'fundraising-toolkit' )
                        : sprintf(
                            /* translators: %d: number of donors to delete */
                            _n(
                                'Delete %d donor? They have no donations, so nothing is kept.',
                                'Delete %d donors? They have no donations, so nothing is kept.',
                                n,
                                'fundraising-toolkit'
                            ),
                            n
                        ),
                    confirmLabel: __( 'Delete', 'fundraising-toolkit' ),
                    destructive:  true,
                    onConfirm: async () => {
                        // allSettled, not all: the first rejection abandoned
                        // the rest of the reporting, so a part-done batch
                        // showed nothing at all.
                        const results = await Promise.allSettled( items.map( ( i ) => apiFetch( {
                            path:   `/fundkit/v1/admin/donors/${ i.id }`,
                            method: 'DELETE',
                        } ) ) );

                        report(
                            results,
                            ( count ) => sprintf(
                                /* translators: %d: how many donors were deleted. */
                                _n( '%d donor deleted.', '%d donors deleted.', count, 'fundraising-toolkit' ),
                                count
                            ),
                            ( count ) => sprintf(
                                /* translators: %d: how many donors could not be deleted. */
                                _n( '%d donor could not be deleted.', '%d donors could not be deleted.', count, 'fundraising-toolkit' ),
                                count
                            )
                        );
                        load();
                    },
                } );
            },
        },
        {
            id:            'redact',
            label:         __( 'Redact (anonymize)', 'fundraising-toolkit' ),
            icon:          () => <RedactIcon size={ 16 } strokeWidth={ 1.75 } />,
            isDestructive: true,
            supportsBulk:  true,
            isEligible:    ( item ) => ! item.redacted,
            callback: ( selection ) => {
                const items = selection.filter( ( i ) => ! i.redacted );
                if ( ! items.length ) return;
                const n = items.length;
                const message = n === 1
                    ? __( 'Redact this donor? Their PII (name, email, address, phone) is wiped from the donor row and any active recurring plan is cancelled at the gateway, but their donations stay attached and counted. This cannot be undone.', 'fundraising-toolkit' )
                    : sprintf(
                        /* translators: %d: number of donors to redact */
                        _n(
                            'Redact %d donor? Their PII is wiped from the donor rows and any active recurring plan is cancelled at the gateway, but donations stay attached and counted. This cannot be undone.',
                            'Redact %d donors? Their PII is wiped from the donor rows and any active recurring plan is cancelled at the gateway, but donations stay attached and counted. This cannot be undone.',
                            n,
                            'fundraising-toolkit'
                        ),
                        n
                    );
                setConfirm( {
                    title:        _n( 'Redact donor', 'Redact donors', n, 'fundraising-toolkit' ),
                    message,
                    confirmLabel: __( 'Redact', 'fundraising-toolkit' ),
                    destructive:  true,
                    // The callback fills the server's confirmation from each
                    // row, so nothing else stands between one click and erased
                    // PII here.
                    requireText:  __( 'REDACT', 'fundraising-toolkit' ),
                    onConfirm: async () => {
                        const results = await Promise.allSettled( items.map( ( i ) => apiFetch( {
                            path:   `/fundkit/v1/admin/donors/${ i.id }/redact`,
                            method: 'POST',
                            data:   { confirmation: i.email || `DONOR_${ i.id }` },
                        } ) ) );

                        report(
                            results,
                            ( count ) => sprintf(
                                /* translators: %d: how many donors were redacted. */
                                _n( '%d donor redacted.', '%d donors redacted.', count, 'fundraising-toolkit' ),
                                count
                            ),
                            ( count ) => sprintf(
                                /* translators: %d: how many donors could not be redacted. */
                                _n( '%d donor could not be redacted.', '%d donors could not be redacted.', count, 'fundraising-toolkit' ),
                                count
                            )
                        );
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
                <span>{ __( 'Donors', 'fundraising-toolkit' ) }</span>
            </div>
            <div className="fundkit-page-head">
                <div className="fundkit-page-head__title-row">
                    <h1>{ __( 'Donors', 'fundraising-toolkit' ) }</h1>
                </div>
                <div className="fundkit-page-head__right">
                    <span className="fundkit-page-head__meta">
                        { sprintf( /* translators: %s: number of donors */ _n( '%s donor', '%s donors', total, 'fundraising-toolkit' ), total.toLocaleString() ) }
                    </span>
                    { toggleSlot }
                </div>
            </div>

            { error && (
                <Notice status="error" onRemove={ () => setError( null ) }>{ error }</Notice>
            ) }

            <KpiStrip items={ donorKpis( stats ) } loading={ loading && ! stats } />

            { ! loading && ! error && total === 0 && ! filtered ? (
                <EmptyState
                    icon={ <UsersIcon size={ 22 } strokeWidth={ 1.75 } /> }
                    title={ __( 'No donors yet', 'fundraising-toolkit' ) }
                    body={ __( 'Anyone who donates is added here. Publish a form to take the first one.', 'fundraising-toolkit' ) }
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
            className="fundkit-view-toggle"
            role="tablist"
            tabIndex={ -1 }
            aria-label={ __( 'Donor sections', 'fundraising-toolkit' ) }
            onKeyDown={ ( e ) => tablistKeyDown( e, [ 'list', 'insights' ], active, onChange ) }
        >
            <button
                type="button"
                role="tab"
                aria-selected={ active === 'list' }
                tabIndex={ active === 'list' ? 0 : -1 }
                className={ `fundkit-cmp-toggle${ active === 'list' ? ' is-active' : '' }` }
                onClick={ () => onChange( 'list' ) }
            >
                <IconList />
                { __( 'List', 'fundraising-toolkit' ) }
            </button>
            <button
                type="button"
                role="tab"
                aria-selected={ active === 'insights' }
                tabIndex={ active === 'insights' ? 0 : -1 }
                className={ `fundkit-cmp-toggle${ active === 'insights' ? ' is-active' : '' }` }
                onClick={ () => onChange( 'insights' ) }
            >
                <IconInsights />
                { __( 'Insights', 'fundraising-toolkit' ) }
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
    const root = document.getElementById( 'fundkit-admin-donors' );
    if ( ! root ) return;
    createRoot( root ).render( <><DonorsRoot /><Toaster /></> );
} );
