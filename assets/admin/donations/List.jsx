// Donations list: paginated DataViews against /gratora/v1/admin/donations.

import { useState, useEffect, useMemo } from '@wordpress/element';
import { DataViews } from '@wordpress/dataviews';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Mail as MailIcon, Check as CheckIcon, Coins, Plus, SearchX, Trash2, FlameKindling } from 'lucide-react';

import Btn from '../_shared/components/Btn';
import { useTableView } from '../_shared/useTableView';
import Notice from '../_shared/components/Notice';
import RecordDonationDrawer from './RecordDonationDrawer';
import DateField from '../_shared/components/DateField';
import EmptyState from '../_shared/components/EmptyState';
import { isViewFiltered, clearedView } from '../_shared/viewFilters';
import ConfirmDialog from '../_shared/components/ConfirmDialog';
import { dashboardHref } from '../_shared/adminPages';
import notify from '../_shared/notify';
import { userCan } from '../_shared/caps';
import KpiStrip from '../_shared/components/KpiStrip';
import { Switch } from '../_shared/components/Switch';
import { formatAmount, STATUS_LABEL } from './format';
import { donationFields } from './fields';
import { postBatch } from './trashActions';
import ViewSwitch from './ViewSwitch';

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
const TEST_PREF = 'gratora.donations.includeTest';

const readTestPref = () => {
    // A link that asked for them outranks the standing preference: the
    // dashboard's "failed test donations" item is the route to rows this
    // screen hides by default, and it is now the only one.
    if ( new URLSearchParams( window.location.search ).get( 'include_test' ) === '1' ) {
        return true;
    }

    try {
        return window.localStorage?.getItem( TEST_PREF ) === '1';
    } catch ( e ) {
        return false;
    }
};

/**
 * What the bin is about to do, said differently once it can hold money.
 *
 * "Attempt" was the only thing it ever took, and a settled donation is not
 * one. The totals sentence changes with it: the campaign figures and the
 * exports are untouched, but the summary above the list is scoped to the list
 * and so leaves out whatever is in the bin.
 */
function trashMessage( n, anySettled ) {
    if ( anySettled ) {
        return n === 1
            ? __( 'Take this donation off the list? Nothing is deleted, and your campaign totals, reports and exports do not change. The summary above this list leaves out what is in the bin, so it will read lower.', 'gratora-donation-platform' )
            : sprintf(
                /* translators: %d: number of donations */
                _n(
                    'Take %d donation off the list? Nothing is deleted, and your campaign totals, reports and exports do not change. The summary above this list leaves out what is in the bin, so it will read lower.',
                    'Take %d donations off the list? Nothing is deleted, and your campaign totals, reports and exports do not change. The summary above this list leaves out what is in the bin, so it will read lower.',
                    n,
                    'gratora-donation-platform'
                ),
                n
            );
    }

    return n === 1
        ? __( 'Take this attempt off the list? Nothing is deleted, and no money total changes.', 'gratora-donation-platform' )
        : sprintf(
            /* translators: %d: number of donations */
            _n(
                'Take %d attempt off the list? Nothing is deleted, and no money total changes.',
                'Take %d attempts off the list? Nothing is deleted, and no money totals change.',
                n,
                'gratora-donation-platform'
            ),
            n
        );
}

