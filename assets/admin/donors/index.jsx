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
import { localizedCountries } from '../../_shared/countries';
import Insights from './Insights';
import DonorProfile from './DonorProfile';
import { userCan } from '../_shared/caps';
import './donors.scss';
// Restores the template picker, the KPI strip and the shared field styles,
// which live in that file rather than in a shared partial.
import '../campaigns/campaigns.scss';

/**
 * What to ask the server to order by.
 *
 * A saved view outlives the column it names. One holding a field nothing can
 * sort was sent all the same, and the server quietly ordered by something
 * else, so the table drew an arrow over an order it was not in. Anything not
 * on this list goes back to the default rather than travelling as a request
 * that cannot be honoured.
 */
const SORT_COLUMNS = {
    name:             'name',
    donations_count:  'donations_count',
    total_donated:    'total_donated_cents',
    last_donation_at: 'last_donation_at',
    created_at:       'created_at',
};

export function sortField( field ) {
    return SORT_COLUMNS[ field ] || 'last_donation_at';
}

function initials( name ) {
    if ( ! name ) return '?';
    const parts = String( name ).trim().split( /\s+/ ).slice( 0, 2 );
    return parts.map( ( p ) => p[ 0 ] || '' ).join( '' ).toUpperCase() || '?';
}

export function donorKpis( stats ) {
    return [
        {
            label: __( 'Total donors', 'gratora-donation-platform' ),
            value: stats ? String( stats.total_count ) : '-',
        },
        {
            label: __( 'With donations', 'gratora-donation-platform' ),
            value: stats ? String( stats.with_donations ) : '-',
        },
        {
            label: __( 'Lifetime given', 'gratora-donation-platform' ),
            value: stats && stats.total_donated_cents > 0
                ? formatAmount( stats.total_donated_cents )
                : '-',
        },
        {
            label: __( 'Avg lifetime value', 'gratora-donation-platform' ),
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

/** Beyond this the warnings bury the question they are attached to. */
const WARNING_LIMIT = 5;

/**
 * What the add-ons say this delete will take with it.
 *
 * Core cannot see a ticket order, a fundraiser page or a Gift Aid declaration,
 * so it asks and puts the answers here. Each line names its donor once more
 * than one is going: a bare sentence in a list of twelve says nothing about
 * which of them it is about.
 *
 * @param {Array} items The donors the delete will act on.
 * @return {Array} Lines to show, already capped.
 */
function deleteWarnings( items ) {
    const named = items.length > 1;

    return items.flatMap( ( item ) => ( item.delete_warnings || [] ).map( ( line ) => ( {
        key:  `${ item.id }:${ line }`,
        text: named
            ? sprintf(
                /* translators: 1: donor email or name, 2: what the add-on warned about. */
                __( '%1$s: %2$s', 'gratora-donation-platform' ),
                item.email || item.name || `#${ item.id }`,
                line
            )
            : line,
    } ) ) );
}

/**
 * The confirmation itself. Split by whether there is anything to count, so the
 * sentence never reads "0 donations and $0.00" for a donor who never gave.
 */
function donorDeleteMessage( n, damage ) {
    if ( damage === null ) {
        return n === 1
            ? __( 'Delete this donor? Their record goes for good, along with any attempt that never completed. A recurring donation is cancelled at the processor first, and the delete stops if the processor cannot be reached.', 'gratora-donation-platform' )
            : sprintf(
                /* translators: %d: number of donors to delete */
                _n(
                    'Delete %d donor? Their record goes for good, along with any attempt that never completed. A recurring donation is cancelled at the processor first, and the delete stops if the processor cannot be reached.',
                    'Delete %d donors? Their records go for good, along with any attempts that never completed. Recurring donations are cancelled at the processor first, and a donor whose processor cannot be reached is left alone.',
                    n,
                    'gratora-donation-platform'
                ),
                n
            );
    }

    return n === 1
        ? sprintf(
            /* translators: %s: what will be removed, for example "20 donations and $2,460.00" */
            __( 'Delete this donor? This removes %s and takes that money out of your totals, along with any attempt that never completed. A recurring donation is cancelled at the processor first, and the delete stops if the processor cannot be reached.', 'gratora-donation-platform' ),
            damage
        )
        : sprintf(
            /* translators: 1: number of donors, 2: what will be removed, for example "440 donations and $82,250.00" */
            __( 'Delete %1$d donors? This removes %2$s and takes that money out of your totals, along with any attempts that never completed. Recurring donations are cancelled at the processor first, and a donor whose processor cannot be reached is left alone.', 'gratora-donation-platform' ),
            n,
            damage
        );
}

/**
 * What a delete is about to destroy, in the numbers the rows already carry.
 *
 * The row count is the one figure that does not describe the damage: 22 donors
 * was 440 donations and $82,250, and the confirmation said 22.
 *
 * donations_count and total_donated_cents are paid money only and net of
 * refunds, so the sentence names exactly those and says separately that the
 * attempts nobody counted go as well, rather than implying the figure covers
 * every row that will be removed.
 */
function deleteDamage( items ) {
    const donations = items.reduce( ( n, i ) => n + ( parseInt( i.donations_count, 10 ) || 0 ), 0 );

    if ( donations === 0 ) {
        return null;
    }

    const cents = items.reduce( ( n, i ) => n + ( parseInt( i.total_donated_cents, 10 ) || 0 ), 0 );

    return sprintf(
        /* translators: 1: number of donations, 2: formatted money, for example $82,250.00 */
        _n(
            '%1$d donation and %2$s',
            '%1$d donations and %2$s',
            donations,
            'gratora-donation-platform'
        ),
        donations,
        formatAmount( cents )
    );
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
    // Per-donor reasons live in a persistent notice rather than a toast: the
    // reason is a sentence naming what to do next, and a toast that takes
    // itself away is no place to read one.
    const [ refusals, setRefusals ] = useState( [] );

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
            path: addQueryArgs( '/gratora/v1/admin/donors', {
                page:       view.page,
                per_page:   view.perPage,
                orderby:    sortField( view.sort?.field ),
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
                setError( err?.message || __( 'Failed to load donors.', 'gratora-donation-platform' ) );
            } )
            .finally( () => ! aborted && setLoading( false ) );

        apiFetch( {
            path: addQueryArgs( '/gratora/v1/admin/donors/stats', {
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
            label: __( 'ID', 'gratora-donation-platform' ),
            render: ( { item } ) => (
                <span className="gratora-ref-cell">
                    <a className="gratora-mono-link" href={ `#donor/${ item.id }` } { ...rowLinkProps }>
                        { item.id }
                    </a>
                </span>
            ),
        },
        {
            id:            'name',
            label:         __( 'Name', 'gratora-donation-platform' ),
            enableSorting: true,
            render: ( { item } ) => {
                const name = item.name || __( '(no name)', 'gratora-donation-platform' );
                return (
                    <div className="gratora-row">
                        { ! item.redacted && (
                            <span className="gratora-row__avatar" aria-hidden="true">
                                { initials( name ) }
                                { item.avatar_url && (
                                    <img className="gratora-row__avatar-photo" src={ item.avatar_url } alt="" loading="lazy" decoding="async" />
                                ) }
                            </span>
                        ) }
                        <div className="gratora-row__body">
                            <span className="gratora-ref-cell">
                                <a className="gratora-row__link gratora-row__link--strong" href={ `#donor/${ item.id }` } { ...rowLinkProps }>
                                    { name }
                                </a>
                                { item.is_test_only && (
                                    <span className="gratora-pill gratora-pill--test">{ __( 'Test', 'gratora-donation-platform' ) }</span>
                                ) }
                            </span>
                            { item.donor_type && item.donor_type !== 'individual' && (
                                <div className="gratora-row__sub" style={ { textTransform: 'capitalize' } }>
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
            label: __( 'Email', 'gratora-donation-platform' ),
            render: ( { item } ) => (
                item.email
                    ? <span className="gratora-mono">{ item.email }</span>
                    : <span className="gratora-row__sub">-</span>
            ),
        },
        {
            id:    'country',
            label: __( 'Country', 'gratora-donation-platform' ),
            elements: localizedCountries().map( ( c ) => ( { value: c.code, label: `${ c.code } - ${ c.label }` } ) ),
            filterBy: { operators: [ 'is' ] },
            render: ( { item } ) => (
                item.country
                    ? (
                        <span className="gratora-country">
                            <span className="gratora-country__code">{ item.country }</span>
                        </span>
                    )
                    : <span className="gratora-row__sub">-</span>
            ),
        },
        {
            id:    'donor_type',
            label: __( 'Donor type', 'gratora-donation-platform' ),
            elements: [
                { value: 'individual',   label: __( 'Individual', 'gratora-donation-platform' ) },
                { value: 'organization', label: __( 'Organization', 'gratora-donation-platform' ) },
                { value: 'household',    label: __( 'Household', 'gratora-donation-platform' ) },
            ],
            filterBy: { operators: [ 'is' ] },
            getValue: ( { item } ) => item.donor_type || 'individual',
            render:   ( { item } ) => (
                <span className="gratora-row__sub" style={ { textTransform: 'capitalize' } }>
                    { item.donor_type || 'individual' }
                </span>
            ),
        },
        {
            id:            'donations_count',
            label:         __( 'Donations', 'gratora-donation-platform' ),
            enableSorting: true,
            render: ( { item } ) => (
                <span className="gratora-amount gratora-amount--num">{ item.donations_count }</span>
            ),
        },
        {
            id:            'total_donated',
            label:         __( 'Total donated', 'gratora-donation-platform' ),
            enableSorting: true,
            render: ( { item } ) => (
                <span className="gratora-amount">
                    { formatAmount( item.total_donated_cents ) }
                </span>
            ),
        },
        {
            id:            'last_donation_at',
            label:         __( 'Last donation', 'gratora-donation-platform' ),
            enableSorting: true,
            render: ( { item } ) => (
                item.last_donation_at
                    ? (
                        <span className="gratora-time" title={ formatDate( item.last_donation_at ) }>
                            <span className="gratora-time__rel">{ timeAgo( item.last_donation_at ) }</span>
                            <span className="gratora-time__abs">{ formatDate( item.last_donation_at ) }</span>
                        </span>
                    )
                    : <span className="gratora-row__sub">-</span>
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
            label:         __( 'Delete', 'gratora-donation-platform' ),
            icon:          () => <DeleteIcon size={ 16 } strokeWidth={ 1.75 } />,
            isDestructive: true,
            supportsBulk:  true,
            // The row carries the gate's own answer, because the counters here
            // cannot stand in for it: they are live and paid only, so they say
            // nothing about a donor held by a receipt or a subscription signup
            // and nothing about one whose only donation was refunded.
            //
            // A donor the gate refuses is offered it as well, because the
            // answer names what to do about it and the row is the only place
            // that sentence can be read. Dropping the action instead shows a
            // row with no delete and nothing saying why.
            isEligible:    ( item ) => userCan( 'redact_donors' )
                && ( !! item.deletable || !! item.delete_blocked ),
            // DataViews hands a bulk callback the whole selection, not the
            // eligible subset, so isEligible only decides whether the button is
            // drawn. Without re-filtering, the count in the sentence is wrong
            // and the requests reach donors the gate exists to exclude.
            callback: ( selection ) => {
                const blocked = selection.filter( ( i ) => ! i.deletable && !! i.delete_blocked );
                if ( blocked.length ) {
                    setRefusals( blocked.map( ( i ) => ( {
                        id:     i.id,
                        who:    i.email || i.name || `#${ i.id }`,
                        reason: i.delete_blocked,
                    } ) ) );
                }

                const items = selection.filter( ( i ) => !! i.deletable );
                if ( ! items.length ) return;
                const n = items.length;
                const damage   = deleteDamage( items );
                const warnings = deleteWarnings( items );
                const shown    = warnings.slice( 0, WARNING_LIMIT );
                const hidden   = warnings.length - shown.length;

                setConfirm( {
                    title:        _n( 'Delete donor', 'Delete donors', n, 'gratora-donation-platform' ),
                    message: donorDeleteMessage( n, damage ),
                    body: warnings.length === 0 ? null : (
                        <div className="gratora-list-note" style={ { marginTop: 12 } }>
                            <strong>{ __( 'Also going:', 'gratora-donation-platform' ) }</strong>
                            <ul style={ { margin: '4px 0 0', paddingLeft: 18 } }>
                                { shown.map( ( w ) => <li key={ w.key }>{ w.text }</li> ) }
                            </ul>
                            { hidden > 0 && (
                                <p style={ { margin: '4px 0 0' } }>
                                    { sprintf(
                                        /* translators: %d: how many further warnings were not listed. */
                                        _n( 'and %d more.', 'and %d more.', hidden, 'gratora-donation-platform' ),
                                        hidden
                                    ) }
                                </p>
                            ) }
                        </div>
                    ),
                    confirmLabel: __( 'Delete', 'gratora-donation-platform' ),
                    destructive:  true,
                    // The bin asks for this before it removes one attempt, and
                    // this removes the donor and every attempt attached to them.
                    requireText:  'DELETE',
                    onConfirm: async () => {
                        // allSettled, not all: the first rejection abandoned
                        // the rest of the reporting, so a part-done batch
                        // showed nothing at all.
                        const results = await Promise.allSettled( items.map( ( i ) => apiFetch( {
                            path:   `/gratora/v1/admin/donors/${ i.id }`,
                            method: 'DELETE',
                            data:   { confirmation: 'DELETE' },
                        } ) ) );

                        // The server refuses with a sentence naming the plan
                        // and the processor, or the receipt and the refund
                        // that withdraws it. A count throws away the only
                        // part an operator can act on.
                        setRefusals(
                            results.flatMap( ( r, at ) => r.status === 'rejected'
                                ? [ {
                                    id:     items[ at ].id,
                                    who:    items[ at ].email || items[ at ].name || `#${ items[ at ].id }`,
                                    reason: r.reason?.message
                                        || __( 'The server refused without saying why. The reason is in the log under Tools.', 'gratora-donation-platform' ),
                                } ]
                                : []
                            )
                        );

                        report(
                            results,
                            ( count ) => sprintf(
                                /* translators: %d: how many donors were deleted. */
                                _n( '%d donor deleted.', '%d donors deleted.', count, 'gratora-donation-platform' ),
                                count
                            ),
                            ( count ) => sprintf(
                                /* translators: %d: how many donors could not be deleted. */
                                _n( '%d donor could not be deleted.', '%d donors could not be deleted.', count, 'gratora-donation-platform' ),
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
            label:         __( 'Redact (anonymize)', 'gratora-donation-platform' ),
            icon:          () => <RedactIcon size={ 16 } strokeWidth={ 1.75 } />,
            isDestructive: true,
            // One row at a time on purpose. The server compares a confirmation
            // against each donor's own address, so a batch behind a single
            // typed word erases people nobody named.
            isEligible:    ( item ) => userCan( 'redact_donors' ) && ! item.redacted,
            callback: ( selection ) => {
                const items = selection.filter( ( i ) => ! i.redacted );
                if ( ! items.length ) return;
                const n = items.length;
                const message = n === 1
                    ? __( 'Redact this donor? Their PII (name, email, address, phone) is wiped from the donor row and any active recurring plan is cancelled at the gateway, but their donations stay attached and counted. This cannot be undone.', 'gratora-donation-platform' )
                    : sprintf(
                        /* translators: %d: number of donors to redact */
                        _n(
                            'Redact %d donor? Their PII is wiped from the donor rows and any active recurring plan is cancelled at the gateway, but donations stay attached and counted. This cannot be undone.',
                            'Redact %d donors? Their PII is wiped from the donor rows and any active recurring plan is cancelled at the gateway, but donations stay attached and counted. This cannot be undone.',
                            n,
                            'gratora-donation-platform'
                        ),
                        n
                    );
                setConfirm( {
                    title:        _n( 'Redact donor', 'Redact donors', n, 'gratora-donation-platform' ),
                    message,
                    confirmLabel: __( 'Redact', 'gratora-donation-platform' ),
                    destructive:  true,
                    // The donor's own address, which is what the server
                    // compares. A fixed word is the same on every row, so it
                    // gets typed without reading the row it belongs to.
                    requireText:  items[ 0 ].email || `DONOR_${ items[ 0 ].id }`,
                    onConfirm: async () => {
                        const results = await Promise.allSettled( items.map( ( i ) => apiFetch( {
                            path:   `/gratora/v1/admin/donors/${ i.id }/redact`,
                            method: 'POST',
                            data:   { confirmation: i.email || `DONOR_${ i.id }` },
                        } ) ) );

                        report(
                            results,
                            ( count ) => sprintf(
                                /* translators: %d: how many donors were redacted. */
                                _n( '%d donor redacted.', '%d donors redacted.', count, 'gratora-donation-platform' ),
                                count
                            ),
                            ( count ) => sprintf(
                                /* translators: %d: how many donors could not be redacted. */
                                _n( '%d donor could not be redacted.', '%d donors could not be redacted.', count, 'gratora-donation-platform' ),
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
            <div className="gratora-crumbs">
                <a href={ dashboardHref( window.location.pathname ) }>{ __( 'Fundraising', 'gratora-donation-platform' ) }</a>
                <span className="sep">›</span>
                <span>{ __( 'Donors', 'gratora-donation-platform' ) }</span>
            </div>
            <div className="gratora-page-head">
                <div className="gratora-page-head__title-row">
                    <h1>{ __( 'Donors', 'gratora-donation-platform' ) }</h1>
                </div>
                <div className="gratora-page-head__right">
                    <span className="gratora-page-head__meta">
                        { sprintf( /* translators: %s: number of donors */ _n( '%s donor', '%s donors', total, 'gratora-donation-platform' ), total.toLocaleString() ) }
                    </span>
                    { toggleSlot }
                </div>
            </div>

            { error && (
                <Notice status="error" onRemove={ () => setError( null ) }>{ error }</Notice>
            ) }

            { refusals.length > 0 && (
                <Notice status="warning" onRemove={ () => setRefusals( [] ) }>
                    <ul style={ { margin: 0, paddingLeft: 18 } }>
                        { refusals.map( ( r ) => (
                            <li key={ r.id }>
                                <code>{ r.who }</code>{ ': ' }{ r.reason }
                            </li>
                        ) ) }
                    </ul>
                </Notice>
            ) }

            <KpiStrip items={ donorKpis( stats ) } loading={ loading && ! stats } />

            { ! loading && ! error && total === 0 && ! filtered ? (
                <EmptyState
                    icon={ <UsersIcon size={ 22 } strokeWidth={ 1.75 } /> }
                    title={ __( 'No donors yet', 'gratora-donation-platform' ) }
                    body={ __( 'Anyone who donates is added here. Publish a form to take the first one.', 'gratora-donation-platform' ) }
                />
            ) : (
                <div className={ `gratora-dataviews${ ! loading && data.length === 0 && filtered ? ' is-no-results' : '' }` }>
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
            className="gratora-view-toggle"
            role="tablist"
            tabIndex={ -1 }
            aria-label={ __( 'Donor sections', 'gratora-donation-platform' ) }
            onKeyDown={ ( e ) => tablistKeyDown( e, [ 'list', 'insights' ], active, onChange ) }
        >
            <button
                type="button"
                role="tab"
                aria-selected={ active === 'list' }
                tabIndex={ active === 'list' ? 0 : -1 }
                className={ `gratora-cmp-toggle${ active === 'list' ? ' is-active' : '' }` }
                onClick={ () => onChange( 'list' ) }
            >
                <IconList />
                { __( 'List', 'gratora-donation-platform' ) }
            </button>
            <button
                type="button"
                role="tab"
                aria-selected={ active === 'insights' }
                tabIndex={ active === 'insights' ? 0 : -1 }
                className={ `gratora-cmp-toggle${ active === 'insights' ? ' is-active' : '' }` }
                onClick={ () => onChange( 'insights' ) }
            >
                <IconInsights />
                { __( 'Insights', 'gratora-donation-platform' ) }
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
    const root = document.getElementById( 'gratora-admin-donors' );
    if ( ! root ) return;
    createRoot( root ).render( <><DonorsRoot /><Toaster /></> );
} );
