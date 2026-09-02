// Donations list: paginated DataViews against /fundkit/v1/admin/donations.

import { useState, useEffect, useMemo } from '@wordpress/element';
import { DataViews } from '@wordpress/dataviews';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Mail as MailIcon, Check as CheckIcon, Coins, Plus, SearchX } from 'lucide-react';

import Btn from '../_shared/components/Btn';
import { useTableView } from '../_shared/useTableView';
import Notice from '../_shared/components/Notice';
import RecordDonationDrawer from './RecordDonationDrawer';
import DateField from '../_shared/components/DateField';
import EmptyState from '../_shared/components/EmptyState';
import { isViewFiltered, clearedView } from '../_shared/viewFilters';
import ConfirmDialog from '../_shared/components/ConfirmDialog';
import { rowLinkProps } from '../_shared/rowLink';
import { dashboardHref } from '../_shared/adminPages';
import notify from '../_shared/notify';
import KpiStrip from '../_shared/components/KpiStrip';
import { Switch } from '../_shared/components/Switch';
import StatusBadge from '../_shared/components/StatusBadge';
import { formatAmount, formatDate, STATUS_LABEL } from './format';
import { timeAgo, detailHref as campaignDetailHref, formEditorHref } from '../_shared/format';

const STATUS_OPTIONS = Object.entries( STATUS_LABEL ).map( ( [ value, label ] ) => ( {
    value,
    label,
} ) );

/**
 * The stored frequency as a short badge. Values come from the column, so an
 * unknown one is shown rather than swallowed.
 */
function frequencyLabel( frequency ) {
    switch ( frequency ) {
        case 'monthly':   return __( 'Monthly', 'fundkit-fundraising-campaigns' );
        case 'yearly':    return __( 'Yearly', 'fundkit-fundraising-campaigns' );
        case 'weekly':    return __( 'Weekly', 'fundkit-fundraising-campaigns' );
        case 'quarterly': return __( 'Quarterly', 'fundkit-fundraising-campaigns' );
        default:          return __( 'Recurring', 'fundkit-fundraising-campaigns' );
    }
}

// 'recurring' is the useful default question ("which of these repeat?");
// the individual cadences are there for orgs that run more than one.
const FREQUENCY_OPTIONS = [
    { value: 'recurring', label: __( 'Recurring (any)', 'fundkit-fundraising-campaigns' ) },
    { value: 'one_time',  label: __( 'One time', 'fundkit-fundraising-campaigns' ) },
    { value: 'monthly',   label: __( 'Monthly', 'fundkit-fundraising-campaigns' ) },
    { value: 'yearly',    label: __( 'Yearly', 'fundkit-fundraising-campaigns' ) },
    { value: 'weekly',    label: __( 'Weekly', 'fundkit-fundraising-campaigns' ) },
    { value: 'quarterly', label: __( 'Quarterly', 'fundkit-fundraising-campaigns' ) },
];

function detailHref( reference ) {
    return addQueryArgs( window.location.pathname, {
        page:      'fundkit-donations',
        view:      'detail',
        reference,
    } );
}

// The dashboard deep-links here with ?status=failed, so seed the view from the
// URL instead of dropping the param. Unknown values are ignored rather than
// filtered on, which would show an unexplained empty table.
function initialFilters() {
    const status = new URLSearchParams( window.location.search ).get( 'status' );
    return status && Object.hasOwn( STATUS_LABEL, status )
        ? [ { field: 'status', operator: 'is', value: status } ]
        : [];
}

// A view preference, not a setting: it belongs to the person looking at the
// screen, and having it reset on every page load would make it useless for the
// thing it is for, which is watching test donations arrive while you make them.
const TEST_PREF = 'fundkit.donations.includeTest';

const readTestPref = () => {
    try {
        return window.localStorage?.getItem( TEST_PREF ) === '1';
    } catch ( e ) {
        return false;
    }
};