export default function List() {
    const [ includeTest, setIncludeTest ] = useState( readTestPref );

    const [ view, setView, viewReady ] = useTableView( 'donations', {
        type:    'table',
        perPage: 25,
        page:    1,
        sort:    { field: 'created_at', direction: 'desc' },
        filters: initialFilters(),
        search:  '',
        // Badge test/replaced attempts on references. Keep the usually redundant form column
        // available but hidden by default.
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
        setView( ( v ) => ( { ...v, page: 1 } ) );
    };

    const [ data, setData ]       = useState( [] );
    const [ total, setTotal ]     = useState( 0 );
    const [ loading, setLoading ] = useState( true );
    const [ recording, setRecording ] = useState( false );
    const [ fetchError, setFetchError ]   = useState( null );
    // Test donations are excluded unless asked for. Saying how many were left
    // out turns a silent exclusion into a visible one: an admin who donates
    // while the org is in test mode otherwise watches it vanish.
    const [ testHidden, setTestHidden ]   = useState( 0 );
    // The bin's own size, so the switch can carry it without this screen
    // fetching the trash it is not showing.
    const [ trashedCount, setTrashedCount ] = useState( 0 );
    // Per-row reasons go in a persistent notice, not a toast: a list of six
    // references auto-dismisses before anyone can read it.
    const [ refusals, setRefusals ]       = useState( [] );
    const [ createdFrom, setCreatedFrom ] = useState( '' );
    const [ createdTo,   setCreatedTo ]   = useState( '' );

    // A date bound narrows the list from outside the view, so it puts the
    // reader back on the first page itself.
    const setDateFilter = ( set, value ) => {
        set( value || '' );
        setView( ( v ) => ( { ...v, page: 1 } ) );
    };
    const [ stats, setStats ]     = useState( null );
    const [ campaigns, setCampaigns ] = useState( [] );
    // Pending confirm dialog. Shape: { title, message, confirmLabel, isDestructive, onConfirm }.
    const [ confirm, setConfirm ] = useState( null );
    const [ gatewayOptions, setGatewayOptions ] = useState( [] );

    // Use the donations-scoped campaign picker; this screen does not require
    // campaign-management permission.
    useEffect( () => {
        let aborted = false;
        apiFetch( { path: '/gratora/v1/admin/donations/campaign-options' } )
            .then( ( res ) => { if ( ! aborted ) setCampaigns( Array.isArray( res ) ? res : [] ); } )
            .catch( ( err ) => {
                if ( aborted ) return;
                setCampaigns( [] );
                notify.error( err?.message || __( 'The campaign filter could not be loaded.', 'gratora-donation-platform' ) );
            } );
        return () => { aborted = true; };
    }, [] );

    // Gateway options come from the rows, not from the registry: a slug
    // outlives the gateway being disconnected, and the Give importer carries in
    // slugs core never registers. Refetches with the test scope so the dropdown
    // never offers an option that would return nothing.
    useEffect( () => {
        let aborted = false;
        apiFetch( { path: addQueryArgs( '/gratora/v1/admin/donations/gateway-options', {
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
        include_test: includeTest || undefined,
        created_from: createdFrom || undefined,
        created_to:   createdTo   || undefined,
    } ), [ view, statusFilter, gatewayFilter, frequencyFilter, campaignFilter, includeTest, createdFrom, createdTo ] );

    useEffect( () => {
        // Nothing until the saved view lands: fetching under the screen's
        // defaults first spends a request on rows the reader's own sort is
        // about to replace.
        if ( ! viewReady ) {
            return undefined;
        }

        let aborted = false;
        setLoading( true );

        setFetchError( null );
        apiFetch( {
            path:  addQueryArgs( '/gratora/v1/admin/donations', apiParams ),
            parse: false,
        } )
            .then( async ( res ) => {
                if ( aborted ) return;
                const items = await res.json();
                setData( Array.isArray( items ) ? items : [] );
                setTotal( parseInt( res.headers.get( 'X-WP-Total' ) || '0', 10 ) );
                setTestHidden( parseInt( res.headers.get( 'X-Gratora-Test-Hidden' ) || '0', 10 ) );
                setTrashedCount( parseInt( res.headers.get( 'X-Gratora-Trashed' ) || '0', 10 ) );
            } )
            .catch( ( err ) => {
                if ( aborted ) return;
                setFetchError( err?.message || __( 'Failed to load donations.', 'gratora-donation-platform' ) );
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
        apiFetch( { path: addQueryArgs( '/gratora/v1/admin/donations/stats', statsParams ) } )
            .then( ( res ) => { if ( ! aborted ) setStats( res || null ); } )
            .catch( () => { if ( ! aborted ) setStats( null ); } );

        return () => {
            aborted = true;
        };
    }, [ apiParams, viewReady ] );

    const fields = useMemo( () => {
        const all = donationFields( { campaigns, gatewayOptions } );
        // Everything but the bin's own columns, which say nothing on a list
        // that never shows a trashed row.
        const show = [ 'reference', 'frequency', 'donor', 'amount', 'status', 'gateway', 'campaign', 'form', 'created_at' ];

        return show.map( ( id ) => all.find( ( f ) => f.id === id ) ).filter( Boolean );
    }, [ campaigns, gatewayOptions ] );

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
            label:        __( 'Mark as paid', 'gratora-donation-platform' ),
            icon:         () => <CheckIcon size={ 16 } strokeWidth={ 1.75 } />,
            supportsBulk: true,
            // Pending and still-settling donations can be flipped to paid;
            // failed ones use the per-row detail action (which captures a
            // reason). A bank debit sits in processing until it lands, and an
            // admin reconciling a statement is often the first to know.
            isEligible:   ( item ) => userCan( 'refund_donations' )
                && ( item.status === 'pending' || item.status === 'processing' ),
            callback: ( items ) => {
                const targets = items.filter( ( i ) => i.status === 'pending' || i.status === 'processing' );
                if ( ! targets.length ) return;
                const n = targets.length;
                const message = n === 1
                    ? __( 'Mark this donation as paid? A receipt will be sent.', 'gratora-donation-platform' )
                    : sprintf(
                        /* translators: %d: number of donations */
                        _n(
                            'Mark %d donation as paid? Receipts will be sent to each donor.',
                            'Mark %d donations as paid? Receipts will be sent to each donor.',
                            n,
                            'gratora-donation-platform'
                        ),
                        n
                    );
                setConfirm( {
                    title:        __( 'Mark donations as paid', 'gratora-donation-platform' ),
                    message,
                    confirmLabel: __( 'Mark as paid', 'gratora-donation-platform' ),
                    onConfirm: async () => {
                        // allSettled, and the refetch outside the counts: a
                        // partial failure still paid some of them and emailed
                        // their donors a receipt, and a batch reported as a
                        // single failure leaves those rows reading Pending.
                        const results = await Promise.allSettled( targets.map( ( i ) => apiFetch( {
                            path:   `/gratora/v1/admin/donations/${ encodeURIComponent( i.reference ) }/mark-paid`,
                            method: 'POST',
                        } ) ) );

                        const done   = results.filter( ( r ) => r.status === 'fulfilled' ).length;
                        const failed = results.length - done;

                        if ( done > 0 ) {
                            notify.success( sprintf(
                                /* translators: %d: number of donations */
                                _n( '%d donation marked paid.', '%d donations marked paid.', done, 'gratora-donation-platform' ),
                                done
                            ) );
                        }
                        if ( failed > 0 ) {
                            notify.error( sprintf(
                                /* translators: %d: number of donations */
                                _n( '%d donation could not be marked paid.', '%d donations could not be marked paid.', failed, 'gratora-donation-platform' ),
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
            label:        __( 'Resend receipt', 'gratora-donation-platform' ),
            icon:         () => <MailIcon size={ 16 } strokeWidth={ 1.75 } />,
            supportsBulk: true,
            // Only paid donations have a receipt to resend, and an erased donor
            // has no address left to send it to.
            isEligible:   ( item ) => userCan( 'resend_receipt' )
                && item.status === 'paid' && ! item.donor?.redacted,
            callback: ( items ) => {
                const targets = items.filter( ( i ) => i.status === 'paid' && ! i.donor?.redacted );
                if ( ! targets.length ) return;
                const n = targets.length;
                const message = n === 1
                    ? __( 'Resend the receipt for this donation?', 'gratora-donation-platform' )
                    : sprintf(
                        /* translators: %d: number of donations */
                        _n( 'Resend receipts for %d donation?', 'Resend receipts for %d donations?', n, 'gratora-donation-platform' ),
                        n
                    );
                setConfirm( {
                    title:        __( 'Resend receipts', 'gratora-donation-platform' ),
                    message,
                    confirmLabel: __( 'Resend', 'gratora-donation-platform' ),
                    onConfirm: async () => {
                        // Counted separately: a batch reported as a single
                        // failure reads as nothing having happened, so admins
                        // press it again and every donor whose receipt did go
                        // out receives it twice.
                        const results = await Promise.allSettled( targets.map( ( i ) => apiFetch( {
                            path:   `/gratora/v1/admin/donations/${ encodeURIComponent( i.reference ) }/resend-receipt`,
                            method: 'POST',
                        } ) ) );

                        const sent   = results.filter( ( r ) => r.status === 'fulfilled' ).length;
                        const failed = results.length - sent;

                        if ( sent > 0 ) {
                            notify.success( sprintf(
                                /* translators: %d: receipt count */
                                _n( '%d receipt resent.', '%d receipts resent.', sent, 'gratora-donation-platform' ),
                                sent
                            ) );
                        }
                        if ( failed > 0 ) {
                            notify.error( sprintf(
                                /* translators: %d: receipt count */
                                _n( '%d receipt could not be resent.', '%d receipts could not be resent.', failed, 'gratora-donation-platform' ),
                                failed
                            ) );
                        }
                    },
                } );
            },
        },
        {
            id:            'trash',
            label:         __( 'Move to trash', 'gratora-donation-platform' ),
            icon:          () => <Trash2 size={ 16 } strokeWidth={ 1.75 } />,
            isDestructive: true,
            supportsBulk:  true,
            isEligible:    ( item ) => userCan( 'refund_donations' ) && !! item.trashable,
            callback: ( items ) => {
                // DataViews hands the callback the whole selection rather than
                // the eligible part of it, so the filter is repeated here.
                const targets = items.filter( ( i ) => i.trashable );
                if ( ! targets.length ) return;
                const n        = targets.length;
                const stopping = targets.filter( ( i ) => i.stops_payment ).length;
                // The bin takes settled donations now, so "attempt" stops
                // being the right word for what is in the selection, and the
                // note about a reference that could still be paid by hand is
                // about an open payment, which these do not have.
                const settled = targets.filter( ( i ) => i.status === 'paid'
                    || i.status === 'partial_refund'
                    || i.status === 'refunded'
                    || i.status === 'disputed' ).length;
                const anySettled = settled > 0;

                setConfirm( {
                    title:       __( 'Move to trash', 'gratora-donation-platform' ),
                    destructive: true,
                    message: trashMessage( n, anySettled ),
                    // The row cannot know the outcome in advance, so the dialog
                    // says what will be attempted and the result says what
                    // happened.
                    body: (
                        <p className="gratora-list-note" style={ { marginBottom: 0 } }>
                            { stopping > 0
                                ? sprintf(
                                    /* translators: %d: number of donations whose payment will be stopped */
                                    _n(
                                        'Gratora will ask the gateway to stop %d payment that is still open.',
                                        'Gratora will ask the gateway to stop %d payments that are still open.',
                                        stopping,
                                        'gratora-donation-platform'
                                    ),
                                    stopping
                                )
                                : ( anySettled
                                    ? __( 'Nothing is open at the gateway for these: the money has already moved and the bin does not touch it.', 'gratora-donation-platform' )
                                    : __( 'There is nothing to close at the gateway for these, so a reference already emailed to a donor could still be paid by hand.', 'gratora-donation-platform' ) ) }
                        </p>
                    ),
                    confirmLabel: __( 'Move to trash', 'gratora-donation-platform' ),
                    onConfirm: async () => {
                        let result;
                        try {
                            result = await postBatch( 'trash', targets.map( ( i ) => i.reference ) );
                        } catch ( err ) {
                            // Sent in chunks, so earlier ones may have trashed
                            // rows this list is still showing.
                            notify.error( err?.message || __( 'Could not move to the trash. Refresh and try again.', 'gratora-donation-platform' ) );
                            refetch();
                            return;
                        }

                        const done = result.done.length + result.already.length;

                        if ( done > 0 ) {
                            // Restore, not Undo: the payment stays stopped, and
                            // Undo promises otherwise. The duration is explicit
                            // because the success default is far shorter.
                            notify.success(
                                sprintf(
                                    /* translators: %d: number of donations */
                                    _n( '%d donation moved to the trash.', '%d donations moved to the trash.', done, 'gratora-donation-platform' ),
                                    done
                                ),
                                {
                                    duration: 9000,
                                    action:   {
                                        label:   __( 'Restore', 'gratora-donation-platform' ),
                                        onClick: async () => {
                                            try {
                                                await postBatch( 'restore', result.done.map( ( d ) => d.reference ) );
                                                notify.success( __( 'Restored. The payment stays stopped.', 'gratora-donation-platform' ) );
                                            } catch ( err ) {
                                                notify.error( err?.message || __( 'Could not restore. Refresh and try again.', 'gratora-donation-platform' ) );
                                            }
                                            refetch();
                                        },
                                    },
                                }
                            );
                        }

                        setRefusals( result.refused );
                        if ( result.refused.length > 0 ) {
                            notify.error( sprintf(
                                /* translators: %d: number of donations */
                                _n( '%d donation could not be moved.', '%d donations could not be moved.', result.refused.length, 'gratora-donation-platform' ),
                                result.refused.length
                            ) );
                        }

                        refetch();
                    },
                } );
            },
        },
        {
            id:            'delete-permanently',
            label:         __( 'Delete permanently', 'gratora-donation-platform' ),
            // Deliberately not the bin: that one is reversible and this is not.
            icon:          () => <FlameKindling size={ 16 } strokeWidth={ 1.75 } />,
            isDestructive: true,
            // One row at a time here. Deleting in bulk belongs to the trash,
            // where every row has already been through a decision to remove it.
            // Only for a row with no bin to pass through. Anything that can be
            // trashed goes that way first, because trashing is what stops its
            // payment, and a reference removed without that could still be
            // paid by hand.
            //
            // A row the server refuses is offered it too, because the refusal
            // names what to do about it and this menu is the only place that
            // sentence can be read. Dropping the action instead leaves a row
            // that looks like the feature was never built. A row with no
            // reason is not offered it: that one wants the bin, and Trash is
            // already sitting beside it.
            isEligible:    ( item ) => userCan( 'delete_donations' )
                && ! item.trashed
                && ( !! item.deletable || !! item.delete_blocked ),
            callback: ( items ) => {
                const blocked = items.filter( ( i ) => ! i.deletable && !! i.delete_blocked );
                if ( blocked.length ) {
                    setRefusals( blocked.map( ( i ) => ( {
                        reference: i.reference,
                        reason:    i.delete_blocked,
                    } ) ) );
                }

                const targets = items.filter( ( i ) => !! i.deletable && ! i.trashed );
                if ( ! targets.length ) return;
                const n = targets.length;

                setConfirm( {
                    title:       __( 'Delete permanently', 'gratora-donation-platform' ),
                    destructive: true,
                    requireText: 'DELETE',
                    message: n === 1
                        ? __( 'This removes the donation and everything describing it. Money already recorded against it comes out of your totals. It cannot be undone.', 'gratora-donation-platform' )
                        : sprintf(
                            /* translators: %d: number of donations */
                            _n(
                                'This removes %d donation and everything describing it. Money already recorded against them comes out of your totals. It cannot be undone.',
                                'This removes %d donations and everything describing them. Money already recorded against them comes out of your totals. It cannot be undone.',
                                n,
                                'gratora-donation-platform'
                            ),
                            n
                        ),
                    confirmLabel: __( 'Delete permanently', 'gratora-donation-platform' ),
                    busyLabel:    __( 'Deleting…', 'gratora-donation-platform' ),
                    onConfirm: async ( onProgress ) => {
                        try {
                            const result = await postBatch( 'delete', targets.map( ( i ) => i.reference ), {
                                confirmation:  'DELETE',
                                // The donor is kept: these rows have money on
                                // them, so the donor is never left with nothing.
                                delete_donors: false,
                            }, onProgress );

                            const done = result.done.length + result.already.length;
                            if ( done > 0 ) {
                                notify.success( sprintf(
                                    /* translators: %d: number of donations */
                                    _n( '%d donation deleted.', '%d donations deleted.', done, 'gratora-donation-platform' ),
                                    done
                                ) );
                            }

                            setRefusals( result.refused );
                            if ( result.refused.length > 0 ) {
                                notify.error( sprintf(
                                    /* translators: %d: number of donations */
                                    _n( '%d donation could not be deleted.', '%d donations could not be deleted.', result.refused.length, 'gratora-donation-platform' ),
                                    result.refused.length
                                ) );
                            }
                        } catch ( err ) {
                            notify.error( err?.message || __( 'Could not delete. Refresh and try again.', 'gratora-donation-platform' ) );
                        }

                        refetch();
                    },
                } );
            },
        },
    ], [] );

    return (
        <div>
            <div className="gratora-crumbs">
                <a href={ dashboardHref( window.location.pathname ) }>{ __( 'Fundraising', 'gratora-donation-platform' ) }</a>
                <span className="sep">›</span>
                <span>{ __( 'Donations', 'gratora-donation-platform' ) }</span>
            </div>
            <div className="gratora-page-head">
                <div className="gratora-page-head__title-row">
                    <h1>{ __( 'Donations', 'gratora-donation-platform' ) }</h1>
                </div>
                <div className="gratora-page-head__right">
                    <ViewSwitch active="list" trashedCount={ trashedCount } />
                    <div className="gratora-page-head__date-filters">
                        <span className="gratora-page-head__date-filters-label">{ __( 'From', 'gratora-donation-platform' ) }</span>
                        <DateField
                            value={ createdFrom }
                            onChange={ ( v ) => setDateFilter( setCreatedFrom, v ) }
                            ariaLabel={ __( 'Filter donations from', 'gratora-donation-platform' ) }
                            placeholder={ __( 'Any', 'gratora-donation-platform' ) }
                        />
                        <span className="gratora-page-head__date-filters-label">{ __( 'To', 'gratora-donation-platform' ) }</span>
                        <DateField
                            value={ createdTo }
                            onChange={ ( v ) => setDateFilter( setCreatedTo, v ) }
                            ariaLabel={ __( 'Filter donations to', 'gratora-donation-platform' ) }
                            placeholder={ __( 'Any', 'gratora-donation-platform' ) }
                        />
                        { ( createdFrom || createdTo ) && (
                            <button
                                type="button"
                                className="gratora-page-head__date-filters-clear"
                                onClick={ () => { setCreatedFrom( '' ); setCreatedTo( '' ); } }
                            >
                                { __( 'Clear', 'gratora-donation-platform' ) }
                            </button>
                        ) }
                    </div>
                    <span className="gratora-page-head__meta">
                        { sprintf( /* translators: %s: number of donations */ _n( '%s donation', '%s donations', total, 'gratora-donation-platform' ), total.toLocaleString() ) }
                    </span>
                    { /* Nothing to reveal on a site that has never taken a test
                         donation, so the control is only offered once some
                         exist, or while it is on and needs turning off. */ }
                    { ( testHidden > 0 || includeTest ) && (
                        <label className="gratora-inline-toggle">
                            <Switch
                                checked={ includeTest }
                                onChange={ toggleTest }
                                label={ __( 'Show test donations', 'gratora-donation-platform' ) }
                            />
                            <span>{ __( 'Show test donations', 'gratora-donation-platform' ) }</span>
                        </label>
                    ) }
                    { userCan( 'refund_donations' ) && (
                        <Btn variant="primary" onClick={ () => setRecording( true ) }>
                            <Plus size={ 16 } strokeWidth={ 1.75 } />
                            { __( 'Record a donation', 'gratora-donation-platform' ) }
                        </Btn>
                    ) }
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
                            __( 'Recorded as %s.', 'gratora-donation-platform' ),
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

            { refusals.length > 0 && (
                <Notice status="warning" onRemove={ () => setRefusals( [] ) }>
                    <ul style={ { margin: 0, paddingLeft: 18 } }>
                        { refusals.map( ( r ) => (
                            <li key={ r.reference }>
                                <code>{ r.reference }</code>{ ': ' }{ r.reason }
                            </li>
                        ) ) }
                    </ul>
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
                            'gratora-donation-platform'
                        ),
                        testHidden
                    ) }
                    { ' ' }
                    <Btn variant="link" onClick={ () => toggleTest( true ) }>
                        { __( 'Show them', 'gratora-donation-platform' ) }
                    </Btn>
                </Notice>
            ) }

            <KpiStrip items={ donationKpis( stats ) } loading={ loading && ! stats } />

            { /* Driven by the figures themselves, not by the toggle: the note
                 is what an org reads to decide whether a number can be quoted,
                 so it may only appear over numbers that are actually counting
                 test donations. */ }
            { stats?.includes_test && (
                <p className="gratora-list-note">
                    { __( 'Test donations are counted in the figures above and shown in the list below. These totals include money that was never actually taken, so they cannot be quoted as income.', 'gratora-donation-platform' ) }
                </p>
            ) }

            { ! loading && total === 0 && ! filtered ? (
                <EmptyState
                    icon={ <Coins size={ 22 } strokeWidth={ 1.75 } /> }
                    title={ __( 'No donations yet', 'gratora-donation-platform' ) }
                    body={ __( 'Donations made through your published forms will appear here. Donors are created automatically from each completed donation.', 'gratora-donation-platform' ) }
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

export function donationKpis( stats ) {
    // Per card, not once above the strip: a single figure gets read out, quoted
    // and screenshotted on its own, and it has to carry its own disclaimer.
    const includesTest = !! stats?.includes_test;
    const testSub = includesTest ? __( 'Includes test donations', 'gratora-donation-platform' ) : null;

    let raisedSub = testSub;
    if ( stats?.currency ) {
        raisedSub = includesTest
            ? sprintf(
                /* translators: %s: currency code */
                __( 'in %s, includes test donations', 'gratora-donation-platform' ),
                stats.currency
            )
            : sprintf( /* translators: %s: currency code */ __( 'in %s', 'gratora-donation-platform' ), stats.currency );
    }

    return [
        {
            label: __( 'Total donations', 'gratora-donation-platform' ),
            value: stats ? String( stats.total_count ) : '-',
            sub:   testSub,
        },
        {
            label: __( 'Paid', 'gratora-donation-platform' ),
            value: stats ? String( stats.paid_count ) : '-',
            sub:   testSub,
        },
        {
            label: __( 'Raised', 'gratora-donation-platform' ),
            value: stats
                ? formatAmount( stats.raised_cents, stats.currency || undefined )
                : '-',
            sub: raisedSub,
        },
        {
            label: __( 'Unique donors', 'gratora-donation-platform' ),
            value: stats ? String( stats.donors_count ) : '-',
            sub:   testSub,
        },
    ];
}

