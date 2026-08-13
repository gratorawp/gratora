// Donations list: paginated DataViews against /dono/v1/admin/donations.

import { useState, useEffect, useMemo } from '@wordpress/element';
import { DataViews } from '@wordpress/dataviews';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Mail as MailIcon, Check as CheckIcon, Coins, Plus } from 'lucide-react';

import Btn from '../_shared/components/Btn';
import Notice from '../_shared/components/Notice';
import RecordDonationDrawer from './RecordDonationDrawer';
import DateField from '../_shared/components/DateField';
import EmptyState from '../_shared/components/EmptyState';
import ConfirmDialog from '../_shared/components/ConfirmDialog';
import { rowLinkProps } from '../_shared/rowLink';
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
        case 'monthly':   return __( 'Monthly', 'dono-fundraising-platform' );
        case 'yearly':    return __( 'Yearly', 'dono-fundraising-platform' );
        case 'weekly':    return __( 'Weekly', 'dono-fundraising-platform' );
        case 'quarterly': return __( 'Quarterly', 'dono-fundraising-platform' );
        default:          return __( 'Recurring', 'dono-fundraising-platform' );
    }
}

// 'recurring' is the useful default question ("which of these repeat?");
// the individual cadences are there for orgs that run more than one.
const FREQUENCY_OPTIONS = [
    { value: 'recurring', label: __( 'Recurring (any)', 'dono-fundraising-platform' ) },
    { value: 'one_time',  label: __( 'One time', 'dono-fundraising-platform' ) },
    { value: 'monthly',   label: __( 'Monthly', 'dono-fundraising-platform' ) },
    { value: 'yearly',    label: __( 'Yearly', 'dono-fundraising-platform' ) },
    { value: 'weekly',    label: __( 'Weekly', 'dono-fundraising-platform' ) },
    { value: 'quarterly', label: __( 'Quarterly', 'dono-fundraising-platform' ) },
];

