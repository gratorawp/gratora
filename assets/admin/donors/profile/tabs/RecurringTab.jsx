import { __, _n, sprintf } from '@wordpress/i18n';
import { useMemo, useState } from '@wordpress/element';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import { RotateCw } from 'lucide-react';

import EmptyState from '../../../_shared/components/EmptyState';
import PlanActionDialog, { actionsFor, dueIn, isTerminal, retryActionFor } from '../../../_shared/recurring/PlanActions';
import { formatAmount, formatDateTime, planStatusPill } from '../helpers';

const STATUS_OPTIONS = [
    { value: 'active',    label: __( 'Active', 'dono' ) },
    { value: 'past_due',  label: __( 'Past due', 'dono' ) },
    { value: 'paused',    label: __( 'Paused', 'dono' ) },
    { value: 'cancelled', label: __( 'Cancelled', 'dono' ) },
    { value: 'expired',   label: __( 'Expired', 'dono' ) },
];

/**
 * "month", "2 week" and so on came straight from the database, so the cell
 * never translated and never pluralised. Spelled out per unit because a
 * translator needs both forms and the singular is not the column value.
 */
function intervalLabel( unit, count ) {
    const n = Number( count ) || 1;
    switch ( unit ) {
        case 'day':
            /* translators: %d: number of days between charges. */
            return sprintf( _n( '%d day', '%d days', n, 'dono' ), n );
        case 'week':
            /* translators: %d: number of weeks between charges. */
            return sprintf( _n( '%d week', '%d weeks', n, 'dono' ), n );
        case 'month':
            /* translators: %d: number of months between charges. */
            return sprintf( _n( '%d month', '%d months', n, 'dono' ), n );
        case 'year':
            /* translators: %d: number of years between charges. */
            return sprintf( _n( '%d year', '%d years', n, 'dono' ), n );
        default:
            return n > 1 ? `${ n } ${ unit }` : String( unit );
    }
}

export default function RecurringTab( { recurring, onChange } ) {
    // A fresh [] each render would re-sort and re-paginate on every keystroke.
    const plans = useMemo( () => recurring?.plans || [], [ recurring ] );
    const [ dialog, setDialog ] = useState( null );

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
            label: __( 'Plan', 'dono' ),
            enableSorting: true,
            enableGlobalSearch: true,
            getValue: ( { item } ) => [ item.gateway, item.gateway_subscription_id ].filter( Boolean ).join( ' ' ),
            render: ( { item } ) => (
                <div className="dono-row">
                    <div className="dono-row__body">
                        <div className="dono-row__name" style={ { textTransform: 'capitalize' } }>
                            { item.gateway }
                            { /* Labelled because the card above deliberately
                                 leaves it out of the totals. */ }
                            { item.is_test && (
                                <span className="dp-pill is-muted" style={ { marginLeft: 6 } }>
                                    { __( 'Test', 'dono' ) }
                                </span>
                            ) }
                        </div>
                        <code className="dono-row__sub dono-row__sub--mono">{ item.gateway_subscription_id }</code>
                    </div>
                </div>
            ),
        },
        {
            id:    'amount',
            label: __( 'Amount / interval', 'dono' ),
            enableSorting: true,
            getValue: ( { item } ) => item.amount_cents,
            render: ( { item } ) => (
                <span>
                    { formatAmount( item.amount_cents, item.currency ) }
                    <span className="dono-row__sub"> / { intervalLabel( item.interval_unit, item.interval_count ) }</span>
                </span>
            ),
        },
        {
            id:       'status',
            label:    __( 'Status', 'dono' ),
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
            label: __( 'Next charge', 'dono' ),
            enableSorting: true,
            render: ( { item } ) => ( isTerminal( item.status ) || ! item.next_payment_at )
                ? <span className="dono-row__sub">-</span>
                : (
                    <div className="dono-row">
                        <div className="dono-row__body">
                            <div className="dono-row__name">{ dueIn( item.next_payment_at ) }</div>
                            <div className="dono-row__sub">{ formatDateTime( item.next_payment_at ) }</div>
                        </div>
                    </div>
                ),
        },
        {
            id:    'failed',
            label: __( 'Renewal health', 'dono' ),
            enableSorting: true,
            getValue: ( { item } ) => item.failed_renewals_count || 0,
            render: ( { item } ) => item.failed_renewals_count > 0
                ? (
                    <span className="dono-pill is-warn">
                        { sprintf(
                            /* translators: %d: consecutive failed renewals. */
                            _n( '%d failure', '%d failures', item.failed_renewals_count, 'dono' ),
                            item.failed_renewals_count
                        ) }
                    </span>
                )
                : <span className="dono-row__sub">{ __( 'OK', 'dono' ) }</span>,
        },
        {
            id:    'lifetime',
            label: __( 'Lifetime', 'dono' ),
            enableSorting: true,
            getValue: ( { item } ) => item.total_paid_cents,
            render: ( { item } ) => (
                <div className="dono-row">
                    <div className="dono-row__body">
                        <div className="dono-row__name">{ formatAmount( item.total_paid_cents, item.currency ) }</div>
                        <div className="dono-row__sub">
                            { sprintf(
                                /* translators: %d: donations collected by this plan so far. */
                                _n( '%d donation', '%d donations', item.payments_count, 'dono' ),
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
        {
            id:    'retry',
            label: __( 'Retry payment', 'dono' ),
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
            label:      __( 'Pause', 'dono' ),
            isEligible: ( item ) => actionsFor( item ).some( ( a ) => a.id === 'pause' ),
            callback:   ( items ) => setDialog( { plan: items[ 0 ], action: 'pause' } ),
        },
        {
            id:         'resume',
            label:      __( 'Resume', 'dono' ),
            isEligible: ( item ) => actionsFor( item ).some( ( a ) => a.id === 'resume' ),
            callback:   ( items ) => setDialog( { plan: items[ 0 ], action: 'resume' } ),
        },
        {
            id:         'skip_next',
            label:      __( 'Skip next', 'dono' ),
            isEligible: ( item ) => actionsFor( item ).some( ( a ) => a.id === 'skip_next' ),
            callback:   ( items ) => setDialog( { plan: items[ 0 ], action: 'skip_next' } ),
        },
        {
            id:         'change_amount',
            label:      __( 'Change amount', 'dono' ),
            isEligible: ( item ) => ! isTerminal( item.status ),
            callback:   ( items ) => setDialog( { plan: items[ 0 ], action: 'change_amount' } ),
        },
        {
            id:            'cancel',
            label:         __( 'Cancel', 'dono' ),
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
                    title={ __( 'No subscriptions on file', 'dono' ) }
                    body={ __( 'Recurring plans appear here once this donor sets one up on a form that offers it.', 'dono' ) }
                />
            </div>
        );
    }

    return (
        <div className="dono-dataviews dp-recurring-dv">
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
                searchLabel={ __( 'Search by subscription ID', 'dono' ) }
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
        </div>
    );
}
