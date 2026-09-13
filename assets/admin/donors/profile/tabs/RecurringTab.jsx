import { __, _n, sprintf } from '@wordpress/i18n';
import { useMemo, useState } from '@wordpress/element';
import { addQueryArgs } from '@wordpress/url';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import { RotateCw } from 'lucide-react';

import EmptyState from '../../../_shared/components/EmptyState';
import PlanActionDialog, { actionsFor, dueIn, isTerminal, retryActionFor } from '../../../_shared/recurring/PlanActions';
import PlanDetailDialog from '../../../subscriptions/PlanDetailDialog';
import {
    cadenceLabel,
    renderHealth,
    viewDetailsAction,
    copySubscriptionIdAction,
} from '../../../_shared/recurring/planColumns';
import { stopRowSelect } from '../../../_shared/rowLink';
import { formatAmount, formatDateTime, planStatusPill } from '../helpers';

function subscriptionHref( id ) {
    return addQueryArgs( window.location.pathname, { page: 'gratora-subscriptions' } ) + `#subscription/${ id }`;
}

export const STATUS_OPTIONS = [
    { value: 'active',    label: __( 'Active', 'gratora-donation-platform' ) },
    { value: 'past_due',  label: __( 'Past due', 'gratora-donation-platform' ) },
    { value: 'paused',    label: __( 'Paused', 'gratora-donation-platform' ) },
    { value: 'cancelled', label: __( 'Cancelled', 'gratora-donation-platform' ) },
    { value: 'expired',   label: __( 'Expired', 'gratora-donation-platform' ) },
];