function detailHref( reference ) {
    return addQueryArgs( window.location.pathname, {
        page:      'dono-donations',
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
const TEST_PREF = 'dono.donations.includeTest';

const readTestPref = () => {
    try {
        return window.localStorage?.getItem( TEST_PREF ) === '1';
    } catch ( e ) {
        return false;
    }
};

export default function List() {
    const [ includeTest, setIncludeTest ] = useState( readTestPref );

    const [ view, setView ] = useState( {
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
        fields:  [ 'reference', 'frequency', 'status', 'donor', 'amount', 'gateway', 'campaign', 'created_at' ],
    } );

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
    const [ actionError, setActionError ] = useState( null );
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
    // that route needs dono_manage_campaigns, which this screen does not, so a
    // role scoped to viewing donations got a 403 and a filter with no options
    // in it and nothing saying why.
    useEffect( () => {
        let aborted = false;
        apiFetch( { path: '/dono/v1/admin/donations/campaign-options' } )
            .then( ( res ) => { if ( ! aborted ) setCampaigns( Array.isArray( res ) ? res : [] ); } )
            .catch( ( err ) => {
                if ( aborted ) return;
                setCampaigns( [] );
                notify.error( err?.message || __( 'The campaign filter could not be loaded.', 'dono-fundraising-platform' ) );
            } );
        return () => { aborted = true; };
    }, [] );

    // Gateway options come from the rows, not from the registry: a slug
    // outlives the gateway being disconnected, and the Give importer carries in
    // slugs core never registers. Refetches with the test scope so the dropdown
    // never offers an option that would return nothing.
    useEffect( () => {
        let aborted = false;
        apiFetch( { path: addQueryArgs( '/dono/v1/admin/donations/gateway-options', {
            include_test: includeTest || undefined,
        } ) } )
            .then( ( res ) => { if ( ! aborted ) setGatewayOptions( Array.isArray( res ) ? res : [] ); } )
            .catch( () => { if ( ! aborted ) setGatewayOptions( [] ); } );
        return () => { aborted = true; };
    }, [ includeTest ] );

    const filterValue = ( field ) => view.filters?.find( ( f ) => f.field === field )?.value;
    const statusFilter   = filterValue( 'status' );
    const gatewayFilter  = filterValue( 'gateway' );
    const frequencyFilter = filterValue( 'frequency' );
    const campaignFilter = filterValue( 'campaign' );
    const testFilter     = filterValue( 'is_test' );

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
        created_from: createdFrom || undefined,
        created_to:   createdTo   || undefined,
    } ), [ view, statusFilter, gatewayFilter, frequencyFilter, campaignFilter, testFilter, includeTest, createdFrom, createdTo ] );

    useEffect( () => {
        let aborted = false;
        setLoading( true );

        setFetchError( null );
        apiFetch( {
            path:  addQueryArgs( '/dono/v1/admin/donations', apiParams ),
            parse: false,
        } )
            .then( async ( res ) => {
                if ( aborted ) return;
                const items = await res.json();
                setData( Array.isArray( items ) ? items : [] );
                setTotal( parseInt( res.headers.get( 'X-WP-Total' ) || '0', 10 ) );
                setTestHidden( parseInt( res.headers.get( 'X-Dono-Test-Hidden' ) || '0', 10 ) );
            } )
            .catch( ( err ) => {
                if ( aborted ) return;
                setFetchError( err?.message || __( 'Failed to load donations.', 'dono-fundraising-platform' ) );
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
        apiFetch( { path: addQueryArgs( '/dono/v1/admin/donations/stats', statsParams ) } )
            .then( ( res ) => { if ( ! aborted ) setStats( res || null ); } )
            .catch( () => { if ( ! aborted ) setStats( null ); } );

        return () => {
            aborted = true;
        };
    }, [ apiParams ] );

    const fields = useMemo( () => [
        {
            id:            'reference',
            label:         __( 'Reference', 'dono-fundraising-platform' ),
            enableSorting: true,
            // The badge rides the reference rather than occupying a column of
            // its own: on a live-only list that column is the same value on
            // every row, and the thing worth knowing is that this particular
            // donation took no money.
            render: ( { item } ) => (
                <span className="dono-ref-cell">
                    <a className="dono-mono-link" href={ detailHref( item.reference ) } { ...rowLinkProps }>
                        { item.reference }
                    </a>
                    { item.is_test && (
                        <span className="dono-pill dono-pill--test">{ __( 'Test', 'dono-fundraising-platform' ) }</span>
                    ) }
                </span>
            ),
        },
        {
            id:    'frequency',
            label: __( 'Frequency', 'dono-fundraising-platform' ),
            // Nothing on the row said whether the money came from a standing
            // gift or a one-off, which is the first thing asked of it.
            elements: FREQUENCY_OPTIONS,
            filterBy: { operators: [ 'is' ] },
            // Not StatusBadge: "monthly" is a cadence, not a lifecycle status,
            // so it has no entry in that map and would come out grey.
            render: ( { item } ) => (
                item.frequency && item.frequency !== 'one_time'
                    ? <span className="dono-pill dono-pill--blue">{ frequencyLabel( item.frequency ) }</span>
                    : <span className="dono-pill dono-pill--gray">{ __( 'One time', 'dono-fundraising-platform' ) }</span>
            ),
        },
        {
            id:    'donor',
            label: __( 'Donor', 'dono-fundraising-platform' ),
            render: ( { item } ) => {
                const d = item.donor;
                if ( ! d ) return <span className="dono-row__sub">-</span>;
                const name = d.name || __( '(no name)', 'dono-fundraising-platform' );
                return (
                    <div className="dono-row">
                        <div className="dono-row__body">
                            <div className="dono-row__name">{ name }</div>
                            { d.email && <div className="dono-row__sub dono-row__sub--mono">{ d.email }</div> }
                        </div>
                    </div>
                );
            },
        },
        {
            id:            'amount',
            label:         __( 'Amount', 'dono-fundraising-platform' ),
            enableSorting: true,
            render: ( { item } ) => {
                const showBase =
                    item.base_amount_cents != null &&
                    item.base_currency &&
                    item.base_currency !== item.currency;
                return (
                    <span className={ `dono-amount${ item.status === 'refunded' ? ' dono-amount--strike' : '' }` }>
                        { formatAmount( item.amount_cents, item.currency ) }
                        { showBase && (
                            <span className="dono-amount__base">
                                { '≈ ' }{ formatAmount( item.base_amount_cents, item.base_currency ) }
                            </span>
                        ) }
                    </span>
                );
            },
        },
        {
            id:            'status',
            label:         __( 'Status', 'dono-fundraising-platform' ),
            elements:      STATUS_OPTIONS,
            filterBy:      { operators: [ 'is' ] },
            enableSorting: true,
            render:        ( { item } ) => <StatusBadge status={ item.status } />,
        },
        {
            id:       'gateway',
            label:    __( 'Gateway', 'dono-fundraising-platform' ),
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
            label:    __( 'Campaign', 'dono-fundraising-platform' ),
            elements: campaigns.map( ( c ) => ( { value: String( c.id ), label: c.title || `#${ c.id }` } ) ),
            filterBy: { operators: [ 'is' ] },
            render: ( { item } ) => (
                item.campaign?.title
                    ? <a className="dono-row__link" href={ campaignDetailHref( item.campaign.id ) } { ...rowLinkProps }>{ item.campaign.title }</a>
                    : <span className="dono-row__sub">-</span>
            ),
        },
        {
            id:       'is_test',
            label:    __( 'Test mode', 'dono-fundraising-platform' ),
            elements: [
                { value: 'yes', label: __( 'Test only', 'dono-fundraising-platform' ) },
                { value: 'no',  label: __( 'Live only', 'dono-fundraising-platform' ) },
            ],
            filterBy:    { operators: [ 'is' ] },
            getValue:    ( { item } ) => ( item.is_test ? 'yes' : 'no' ),
            render:      ( { item } ) => item.is_test
                ? <span className="dono-pill dono-pill--test">{ __( 'Test', 'dono-fundraising-platform' ) }</span>
                : <span className="dono-row__sub">-</span>,
        },
        {
            id:     'form',
            label:  __( 'Form', 'dono-fundraising-platform' ),
            render: ( { item } ) => (
                item.form?.title
                    ? <a className="dono-row__link" href={ formEditorHref( item.form.id ) } { ...rowLinkProps }>{ item.form.title }</a>
                    : <span className="dono-row__sub">-</span>
            ),
        },
        {
            id:            'created_at',
            label:         __( 'Created', 'dono-fundraising-platform' ),
            enableSorting: true,
            render: ( { item } ) => (
                <span className="dono-time" title={ formatDate( item.created_at ) }>
                    <span className="dono-time__rel">{ timeAgo( item.created_at ) }</span>
                    <span className="dono-time__abs">{ formatDate( item.created_at ) }</span>
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
            label:        __( 'Mark as paid', 'dono-fundraising-platform' ),
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
                    ? __( 'Mark this donation as paid? A receipt will be sent.', 'dono-fundraising-platform' )
                    : sprintf(
                        /* translators: %d: number of donations */
                        _n(
                            'Mark %d donation as paid? Receipts will be sent to each donor.',
                            'Mark %d donations as paid? Receipts will be sent to each donor.',
                            n,
                            'dono-fundraising-platform'
                        ),
                        n
                    );
                setConfirm( {
                    title:        __( 'Mark donations as paid', 'dono-fundraising-platform' ),
                    message,
                    confirmLabel: __( 'Mark as paid', 'dono-fundraising-platform' ),
                    onConfirm: async () => {
                        try {
                            await Promise.all( targets.map( ( i ) => apiFetch( {
                                path:   `/dono/v1/admin/donations/${ encodeURIComponent( i.reference ) }/mark-paid`,
                                method: 'POST',
                            } ) ) );
                            notify.success( sprintf(
                                /* translators: %d: number of donations */
                                _n( '%d donation marked paid.', '%d donations marked paid.', n, 'dono-fundraising-platform' ),
                                n
                            ) );
                        } catch ( err ) {
                            setActionError( err?.message || __( 'Could not mark one or more donations paid.', 'dono-fundraising-platform' ) );
                        } finally {
                            // In the finally, not the try: a partial failure
                            // still paid some of them and emailed their donors
                            // a receipt, and skipping the refetch left those
                            // rows reading Pending on screen.
                            refetch();
                        }
                    },
                } );
            },
        },
        {
            id:           'resend-receipt',
            label:        __( 'Resend receipt', 'dono-fundraising-platform' ),
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
                    ? __( 'Resend the receipt for this donation?', 'dono-fundraising-platform' )
                    : sprintf(
                        /* translators: %d: number of donations */
                        _n( 'Resend receipts for %d donation?', 'Resend receipts for %d donations?', n, 'dono-fundraising-platform' ),
                        n
                    );
                setConfirm( {
                    title:        __( 'Resend receipts', 'dono-fundraising-platform' ),
                    message,
                    confirmLabel: __( 'Resend', 'dono-fundraising-platform' ),
                    onConfirm: async () => {
                        try {
                            await Promise.all( targets.map( ( i ) => apiFetch( {
                                path:   `/dono/v1/admin/donations/${ encodeURIComponent( i.reference ) }/resend-receipt`,
                                method: 'POST',
                            } ) ) );
                            // Silence read as "nothing happened", so admins
                            // clicked again and donors received the same
                            // receipt twice.
                            notify.success( sprintf(
                                /* translators: %d: receipt count */
                                _n( '%d receipt resent.', '%d receipts resent.', n, 'dono-fundraising-platform' ),
                                n
                            ) );
                        } catch ( err ) {
                            setActionError( err?.message || __( 'Could not resend one or more receipts.', 'dono-fundraising-platform' ) );
                        }
                    },
                } );
            },
        },
    ], [] );

    return (
        <div>
            <div className="dono-crumbs">
                <a href={ addQueryArgs( window.location.pathname, { page: 'dono-fundraising-platform' } ) }>{ __( 'Dono', 'dono-fundraising-platform' ) }</a>
                <span className="sep">›</span>
                <span>{ __( 'Donations', 'dono-fundraising-platform' ) }</span>
            </div>
            <div className="dono-page-head">
                <div className="dono-page-head__title-row">
                    <h1>{ __( 'Donations', 'dono-fundraising-platform' ) }</h1>
                </div>
                <div className="dono-page-head__right">
                    <div className="dono-page-head__date-filters">
                        <span className="dono-page-head__date-filters-label">{ __( 'From', 'dono-fundraising-platform' ) }</span>
                        <DateField
                            value={ createdFrom }
                            onChange={ ( v ) => setCreatedFrom( v || '' ) }
                            ariaLabel={ __( 'Filter donations from', 'dono-fundraising-platform' ) }
                            placeholder={ __( 'Any', 'dono-fundraising-platform' ) }
                        />
                        <span className="dono-page-head__date-filters-label">{ __( 'To', 'dono-fundraising-platform' ) }</span>
                        <DateField
                            value={ createdTo }
                            onChange={ ( v ) => setCreatedTo( v || '' ) }
                            ariaLabel={ __( 'Filter donations to', 'dono-fundraising-platform' ) }
                            placeholder={ __( 'Any', 'dono-fundraising-platform' ) }
                        />
                        { ( createdFrom || createdTo ) && (
                            <button
                                type="button"
                                className="dono-page-head__date-filters-clear"
                                onClick={ () => { setCreatedFrom( '' ); setCreatedTo( '' ); } }
                            >
                                { __( 'Clear', 'dono-fundraising-platform' ) }
                            </button>
                        ) }
                    </div>
                    <span className="dono-page-head__meta">
                        { sprintf( /* translators: %s: number of donations */ _n( '%s donation', '%s donations', total, 'dono-fundraising-platform' ), total.toLocaleString() ) }
                    </span>
                    { /* Nothing to reveal on a site that has never taken a test
                         donation, so the control is only offered once some
                         exist, or while it is on and needs turning off. */ }
                    { ( testHidden > 0 || includeTest ) && (
                        <label className="dono-inline-toggle">
                            <Switch
                                checked={ includeTest }
                                onChange={ toggleTest }
                                label={ __( 'Show test donations', 'dono-fundraising-platform' ) }
                            />
                            <span>{ __( 'Show test donations', 'dono-fundraising-platform' ) }</span>
                        </label>
                    ) }
                    <Btn variant="primary" onClick={ () => setRecording( true ) }>
                        <Plus size={ 16 } strokeWidth={ 1.75 } />
                        { __( 'Record a donation', 'dono-fundraising-platform' ) }
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
                            __( 'Recorded as %s.', 'dono-fundraising-platform' ),
                            created?.reference || ''
                        ) );
                    } }
                />
            ) }
            { ( actionError || fetchError ) && (
                <Notice status="error" isDismissible={ false }>
                    { actionError || fetchError }
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
                            'dono-fundraising-platform'
                        ),
                        testHidden
                    ) }
                    { ' ' }
                    <Btn variant="link" onClick={ () => toggleTest( true ) }>
                        { __( 'Show them', 'dono-fundraising-platform' ) }
                    </Btn>
                </Notice>
            ) }

            <KpiStrip items={ donationKpis( stats ) } loading={ loading && ! stats } />

            { /* Driven by the figures themselves, not by the toggle: the note
                 is what an org reads to decide whether a number can be quoted,
                 so it may only appear over numbers that are actually counting
                 test donations. */ }
            { stats?.includes_test && (
                <p className="dono-list-note">
                    { __( 'Test donations are counted in the figures above and shown in the list below. These totals include money that was never actually taken, so they cannot be quoted as income.', 'dono-fundraising-platform' ) }
                </p>
            ) }

            { ! loading && total === 0 && ! view.search && ! statusFilter && ! createdFrom && ! createdTo && ! view.filters?.length ? (
                <EmptyState
                    icon={ <Coins size={ 22 } strokeWidth={ 1.75 } /> }
                    title={ __( 'No donations yet', 'dono-fundraising-platform' ) }
                    body={ __( 'Donations made through your published forms will appear here. Donors are created automatically from each completed donation.', 'dono-fundraising-platform' ) }
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

function donationKpis( stats ) {
    // Per card, not once above the strip: a single figure gets read out, quoted
    // and screenshotted on its own, and it has to carry its own disclaimer.
    const includesTest = !! stats?.includes_test;
    const testSub = includesTest ? __( 'Includes test donations', 'dono-fundraising-platform' ) : null;

    let raisedSub = testSub;
    if ( stats?.currency ) {
        raisedSub = includesTest
            ? sprintf(
                /* translators: %s: currency code */
                __( 'in %s, includes test donations', 'dono-fundraising-platform' ),
                stats.currency
            )
            : sprintf( /* translators: %s: currency code */ __( 'in %s', 'dono-fundraising-platform' ), stats.currency );
    }

    return [
        {
            label: __( 'Total donations', 'dono-fundraising-platform' ),
            value: stats ? stats.total_count.toLocaleString() : '-',
            sub:   testSub,
        },
        {
            label: __( 'Paid', 'dono-fundraising-platform' ),
            value: stats ? stats.paid_count.toLocaleString() : '-',
            sub:   testSub,
        },
        {
            label: __( 'Raised', 'dono-fundraising-platform' ),
            value: stats
                ? formatAmount( stats.raised_cents, stats.currency || undefined )
                : '-',
            sub: raisedSub,
        },
        {
            label: __( 'Unique donors', 'dono-fundraising-platform' ),
            value: stats ? stats.donors_count.toLocaleString() : '-',
            sub:   testSub,
        },
    ];
}

