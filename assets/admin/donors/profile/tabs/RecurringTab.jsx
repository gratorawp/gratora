import { __, _n, sprintf } from '@wordpress/i18n';
import { useMemo, useState } from '@wordpress/element';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import { RotateCw } from 'lucide-react';

import EmptyState from '../../../_shared/components/EmptyState';
import PlanActionDialog, { actionsFor, dueIn, isTerminal, retryActionFor } from '../../../_shared/recurring/PlanActions';
import PlanDetailDialog from '../../../subscriptions/PlanDetailDialog';
import {
    intervalLabel,
    renderHealth,
    viewDetailsAction,
    copySubscriptionIdAction,
} from '../../../_shared/recurring/planColumns';
import { formatAmount, formatDateTime, planStatusPill } from '../helpers';

const STATUS_OPTIONS = [
    { value: 'active',    label: __( 'Active', 'fundraising-toolkit' ) },
    { value: 'past_due',  label: __( 'Past due', 'fundraising-toolkit' ) },
    { value: 'paused',    label: __( 'Paused', 'fundraising-toolkit' ) },
    { value: 'cancelled', label: __( 'Cancelled', 'fundraising-toolkit' ) },
    { value: 'expired',   label: __( 'Expired', 'fundraising-toolkit' ) },
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
        fields:  [ 'plan', 'amount', 'status', 'next_payment_at', 'failed', 'lifetime' ],
    } );

    const fields = useMemo( () => [
        {
            id:    'plan',
            label: __( 'Plan', 'fundraising-toolkit' ),
            enableSorting: true,
            enableGlobalSearch: true,
            getValue: ( { item } ) => [ item.gateway, item.gateway_subscription_id ].filter( Boolean ).join( ' ' ),
            render: ( { item } ) => (
                <div className="fundkit-row">
                    <div className="fundkit-row__body">
                        <div className="fundkit-row__name" style={ { textTransform: 'capitalize' } }>
                            { item.gateway }
                            { /* Labelled because the card above deliberately
                                 leaves it out of the totals. */ }
                            { item.is_test && (
                                <span className="dp-pill is-muted" style={ { marginLeft: 6 } }>
                                    { __( 'Test', 'fundraising-toolkit' ) }
                                </span>
                            ) }
                        </div>
                        <code className="fundkit-row__sub fundkit-row__sub--mono">{ item.gateway_subscription_id }</code>
                    </div>
                </div>
            ),
        },
        {
            id:    'amount',
            label: __( 'Amount / interval', 'fundraising-toolkit' ),
            enableSorting: true,
            getValue: ( { item } ) => item.amount_cents,
            render: ( { item } ) => (
                <span>
                    { formatAmount( item.amount_cents, item.currency ) }
                    <span className="fundkit-row__sub"> / { intervalLabel( item.interval_unit, item.interval_count ) }</span>
                </span>
            ),
        },
        {
            id:       'status',
            label:    __( 'Status', 'fundraising-toolkit' ),
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
            label: __( 'Next charge', 'fundraising-toolkit' ),
            enableSorting: true,
            render: ( { item } ) => ( isTerminal( item.status ) || ! item.next_payment_at )
                ? <span className="fundkit-row__sub">-</span>
                : (
                    <div className="fundkit-row">
                        <div className="fundkit-row__body">
                            <div className="fundkit-row__name">{ dueIn( item.next_payment_at ) }</div>
                            <div className="fundkit-row__sub">{ formatDateTime( item.next_payment_at ) }</div>
                        </div>
                    </div>
                ),
        },
        {
            id:    'failed',
            label: __( 'Health', 'fundraising-toolkit' ),
            enableSorting: true,
            getValue: ( { item } ) => item.failed_renewals_count || 0,
            render: ( { item } ) => renderHealth( item ),
        },
        {
            id:    'gateway',
            label: __( 'Gateway', 'fundraising-toolkit' ),
            render: ( { item } ) => (
                <div className="fundkit-row">
                    <div className="fundkit-row__body">
                        <div className="fundkit-row__name" style={ { textTransform: 'capitalize' } }>{ item.gateway }</div>
                        { item.gateway_subscription_id
                            ? <div className="fundkit-row__sub mono">{ item.gateway_subscription_id }</div>
                            : <div className="fundkit-row__sub">{ __( 'Not linked', 'fundraising-toolkit' ) }</div> }
                    </div>
                </div>
            ),
        },
        {
            id:    'started_at',
            label: __( 'Started', 'fundraising-toolkit' ),
            enableSorting: true,
            getValue: ( { item } ) => item.started_at || '',
            render: ( { item } ) => <span>{ item.started_at ? formatDateTime( item.started_at ) : '-' }</span>,
        },
        {
            id:    'lifetime',
            label: __( 'Lifetime', 'fundraising-toolkit' ),
            enableSorting: true,
            getValue: ( { item } ) => item.total_paid_cents,
            render: ( { item } ) => (
                <div className="fundkit-row">
                    <div className="fundkit-row__body">
                        <div className="fundkit-row__name">{ formatAmount( item.total_paid_cents, item.currency ) }</div>
                        <div className="fundkit-row__sub">
                            { sprintf(
                                /* translators: %d: number of donations */
                                _n( '%d donation', '%d donations', item.payments_count, 'fundraising-toolkit' ),
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
            label: __( 'Retry payment', 'fundraising-toolkit' ),
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
            label:      __( 'Pause', 'fundraising-toolkit' ),
            isEligible: ( item ) => actionsFor( item ).some( ( a ) => a.id === 'pause' ),
            callback:   ( items ) => setDialog( { plan: items[ 0 ], action: 'pause' } ),
        },
        {
            id:         'resume',
            label:      __( 'Resume', 'fundraising-toolkit' ),
            isEligible: ( item ) => actionsFor( item ).some( ( a ) => a.id === 'resume' ),
            callback:   ( items ) => setDialog( { plan: items[ 0 ], action: 'resume' } ),
        },
        {
            id:         'skip_next',
            label:      __( 'Skip next', 'fundraising-toolkit' ),
            isEligible: ( item ) => actionsFor( item ).some( ( a ) => a.id === 'skip_next' ),
            callback:   ( items ) => setDialog( { plan: items[ 0 ], action: 'skip_next' } ),
        },
        {
            id:         'change_amount',
            label:      __( 'Change amount', 'fundraising-toolkit' ),
            isEligible: ( item ) => ! isTerminal( item.status ),
            callback:   ( items ) => setDialog( { plan: items[ 0 ], action: 'change_amount' } ),
        },
        {
            id:            'cancel',
            label:         __( 'Cancel', 'fundraising-toolkit' ),
            isDestructive: true,
            isEligible:    ( item ) => ! isTerminal( item.status ),
            callback:      ( items ) => setDialog( { plan: items[ 0 ], action: 'cancel' } ),
        },
    ], [] );

    if ( plans.length === 0 ) {
        return (
            <div className="dp-card">
                <EmptyState
                    compact
                    title={ __( 'No subscriptions on file', 'fundraising-toolkit' ) }
                    body={ __( 'Recurring plans appear here once this donor sets one up on a form that offers it.', 'fundraising-toolkit' ) }
                />
            </div>
        );
    }

    return (
        <div className="fundkit-dataviews dp-recurring-dv">
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
                searchLabel={ __( 'Search by subscription ID', 'fundraising-toolkit' ) }
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
