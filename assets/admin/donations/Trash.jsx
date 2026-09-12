// Donations: the Trash view, against /gratora/v1/admin/donations?trashed=only.
//
// Its own useTableView instance rather than a scope switch on the list's: that
// hook merges saved state onto current state, so one shared instance would let
// this screen's trashed_at column rewrite the live list's saved column order.

import { useState, useEffect, useMemo, useRef } from '@wordpress/element';
import { DataViews } from '@wordpress/dataviews';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Trash2, SearchX, Undo2, FlameKindling } from 'lucide-react';

import Btn from '../_shared/components/Btn';
import Notice from '../_shared/components/Notice';
import EmptyState from '../_shared/components/EmptyState';
import ConfirmDialog from '../_shared/components/ConfirmDialog';
import { useTableView } from '../_shared/useTableView';
import { isViewFiltered, clearedView } from '../_shared/viewFilters';
import { dashboardHref } from '../_shared/adminPages';
import notify from '../_shared/notify';
import { userCan } from '../_shared/caps';
import { donationFields } from './fields';
import { postBatch } from './trashActions';
import ViewSwitch from './ViewSwitch';

export default function Trash() {
    const fields = useMemo( () => {
        const all = donationFields();
        const show = [ 'reference', 'status', 'donor', 'amount', 'campaign', 'trashed_at', 'trashed_by_name' ];

        return show.map( ( id ) => all.find( ( f ) => f.id === id ) ).filter( Boolean );
    }, [] );

    const [ view, setView, viewReady ] = useTableView( 'donations-trash', {
        type:    'table',
        perPage: 25,
        page:    1,
        sort:    { field: 'trashed_at', direction: 'desc' },
        filters: [],
        search:  '',
        fields:  [ 'reference', 'status', 'donor', 'amount', 'campaign', 'trashed_at', 'trashed_by_name' ],
        layout:  {
            styles: {
                reference: { width: '170px' },
                donor:     { width: '26%', minWidth: '220px' },
            },
        },
    }, () => fields.map( ( f ) => f.id ) );

    const [ data, setData ]           = useState( [] );
    const [ total, setTotal ]         = useState( 0 );
    const [ loading, setLoading ]     = useState( true );
    const [ fetchError, setFetchError ] = useState( null );
    const [ confirm, setConfirm ]     = useState( null );
    // Per-row reasons live in a persistent notice rather than a toast: a list
    // of six references auto-dismisses before anyone can read it.
    const [ refusals, setRefusals ]   = useState( [] );

    const filtered = isViewFiltered( view, [] );
    const refetch  = () => setView( ( v ) => ( { ...v } ) );

    const apiParams = useMemo( () => ( {
        trashed:      'only',
        page:         view.page,
        per_page:     view.perPage,
        orderby:      view.sort?.field === 'amount' ? 'amount_cents' : ( view.sort?.field || 'created_at' ),
        order:        view.sort?.direction || 'desc',
        search:       view.search || undefined,
        include_test: true,
    } ), [ view ] );

    useEffect( () => {
        if ( ! viewReady ) return undefined;

        let aborted = false;
        setLoading( true );
        setFetchError( null );

        apiFetch( { path: addQueryArgs( '/gratora/v1/admin/donations', apiParams ), parse: false } )
            .then( async ( res ) => {
                if ( aborted ) return;
                const items = await res.json();
                setData( Array.isArray( items ) ? items : [] );
                setTotal( parseInt( res.headers.get( 'X-WP-Total' ) || '0', 10 ) );
            } )
            .catch( ( err ) => {
                if ( aborted ) return;
                setFetchError( err?.message || __( 'Failed to load the trash.', 'gratora-donation-platform' ) );
                setData( [] );
                setTotal( 0 );
            } )
            .finally( () => ! aborted && setLoading( false ) );

        return () => { aborted = true; };
    }, [ apiParams, viewReady ] );

    const report = ( result, doneMessage, refusedMessage ) => {
        const done = result.done.length + result.already.length;
        if ( done > 0 ) {
            notify.success( doneMessage( done ) );
        }
        setRefusals( result.refused );
        if ( result.refused.length > 0 ) {
            notify.error( refusedMessage( result.refused.length ) );
        }
        refetch();
    };

    // A ref, not state: the dialog's onConfirm is stored when the action fires,
    // so a captured value would send whatever the checkbox said at that moment
    // and quietly ignore the admin unticking it afterwards.
    const deleteDonors = useRef( true );

    const actions = useMemo( () => [
        {
            id:           'restore',
            label:        __( 'Restore', 'gratora-donation-platform' ),
            // Without an icon the bulk bar drops it: DataViews draws those
            // buttons icon-only, which is why selecting rows in here offered
            // nothing to press.
            icon:         () => <Undo2 size={ 16 } strokeWidth={ 1.75 } />,
            supportsBulk: true,
            // Deliberately no isEligible: DataViews drops an ineligible action
            // from the row menu entirely, which would leave a bin row with no
            // buttons and nothing saying why.
            callback: async ( items ) => {
                try {
                    const result = await postBatch( 'restore', items.map( ( i ) => i.reference ) );
                    report(
                        result,
                        ( n ) => {
                            // Only the rows that actually had a payment closed:
                            // the bin takes settled donations now, and nothing
                            // was ever stopped on those.
                            const stopped = result.done.filter( ( r ) => r.payment_stopped ).length;

                            return stopped === 0
                                ? sprintf(
                                    /* translators: %d: number of donations */
                                    _n( '%d donation restored.', '%d donations restored.', n, 'gratora-donation-platform' ),
                                    n
                                )
                                : sprintf(
                                    /* translators: %d: number of donations */
                                    _n( '%d donation restored. Its payment is still stopped.', '%d donations restored. Their payments are still stopped.', stopped, 'gratora-donation-platform' ),
                                    n
                                );
                        },
                        ( n ) => sprintf(
                            /* translators: %d: number of donations */
                            _n( '%d donation could not be restored.', '%d donations could not be restored.', n, 'gratora-donation-platform' ),
                            n
                        )
                    );
                } catch ( err ) {
                    // Refetched anyway: the batch is sent in chunks, so earlier
                    // ones may have restored rows this screen is still showing.
                    notify.error( err?.message || __( 'Could not restore. Refresh and try again.', 'gratora-donation-platform' ) );
                    refetch();
                }
            },
        },
        {
            id:            'delete-permanently',
            label:         __( 'Delete permanently', 'gratora-donation-platform' ),
            icon:          () => <FlameKindling size={ 16 } strokeWidth={ 1.75 } />,
            isDestructive: true,
            supportsBulk:  true,
            isEligible:    ( item ) => userCan( 'delete_donations' ) && ! item.delete_blocked,
            callback: ( items ) => {
                const targets = items.filter( ( i ) => ! i.delete_blocked );
                if ( ! targets.length ) return;
                const n = targets.length;

                deleteDonors.current = true;
                setConfirm( {
                    title:       __( 'Delete permanently', 'gratora-donation-platform' ),
                    destructive: true,
                    requireText: 'DELETE',
                    message: n === 1
                        ? __( 'This removes the donation and everything describing it. It cannot be undone.', 'gratora-donation-platform' )
                        : sprintf(
                            /* translators: %d: number of donations */
                            _n(
                                'This removes %d donation and everything describing it. It cannot be undone.',
                                'This removes %d donations and everything describing them. It cannot be undone.',
                                n,
                                'gratora-donation-platform'
                            ),
                            n
                        ),
                    body: (
                        <label className="gratora-inline-toggle" style={ { marginTop: 12, display: 'block' } }>
                            <input
                                type="checkbox"
                                defaultChecked
                                onChange={ ( e ) => { deleteDonors.current = e.target.checked; } }
                            />
                            { ' ' }
                            { __( 'Also delete donors who are left with nothing', 'gratora-donation-platform' ) }
                        </label>
                    ),
                    confirmLabel: __( 'Delete permanently', 'gratora-donation-platform' ),
                    onConfirm: async () => {
                        try {
                            const result = await postBatch( 'delete', targets.map( ( i ) => i.reference ), {
                                confirmation:  'DELETE',
                                delete_donors: deleteDonors.current,
                            } );
                            report(
                                result,
                                ( count ) => sprintf(
                                    /* translators: %d: number of donations */
                                    _n( '%d donation deleted.', '%d donations deleted.', count, 'gratora-donation-platform' ),
                                    count
                                ),
                                ( count ) => sprintf(
                                    /* translators: %d: number of donations */
                                    _n( '%d donation could not be deleted.', '%d donations could not be deleted.', count, 'gratora-donation-platform' ),
                                    count
                                )
                            );
                        } catch ( err ) {
                            // The dialog has already closed, so without this the
                            // screen answers an irreversible action with silence.
                            notify.error( err?.message || __( 'Could not delete. Refresh and try again.', 'gratora-donation-platform' ) );
                            refetch();
                        }
                    },
                } );
            },
        },
    ], [] );

    const paginationInfo = useMemo( () => ( {
        totalItems: total,
        totalPages: Math.max( 1, Math.ceil( total / view.perPage ) ),
    } ), [ total, view.perPage ] );

    return (
        <div>
            <div className="gratora-crumbs">
                <a href={ dashboardHref( window.location.pathname ) }>{ __( 'Fundraising', 'gratora-donation-platform' ) }</a>
                <span className="sep">›</span>
                <span>{ __( 'Donations', 'gratora-donation-platform' ) }</span>
            </div>

            <div className="gratora-page-head">
                <div className="gratora-page-head__title-row">
                    <h1>{ __( 'Trash', 'gratora-donation-platform' ) }</h1>
                </div>
                <div className="gratora-page-head__right">
                    <ViewSwitch active="trash" trashedCount={ total } />
                </div>
            </div>

            <Notice status="info" isDismissible={ false }>
                { __( 'Nothing here has been deleted, and nothing is ever deleted on a schedule. No money total, report or export changes: a donation goes on counting from in here, because the bin holds a decision to remove something rather than a change to your books. Deleting is what moves them.', 'gratora-donation-platform' ) }
            </Notice>

            { fetchError && (
                <Notice status="error" isDismissible={ false }>{ fetchError }</Notice>
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

            { ! loading && total === 0 && ! filtered ? (
                <EmptyState
                    icon={ <Trash2 size={ 22 } strokeWidth={ 1.75 } /> }
                    title={ __( 'The trash is empty', 'gratora-donation-platform' ) }
                    body={ __( 'Attempts you take off the donations list appear here, with their payments stopped, until you delete them.', 'gratora-donation-platform' ) }
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
                        defaultLayouts={ { table: {} } }
                        getItemId={ ( item ) => String( item.id ) }
                    />

                    { ! loading && data.length === 0 && filtered && (
                        <EmptyState
                            compact
                            icon={ <SearchX size={ 22 } strokeWidth={ 1.75 } /> }
                            title={ __( 'Nothing matches these filters', 'gratora-donation-platform' ) }
                            body={ __( 'Try a different search, or clear the filters to see everything again.', 'gratora-donation-platform' ) }
                            action={
                                <Btn variant="secondary" onClick={ () => setView( clearedView( view ) ) }>
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