export default function List() {
    const [ includeTest, setIncludeTest ] = useState( readTestPref );

    const [ view, setView ] = useTableView( 'donations', {
        type:    'table',
        perPage: 25,
        page:    1,
        sort:    { field: 'created_at', direction: 'desc' },
        filters: initialFilters(),
        search:  '',
        // is_test stays out of the columns. A rehearsal donation that looks
        // exactly like a real one is worse than not showing it at all, so it is
        // badged on the reference instead: visible on the row it belongs to,
        // without a column that reads the same on every other row. Still in the
        // picker, and still a filter.
        // 'form' is defined but not shown: most orgs run one form per campaign,
        // so the column repeats the campaign next to it. Still in the picker.
        fields:  [ 'reference', 'status', 'donor', 'amount', 'frequency', 'gateway', 'campaign', 'created_at' ],
        // Widths are read from here, not from the field. The donor is what a
        // row is about, and the reference is a fixed short string that would
        // otherwise take the space as the first column.
        layout: {
            styles: {
                reference: { width: '170px' },
                donor:     { width: '28%', minWidth: '240px' },
                frequency: { width: '110px' },
            },
        },
    }, () => fields.map( ( f ) => f.id ) );

    const toggleTest = ( on ) => {
        setIncludeTest( on );
        try {
            window.localStorage?.setItem( TEST_PREF, on ? '1' : '0' );
        } catch ( e ) { /* private mode: the toggle still works for this visit */ }
        setView( ( v ) => ( {
            ...v,
            page: 1,
            // The two exclusive filters and this scope answer different
            // questions; leaving "Test only" on under it would be a contradiction.
            filters: ( v.filters || [] ).filter( ( f ) => f.field !== 'is_test' ),
        } ) );
    };

    const [ data, setData ]       = useState( [] );
    const [ total, setTotal ]     = useState( 0 );
    const [ loading, setLoading ] = useState( false );
    const [ recording, setRecording ] = useState( false );
    const [ fetchError, setFetchError ]   = useState( null );
    // Test donations are excluded unless asked for. Saying how many were left
    // out turns a silent exclusion into a visible one: an admin who donates
    // while the org is in test mode otherwise watches it vanish.
    const [ testHidden, setTestHidden ]   = useState( 0 );
    const [ createdFrom, setCreatedFrom ] = useState( '' );
    const [ createdTo,   setCreatedTo ]   = useState( '' );
    const [ stats, setStats ]     = useState( null );
    const [ campaigns, setCampaigns ] = useState( [] );
    // Pending confirm dialog. Shape: { title, message, confirmLabel, isDestructive, onConfirm }.
    const [ confirm, setConfirm ] = useState( null );
    const [ gatewayOptions, setGatewayOptions ] = useState( [] );

    // Campaign list for the campaign filter dropdown. Forms could follow the
    // same pattern, but they typically run into the hundreds per org and
    // aren't worth front-loading here; the donor portal scopes by donor_id.
    //
    // Not /admin/campaigns, for the reason RecordDonationDrawer already gives:
    // that route needs fundkit_manage_campaigns, which this screen does not, so a
    // role scoped to viewing donations got a 403 and a filter with no options
    // in it and nothing saying why.
    useEffect( () => {
        let aborted = false;
        apiFetch( { path: '/fundkit/v1/admin/donations/campaign-options' } )
            .then( ( res ) => { if ( ! aborted ) setCampaigns( Array.isArray( res ) ? res : [] ); } )
            .catch( ( err ) => {
                if ( aborted ) return;
                setCampaigns( [] );
                notify.error( err?.message || __( 'The campaign filter could not be loaded.', 'fundkit-fundraising-campaigns' ) );
            } );
        return () => { aborted = true; };
    }, [] );

    // Gateway options come from the rows, not from the registry: a slug
    // outlives the gateway being disconnected, and the Give importer carries in
    // slugs core never registers. Refetches with the test scope so the dropdown
    // never offers an option that would return nothing.
    useEffect( () => {
        let aborted = false;
        apiFetch( { path: addQueryArgs( '/fundkit/v1/admin/donations/gateway-options', {
            include_test: includeTest || undefined,
        } ) } )
            .then( ( res ) => { if ( ! aborted ) setGatewayOptions( Array.isArray( res ) ? res : [] ); } )
            .catch( () => { if ( ! aborted ) setGatewayOptions( [] ); } );
        return () => { aborted = true; };
    }, [ includeTest ] );

    const filterValue = ( field ) => view.filters?.find( ( f ) => f.field === field )?.value;
    const statusFilter   = filterValue( 'status' );

    // Which empty this screen shows depends on it. See _shared/viewFilters.
    const filtered = isViewFiltered( view, [ createdFrom, createdTo ] );
    const clearFilters = () => {
        setView( clearedView( view ) );
        setCreatedFrom( '' );
        setCreatedTo( '' );
    };
    const gatewayFilter  = filterValue( 'gateway' );
    const frequencyFilter = filterValue( 'frequency' );
    const campaignFilter = filterValue( 'campaign' );
    const testFilter     = filterValue( 'is_test' );
    const supersededFilter = filterValue( 'superseded' );

    const apiParams = useMemo( () => ( {
        page:         view.page,
        per_page:     view.perPage,
        orderby:      view.sort?.field === 'amount' ? 'amount_cents' : ( view.sort?.field || 'created_at' ),
        order:        view.sort?.direction || 'desc',
        search:       view.search || undefined,
        status:       statusFilter || undefined,
        gateway:      gatewayFilter || undefined,
        frequency:    frequencyFilter || undefined,
        campaign_id:  campaignFilter || undefined,
        is_test:      testFilter === 'yes' ? true : ( testFilter === 'no' ? false : undefined ),
        include_test: includeTest || undefined,
        superseded:   supersededFilter === 'yes' ? true : ( supersededFilter === 'no' ? false : undefined ),
        created_from: createdFrom || undefined,
        created_to:   createdTo   || undefined,
    } ), [ view, statusFilter, gatewayFilter, frequencyFilter, campaignFilter, testFilter, supersededFilter, includeTest, createdFrom, createdTo ] );

    useEffect( () => {
        let aborted = false;
        setLoading( true );

        setFetchError( null );
        apiFetch( {
            path:  addQueryArgs( '/fundkit/v1/admin/donations', apiParams ),
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
                setFetchError( err?.message || __( 'Failed to load donations.', 'fundkit-fundraising-campaigns' ) );
                setData( [] );
                setTotal( 0 );
                setTestHidden( 0 );
            } )
            .finally( () => ! aborted && setLoading( false ) );

        // Stats use the filter shape (not pagination), so strip those keys.
        const statsParams = { ...apiParams };
        delete statsParams.page;
        delete statsParams.per_page;
        delete statsParams.orderby;
        delete statsParams.order;
        apiFetch( { path: addQueryArgs( '/fundkit/v1/admin/donations/stats', statsParams ) } )
            .then( ( res ) => { if ( ! aborted ) setStats( res || null ); } )
            .catch( () => { if ( ! aborted ) setStats( null ); } );

        return () => {
            aborted = true;
        };
    }, [ apiParams ] );

    const fields = useMemo( () => [
        {
            id:            'reference',
            label:         __( 'Reference', 'fundkit-fundraising-campaigns' ),
            enableSorting: true,
            // The badge rides the reference rather than occupying a column of
            // its own: on a live-only list that column is the same value on
            // every row, and the thing worth knowing is that this particular
            // donation took no money.
            render: ( { item } ) => (
                <span className="fundkit-ref-cell">
                    <a className="fundkit-mono-link" href={ detailHref( item.reference ) } { ...rowLinkProps }>
                        { item.reference }
                    </a>
                    { item.is_test && (
                        <span className="fundkit-pill fundkit-pill--test">{ __( 'Test', 'fundkit-fundraising-campaigns' ) }</span>
                    ) }
                    { item.superseded && (
                        <span className="fundkit-pill fundkit-pill--gray" title={ __( 'The donor started again on another gateway. Nothing they do now can collect this attempt.', 'fundkit-fundraising-campaigns' ) }>
                            { __( 'Replaced', 'fundkit-fundraising-campaigns' ) }
                        </span>
                    ) }
                </span>
            ),
        },
        {
            id:    'frequency',
            label: __( 'Frequency', 'fundkit-fundraising-campaigns' ),
            // Nothing on the row said whether the money came from a standing
            // recurring or a one-off, which is the first thing asked of it.
            elements: FREQUENCY_OPTIONS,
            filterBy: { operators: [ 'is' ] },
            // Not StatusBadge: "monthly" is a cadence, not a lifecycle status,
            // so it has no entry in that map and would come out grey.
            render: ( { item } ) => (
                item.frequency && item.frequency !== 'one_time'
                    ? <span className="fundkit-pill fundkit-pill--blue">{ frequencyLabel( item.frequency ) }</span>
                    : <span className="fundkit-pill fundkit-pill--gray">{ __( 'One time', 'fundkit-fundraising-campaigns' ) }</span>
            ),
        },
        {
            id:    'donor',
            label: __( 'Donor', 'fundkit-fundraising-campaigns' ),
            render: ( { item } ) => {
                const d = item.donor;
                if ( ! d ) return <span className="fundkit-row__sub">-</span>;
                const name = d.name || __( '(no name)', 'fundkit-fundraising-campaigns' );
                return (
                    <div className="fundkit-row">
                        <div className="fundkit-row__body">
                            <div className="fundkit-row__name">{ name }</div>
                            { d.email && <div className="fundkit-row__sub fundkit-row__sub--mono">{ d.email }</div> }
                        </div>
                    </div>
                );
            },
        },
        {
            id:            'amount',
            label:         __( 'Amount', 'fundkit-fundraising-campaigns' ),
            enableSorting: true,
            render: ( { item } ) => {
                const showBase =
                    item.base_amount_cents != null &&
                    item.base_currency &&
                    item.base_currency !== item.currency;
                return (
                    <span className={ `fundkit-amount${ item.status === 'refunded' ? ' fundkit-amount--strike' : '' }` }>
                        { formatAmount( item.amount_cents, item.currency ) }
                        { showBase && (
                            <span className="fundkit-amount__base">
                                { '≈ ' }{ formatAmount( item.base_amount_cents, item.base_currency ) }
                            </span>
                        ) }
                    </span>
                );
            },
        },
        {
            id:            'status',
            label:         __( 'Status', 'fundkit-fundraising-campaigns' ),
            elements:      STATUS_OPTIONS,
            filterBy:      { operators: [ 'is' ] },
            enableSorting: true,
            render:        ( { item } ) => <StatusBadge status={ item.status } />,
        },
        {
            id:       'gateway',
            label:    __( 'Gateway', 'fundkit-fundraising-campaigns' ),
            elements: gatewayOptions,
            filterBy: { operators: [ 'is' ] },
            render: ( { item } ) => {
                if ( ! item.gateway ) return <span>-</span>;
                const named = gatewayOptions.find( ( g ) => g.value === item.gateway );
                return named
                    ? <span>{ named.label }</span>
                    : <span style={ { textTransform: 'capitalize' } }>{ item.gateway }</span>;
            },
        },
        {
            id:       'campaign',
            label:    __( 'Campaign', 'fundkit-fundraising-campaigns' ),
            elements: campaigns.map( ( c ) => ( { value: String( c.id ), label: c.title || `#${ c.id }` } ) ),
            filterBy: { operators: [ 'is' ] },
            render: ( { item } ) => {
                if ( ! item.campaign?.title ) {
                    return <span className="fundkit-row__sub">-</span>;
                }

                return (
                    <div className="fundkit-row">
                        <div className="fundkit-row__body">
                            <a className="fundkit-row__link" href={ campaignDetailHref( item.campaign.id ) } { ...rowLinkProps }>
                                { item.campaign.title }
                            </a>
                            { /* Who inside the campaign it came through, when
                                 something owns that idea. The campaign alone
                                 does not say whether a donation arrived
                                 through somebody raising for it. */ }
                            { item.attributed_to?.label && (
                                <div className="fundkit-row__sub">{ item.attributed_to.label }</div>
                            ) }
                        </div>
                    </div>
                );
            },
        },
        {
            id:       'is_test',
            label:    __( 'Test mode', 'fundkit-fundraising-campaigns' ),
            elements: [
                { value: 'yes', label: __( 'Test only', 'fundkit-fundraising-campaigns' ) },
                { value: 'no',  label: __( 'Live only', 'fundkit-fundraising-campaigns' ) },
            ],
            filterBy:    { operators: [ 'is' ] },
            getValue:    ( { item } ) => ( item.is_test ? 'yes' : 'no' ),
            render:      ( { item } ) => item.is_test
                ? <span className="fundkit-pill fundkit-pill--test">{ __( 'Test', 'fundkit-fundraising-campaigns' ) }</span>
                : <span className="fundkit-row__sub">-</span>,
        },
        {
            id:    'superseded',
            label: __( 'Replaced attempt', 'fundkit-fundraising-campaigns' ),
            // Out of the columns and out of the default view, for the reason
            // the reference badge exists: on a list that hides them the column
            // reads the same on every row. It is here so an admin who needs one
            // of these can ask for it by name.
            elements: [
                { value: 'yes', label: __( 'Replaced only', 'fundkit-fundraising-campaigns' ) },
                { value: 'no',  label: __( 'Live attempts only', 'fundkit-fundraising-campaigns' ) },
            ],
            filterBy: { operators: [ 'is' ] },
            getValue: ( { item } ) => ( item.superseded ? 'yes' : 'no' ),
            render:   ( { item } ) => item.superseded
                ? <span className="fundkit-pill fundkit-pill--gray">{ __( 'Replaced', 'fundkit-fundraising-campaigns' ) }</span>
                : <span className="fundkit-row__sub">-</span>,
        },
        {
            id:     'form',
            label:  __( 'Form', 'fundkit-fundraising-campaigns' ),
            render: ( { item } ) => (
                item.form?.title
                    ? <a className="fundkit-row__link" href={ formEditorHref( item.form.id ) } { ...rowLinkProps }>{ item.form.title }</a>
                    : <span className="fundkit-row__sub">-</span>
            ),
        },
        {
            id:            'created_at',
            label:         __( 'Created', 'fundkit-fundraising-campaigns' ),
            enableSorting: true,
            render: ( { item } ) => (
                <span className="fundkit-time" title={ formatDate( item.created_at ) }>
                    <span className="fundkit-time__rel">{ timeAgo( item.created_at ) }</span>
                    <span className="fundkit-time__abs">{ formatDate( item.created_at ) }</span>
                </span>
            ),
        },
    ], [ campaigns, gatewayOptions ] );

    const paginationInfo = useMemo(
        () => ( {
            totalItems: total,
            totalPages: Math.max( 1, Math.ceil( total / view.perPage ) ),
        } ),
        [ total, view.perPage ]
    );

    const refetch = () => setView( ( v ) => ( { ...v } ) );

    const actions = useMemo( () => [
        {
            id:           'mark-paid',
            label:        __( 'Mark as paid', 'fundkit-fundraising-campaigns' ),
            icon:         () => <CheckIcon size={ 16 } strokeWidth={ 1.75 } />,
            supportsBulk: true,
            // Pending and still-settling donations can be flipped to paid;
            // failed ones use the per-row detail action (which captures a
            // reason). A bank debit sits in processing until it lands, and an
            // admin reconciling a statement is often the first to know.
            isEligible:   ( item ) => item.status === 'pending' || item.status === 'processing',
            callback: ( items ) => {
                const targets = items.filter( ( i ) => i.status === 'pending' || i.status === 'processing' );
                if ( ! targets.length ) return;
                const n = targets.length;
                const message = n === 1
                    ? __( 'Mark this donation as paid? A receipt will be sent.', 'fundkit-fundraising-campaigns' )
                    : sprintf(
                        /* translators: %d: number of donations */
                        _n(
                            'Mark %d donation as paid? Receipts will be sent to each donor.',
                            'Mark %d donations as paid? Receipts will be sent to each donor.',
                            n,
                            'fundkit-fundraising-campaigns'
                        ),
                        n
                    );
                setConfirm( {
                    title:        __( 'Mark donations as paid', 'fundkit-fundraising-campaigns' ),
                    message,
                    confirmLabel: __( 'Mark as paid', 'fundkit-fundraising-campaigns' ),
                    onConfirm: async () => {
                        // allSettled, and the refetch outside the counts: a
                        // partial failure still paid some of them and emailed
                        // their donors a receipt, and a batch reported as a
                        // single failure leaves those rows reading Pending.
                        const results = await Promise.allSettled( targets.map( ( i ) => apiFetch( {
                            path:   `/fundkit/v1/admin/donations/${ encodeURIComponent( i.reference ) }/mark-paid`,
                            method: 'POST',
                        } ) ) );

                        const done   = results.filter( ( r ) => r.status === 'fulfilled' ).length;
                        const failed = results.length - done;

                        if ( done > 0 ) {
                            notify.success( sprintf(
                                /* translators: %d: number of donations */
                                _n( '%d donation marked paid.', '%d donations marked paid.', done, 'fundkit-fundraising-campaigns' ),
                                done
                            ) );
                        }
                        if ( failed > 0 ) {
                            notify.error( sprintf(
                                /* translators: %d: number of donations */
                                _n( '%d donation could not be marked paid.', '%d donations could not be marked paid.', failed, 'fundkit-fundraising-campaigns' ),
                                failed
                            ) );
                        }

                        refetch();
                    },
                } );
            },
        },
        {
            id:           'resend-receipt',
            label:        __( 'Resend receipt', 'fundkit-fundraising-campaigns' ),
            icon:         () => <MailIcon size={ 16 } strokeWidth={ 1.75 } />,
            supportsBulk: true,
            // Only paid donations have a receipt to resend, and an erased donor
            // has no address left to send it to.
            isEligible:   ( item ) => item.status === 'paid' && ! item.donor?.redacted,
            callback: ( items ) => {
                const targets = items.filter( ( i ) => i.status === 'paid' && ! i.donor?.redacted );
                if ( ! targets.length ) return;
                const n = targets.length;
                const message = n === 1
                    ? __( 'Resend the receipt for this donation?', 'fundkit-fundraising-campaigns' )
                    : sprintf(
                        /* translators: %d: number of donations */
                        _n( 'Resend receipts for %d donation?', 'Resend receipts for %d donations?', n, 'fundkit-fundraising-campaigns' ),
                        n
                    );
                setConfirm( {
                    title:        __( 'Resend receipts', 'fundkit-fundraising-campaigns' ),
                    message,
                    confirmLabel: __( 'Resend', 'fundkit-fundraising-campaigns' ),
                    onConfirm: async () => {
                        // Counted separately: a batch reported as a single
                        // failure reads as nothing having happened, so admins
                        // press it again and every donor whose receipt did go
                        // out receives it twice.
                        const results = await Promise.allSettled( targets.map( ( i ) => apiFetch( {
                            path:   `/fundkit/v1/admin/donations/${ encodeURIComponent( i.reference ) }/resend-receipt`,
                            method: 'POST',
                        } ) ) );

                        const sent   = results.filter( ( r ) => r.status === 'fulfilled' ).length;
                        const failed = results.length - sent;

                        if ( sent > 0 ) {
                            notify.success( sprintf(
                                /* translators: %d: receipt count */
                                _n( '%d receipt resent.', '%d receipts resent.', sent, 'fundkit-fundraising-campaigns' ),
                                sent
                            ) );
                        }
                        if ( failed > 0 ) {
                            notify.error( sprintf(
                                /* translators: %d: receipt count */
                                _n( '%d receipt could not be resent.', '%d receipts could not be resent.', failed, 'fundkit-fundraising-campaigns' ),
                                failed
                            ) );
                        }
                    },
                } );
            },
        },
    ], [] );

    return (
        <div>
            <div className="fundkit-crumbs">
                <a href={ dashboardHref( window.location.pathname ) }>{ __( 'FundKit', 'fundkit-fundraising-campaigns' ) }</a>
                <span className="sep">›</span>
                <span>{ __( 'Donations', 'fundkit-fundraising-campaigns' ) }</span>
            </div>
            <div className="fundkit-page-head">
                <div className="fundkit-page-head__title-row">
                    <h1>{ __( 'Donations', 'fundkit-fundraising-campaigns' ) }</h1>
                </div>
                <div className="fundkit-page-head__right">
                    <div className="fundkit-page-head__date-filters">
                        <span className="fundkit-page-head__date-filters-label">{ __( 'From', 'fundkit-fundraising-campaigns' ) }</span>
                        <DateField
                            value={ createdFrom }
                            onChange={ ( v ) => setCreatedFrom( v || '' ) }
                            ariaLabel={ __( 'Filter donations from', 'fundkit-fundraising-campaigns' ) }
                            placeholder={ __( 'Any', 'fundkit-fundraising-campaigns' ) }
                        />
                        <span className="fundkit-page-head__date-filters-label">{ __( 'To', 'fundkit-fundraising-campaigns' ) }</span>
                        <DateField
                            value={ createdTo }
                            onChange={ ( v ) => setCreatedTo( v || '' ) }
                            ariaLabel={ __( 'Filter donations to', 'fundkit-fundraising-campaigns' ) }
                            placeholder={ __( 'Any', 'fundkit-fundraising-campaigns' ) }
                        />
                        { ( createdFrom || createdTo ) && (
                            <button
                                type="button"
                                className="fundkit-page-head__date-filters-clear"
                                onClick={ () => { setCreatedFrom( '' ); setCreatedTo( '' ); } }
                            >
                                { __( 'Clear', 'fundkit-fundraising-campaigns' ) }
                            </button>
                        ) }
                    </div>
                    <span className="fundkit-page-head__meta">
                        { sprintf( /* translators: %s: number of donations */ _n( '%s donation', '%s donations', total, 'fundkit-fundraising-campaigns' ), total.toLocaleString() ) }
                    </span>
                    { /* Nothing to reveal on a site that has never taken a test
                         donation, so the control is only offered once some
                         exist, or while it is on and needs turning off. */ }
                    { ( testHidden > 0 || includeTest ) && (
                        <label className="fundkit-inline-toggle">
                            <Switch
                                checked={ includeTest }
                                onChange={ toggleTest }
                                label={ __( 'Show test donations', 'fundkit-fundraising-campaigns' ) }
                            />
                            <span>{ __( 'Show test donations', 'fundkit-fundraising-campaigns' ) }</span>
                        </label>
                    ) }
                    <Btn variant="primary" onClick={ () => setRecording( true ) }>
                        <Plus size={ 16 } strokeWidth={ 1.75 } />
                        { __( 'Record a donation', 'fundkit-fundraising-campaigns' ) }
                    </Btn>
                </div>
            </div>

            { recording && (
                <RecordDonationDrawer
                    onClose={ () => setRecording( false ) }
                    onRecorded={ ( created ) => {
                        setRecording( false );
                        refetch();
                        // A donation dated to when the money arrived sorts by
                        // that date, so a January check entered in July lands
                        // pages down a newest-first list and the admin sees
                        // nothing happen. The toast is the only confirmation
                        // they get, so it names the row.
                        notify.success( sprintf(
                            /* translators: %s: the new donation's reference. */
                            __( 'Recorded as %s.', 'fundkit-fundraising-campaigns' ),
                            created?.reference || ''
                        ) );
                    } }
                />
            ) }
            { fetchError && (
                <Notice status="error" isDismissible={ false }>
                    { fetchError }
                </Notice>
            ) }

            { testHidden > 0 && ! includeTest && (
                <Notice status="info" isDismissible={ false }>
                    { sprintf(
                        /* translators: %d: number of test donations hidden. */
                        _n(
                            '%d test donation is hidden.',
                            '%d test donations are hidden.',
                            testHidden,
                            'fundkit-fundraising-campaigns'
                        ),
                        testHidden
                    ) }
                    { ' ' }
                    <Btn variant="link" onClick={ () => toggleTest( true ) }>
                        { __( 'Show them', 'fundkit-fundraising-campaigns' ) }
                    </Btn>
                </Notice>
            ) }

            <KpiStrip items={ donationKpis( stats ) } loading={ loading && ! stats } />

            { /* Driven by the figures themselves, not by the toggle: the note
                 is what an org reads to decide whether a number can be quoted,
                 so it may only appear over numbers that are actually counting
                 test donations. */ }
            { stats?.includes_test && (
                <p className="fundkit-list-note">
                    { __( 'Test donations are counted in the figures above and shown in the list below. These totals include money that was never actually taken, so they cannot be quoted as income.', 'fundkit-fundraising-campaigns' ) }
                </p>
            ) }

            { ! loading && total === 0 && ! filtered ? (
                <EmptyState
                    icon={ <Coins size={ 22 } strokeWidth={ 1.75 } /> }
                    title={ __( 'No donations yet', 'fundkit-fundraising-campaigns' ) }
                    body={ __( 'Donations made through your published forms will appear here. Donors are created automatically from each completed donation.', 'fundkit-fundraising-campaigns' ) }
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

            <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
        </div>
    );
}

function donationKpis( stats ) {
    // Per card, not once above the strip: a single figure gets read out, quoted
    // and screenshotted on its own, and it has to carry its own disclaimer.
    const includesTest = !! stats?.includes_test;
    const testSub = includesTest ? __( 'Includes test donations', 'fundkit-fundraising-campaigns' ) : null;

    let raisedSub = testSub;
    if ( stats?.currency ) {
        raisedSub = includesTest
            ? sprintf(
                /* translators: %s: currency code */
                __( 'in %s, includes test donations', 'fundkit-fundraising-campaigns' ),
                stats.currency
            )
            : sprintf( /* translators: %s: currency code */ __( 'in %s', 'fundkit-fundraising-campaigns' ), stats.currency );
    }

    return [
        {
            label: __( 'Total donations', 'fundkit-fundraising-campaigns' ),
            value: stats ? stats.total_count.toLocaleString() : '-',
            sub:   testSub,
        },
        {
            label: __( 'Paid', 'fundkit-fundraising-campaigns' ),
            value: stats ? stats.paid_count.toLocaleString() : '-',
            sub:   testSub,
        },
        {
            label: __( 'Raised', 'fundkit-fundraising-campaigns' ),
            value: stats
                ? formatAmount( stats.raised_cents, stats.currency || undefined )
                : '-',
            sub: raisedSub,
        },
        {
            label: __( 'Unique donors', 'fundkit-fundraising-campaigns' ),
            value: stats ? stats.donors_count.toLocaleString() : '-',
            sub:   testSub,
        },
    ];
}