export default function RecurringTab( { recurring, onChange } ) {
    // A fresh [] each render would re-sort and re-paginate on every keystroke.
    const plans = useMemo( () => recurring?.plans || [], [ recurring ] );
    const [ dialog, setDialog ] = useState( null );
    const [ detail, setDetail ] = useState( null );

    const [ view, setView ] = useState( {
        type:    'table',
        perPage: 25,
        page:    1,
        // Not next_payment_at: a cancelled plan has none, and ascending nulls
        // would sort the dead plans above the live ones.
        sort:    { field: 'status', direction: 'asc' },
        filters: [],
        search:  '',
        fields:  [ 'id', 'gateway_subscription_id', 'gateway', 'amount', 'status', 'next_payment_at', 'failed', 'lifetime' ],
    } );

    const fields = useMemo( () => [
        {
            id:    'id',
            label: __( 'ID', 'gratora-donation-platform' ),
            enableSorting: true,
            enableGlobalSearch: true,
            // Both forms, because the cell prints one and an admin reading a
            // reference has the other. The search trims and lowercases whatever
            // it is handed, so a number throws before the box filters anything.
            getValue: ( { item } ) => `#${ item.id } ${ item.id }`,
            // Sorting the string would put #10 above #9.
            sort: ( a, b, direction ) => ( direction === 'asc' ? a.id - b.id : b.id - a.id ),
            render: ( { item } ) => (
                <span className="gratora-ref-cell">
                    <a
                        className="gratora-mono-link"
                        href={ subscriptionHref( item.id ) }
                        onMouseDown={ stopRowSelect }
                        onClick={ ( e ) => { e.preventDefault(); e.stopPropagation(); setDetail( item ); } }
                    >
                        { `#${ item.id }` }
                    </a>
                    { /* Labelled because the card above deliberately leaves it
                         out of the totals. */ }
                    { item.is_test && (
                        <span className="dp-pill is-muted">{ __( 'Test', 'gratora-donation-platform' ) }</span>
                    ) }
                </span>
            ),
        },
        {
            id:    'gateway_subscription_id',
            label: __( 'Gateway subscription ID', 'gratora-donation-platform' ),
            enableSorting: true,
            enableGlobalSearch: true,
            getValue: ( { item } ) => item.gateway_subscription_id || '',
            render: ( { item } ) => ( item.gateway_subscription_id
                ? <code className="gratora-row__sub gratora-row__sub--mono">{ item.gateway_subscription_id }</code>
                : <span className="gratora-row__sub">{ __( 'Not linked', 'gratora-donation-platform' ) }</span> ),
        },
        {
            id:    'amount',
            label: __( 'Amount / interval', 'gratora-donation-platform' ),
            enableSorting: true,
            getValue: ( { item } ) => item.amount_cents,
            render: ( { item } ) => (
                <span>
                    { formatAmount( item.amount_cents, item.currency ) }
                    <span className="gratora-row__sub"> / { cadenceLabel( item ) }</span>
                </span>
            ),
        },
        {
            id:       'status',
            label:    __( 'Status', 'gratora-donation-platform' ),
            elements: STATUS_OPTIONS,
            filterBy: { operators: [ 'is' ] },
            enableSorting: true,
            render: ( { item } ) => {
                const pill = planStatusPill( item.status );
                return <span className={ `dp-pill ${ pill.cls }` }>{ pill.label }</span>;
            },
        },
        {
            id:    'next_payment_at',
            label: __( 'Next charge', 'gratora-donation-platform' ),
            enableSorting: true,
            // A cancelled plan has none, and the default comparator calls
            // localeCompare on whatever getValue returns.
            getValue: ( { item } ) => item.next_payment_at || '',
            render: ( { item } ) => ( isTerminal( item.status ) || ! item.next_payment_at )
                ? <span className="gratora-row__sub">-</span>
                : (
                    <div className="gratora-row">
                        <div className="gratora-row__body">
                            <div className="gratora-row__name">{ dueIn( item.next_payment_at ) }</div>
                            <div className="gratora-row__sub">{ formatDateTime( item.next_payment_at ) }</div>
                        </div>
                    </div>
                ),
        },
        {
            id:    'failed',
            label: __( 'Health', 'gratora-donation-platform' ),
            enableSorting: true,
            getValue: ( { item } ) => item.failed_renewals_count || 0,
            render: ( { item } ) => renderHealth( item ),
        },
        {
            id:    'gateway',
            label: __( 'Gateway', 'gratora-donation-platform' ),
            enableSorting: true,
            enableGlobalSearch: true,
            getValue: ( { item } ) => item.gateway || '',
            render: ( { item } ) => (
                <span>{ item.gateway_label || item.gateway }</span>
            ),
        },
        {
            id:    'started_at',
            label: __( 'Started', 'gratora-donation-platform' ),
            enableSorting: true,
            getValue: ( { item } ) => item.started_at || '',
            render: ( { item } ) => <span>{ item.started_at ? formatDateTime( item.started_at ) : '-' }</span>,
        },
        {
            id:    'lifetime',
            label: __( 'Lifetime', 'gratora-donation-platform' ),
            enableSorting: true,
            getValue: ( { item } ) => item.total_paid_cents,
            render: ( { item } ) => (
                <div className="gratora-row">
                    <div className="gratora-row__body">
                        <div className="gratora-row__name">{ formatAmount( item.total_paid_cents, item.currency ) }</div>
                        <div className="gratora-row__sub">
                            { sprintf(
                                /* translators: %d: number of donations */
                                _n( '%d donation', '%d donations', item.payments_count, 'gratora-donation-platform' ),
                                item.payments_count
                            ) }
                        </div>
                    </div>
                </div>
            ),
        },
    ], [] );

    const { data: rows, paginationInfo } = useMemo(
        () => filterSortAndPaginate( plans, view, fields ),
        [ plans, view, fields ]
    );

    // Same set the Subscriptions screen offers, resolved through the shared
    // PlanActions helpers so the two cannot drift apart.
    const actions = useMemo( () => [
        viewDetailsAction( setDetail ),
        copySubscriptionIdAction(),
        {
            id:    'retry',
            label: __( 'Retry payment', 'gratora-donation-platform' ),
            // Outside the menu on purpose: collecting a failed renewal is the
            // reason this row is being looked at. The icon is not decoration --
            // DataViews draws a primary action as an icon button, and one
            // without an icon renders as nothing while still being kept out of
            // the menu.
            isPrimary:  true,
            icon:       () => <RotateCw size={ 16 } strokeWidth={ 1.75 } />,
            isEligible: ( item ) => !! retryActionFor( item ),
            callback:   ( items ) => setDialog( { plan: items[ 0 ], action: 'retry' } ),
        },
        {
            id:         'pause',
            label:      __( 'Pause', 'gratora-donation-platform' ),
            isEligible: ( item ) => actionsFor( item ).some( ( a ) => a.id === 'pause' ),
            callback:   ( items ) => setDialog( { plan: items[ 0 ], action: 'pause' } ),
        },
        {
            id:         'resume',
            label:      __( 'Resume', 'gratora-donation-platform' ),
            isEligible: ( item ) => actionsFor( item ).some( ( a ) => a.id === 'resume' ),
            callback:   ( items ) => setDialog( { plan: items[ 0 ], action: 'resume' } ),
        },
        {
            id:         'skip_next',
            label:      __( 'Skip next', 'gratora-donation-platform' ),
            isEligible: ( item ) => actionsFor( item ).some( ( a ) => a.id === 'skip_next' ),
            callback:   ( items ) => setDialog( { plan: items[ 0 ], action: 'skip_next' } ),
        },
        {
            id:         'change_amount',
            label:      __( 'Change amount', 'gratora-donation-platform' ),
            isEligible: ( item ) => actionsFor( item ).some( ( a ) => a.id === 'change_amount' ),
            callback:   ( items ) => setDialog( { plan: items[ 0 ], action: 'change_amount' } ),
        },
        {
            id:            'cancel',
            label:         __( 'Cancel', 'gratora-donation-platform' ),
            isDestructive: true,
            isEligible:    ( item ) => actionsFor( item ).some( ( a ) => a.id === 'cancel' ),
            callback:      ( items ) => setDialog( { plan: items[ 0 ], action: 'cancel' } ),
        },
    ], [] );

    if ( plans.length === 0 ) {
        return (
            <div className="dp-card">
                <EmptyState
                    compact
                    title={ __( 'No subscriptions on file', 'gratora-donation-platform' ) }
                    body={ __( 'Recurring plans appear here once this donor sets one up on a form that offers it.', 'gratora-donation-platform' ) }
                />
            </div>
        );
    }

    return (
        <div className="gratora-dataviews dp-recurring-dv">
            <DataViews
                data={ rows }
                isLoading={ false }
                fields={ fields }
                view={ view }
                onChangeView={ setView }
                actions={ actions }
                paginationInfo={ paginationInfo }
                defaultLayouts={ { table: {} } }
                getItemId={ ( item ) => String( item.id ) }
                searchLabel={ __( 'Search by subscription ID', 'gratora-donation-platform' ) }
            />

            { dialog && (
                <PlanActionDialog
                    plan={ dialog.plan }
                    action={ dialog.action }
                    onClose={ () => setDialog( null ) }
                    // The plan row, the donor's counters and the activity list
                    // all move together, so the whole profile is refetched.
                    onDone={ onChange }
                />
            ) }

            { detail && (
                <PlanDetailDialog
                    plan={ detail }
                    onClose={ () => setDetail( null ) }
                    onAction={ ( action ) => { setDialog( { plan: detail, action } ); setDetail( null ); } }
                />
            ) }
        </div>
    );
}
