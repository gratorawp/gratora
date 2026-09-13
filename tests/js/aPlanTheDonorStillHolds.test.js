/**
 * A subscription that has not ended is one the donor still holds, whatever
 * the gateway is doing with it today.
 *
 * Three places decided that for themselves and all three drew the line at
 * active-or-past-due. The profile said "No active recurring plan" over a
 * paused one while the card above it counted that same plan as paused; the
 * lifetime line computed a pending count and discarded it; and the action
 * menu offered Pause and Skip next on a PayPal subscription that had never
 * started, which the route behind them refuses.
 */

import { render } from 'preact';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

import ActivityTab from '../../assets/admin/donors/profile/tabs/ActivityTab';
import LifetimeMetrics from '../../assets/admin/donors/profile/LifetimeMetrics';
import { actionsFor } from '../../assets/admin/_shared/recurring/PlanActions';

// What the server hands the page, terminality and all.
const SHIPPED = [
    { value: 'active', label: 'Active', variant: 'green', terminal: false, unstarted: false },
    { value: 'pending', label: 'Pending', variant: 'amber', terminal: false, unstarted: true },
    { value: 'past_due', label: 'Past due', variant: 'amber', terminal: false, unstarted: false },
    { value: 'paused', label: 'Paused', variant: 'gray', terminal: false, unstarted: false },
    { value: 'cancelled', label: 'Cancelled', variant: 'gray', terminal: true, unstarted: false },
    { value: 'expired', label: 'Expired', variant: 'gray', terminal: true, unstarted: false },
];

const plan = ( status ) => ( {
    id: 3,
    status,
    gateway: 'paypal',
    amount_cents: 2500,
    currency: 'USD',
    interval_unit: 'month',
    interval_count: 1,
    next_payment_at: '2026-10-01 00:00:00',
    last_payment_at: null,
    total_paid_cents: 0,
    can_change_interval: false,
    failed_renewals_count: 0,
} );

function mountActivity( status ) {
    document.body.innerHTML = '<div id="root"></div>';
    render(
        <ActivityTab
            donations={ [] }
            donationsTotal={ 0 }
            events={ [] }
            eventsTotal={ 0 }
            campaigns={ [] }
            recurring={ { plans: [ plan( status ) ] } }
            donorId={ 1 }
            onTabSwitch={ () => {} }
        />,
        document.getElementById( 'root' )
    );
}

function mountLifetime( planCounts ) {
    document.body.innerHTML = '<div id="root"></div>';
    render(
        <LifetimeMetrics
            lifetime={ {
                total_cents: 5000, count: 2, avg_cents: 2500, largest_cents: 2500,
                one_time_count: 1, recurring_count: 1,
                mrr_cents: 0, mrr_unconverted: 0, active_plan_count: 0,
                plan_counts: planCounts, next_payment_at: null, sparkline: [],
            } }
        />,
        document.getElementById( 'root' )
    );
}

beforeEach( () => {
    window.gratora = { plan_statuses: SHIPPED, can: { refund_donations: true, manage_options: true } };
} );

describe( 'the profile card for the plan a donor holds', () => {
    it.each( [ [ 'paused' ], [ 'pending' ], [ 'past_due' ], [ 'active' ] ] )(
        'shows a %s plan rather than claiming there is none',
        ( status ) => {
            mountActivity( status );

            expect( document.body.textContent ).not.toContain( 'No recurring plan on file' );
        }
    );

    it.each( [ [ 'cancelled' ], [ 'expired' ] ] )( 'says there is none once the plan has ended (%s)', ( status ) => {
        mountActivity( status );

        expect( document.body.textContent ).toContain( 'No recurring plan on file' );
    } );

    it( 'names the status in the words the server sent', () => {
        mountActivity( 'pending' );

        expect( document.body.textContent ).toContain( 'Pending' );
    } );
} );

describe( 'the lifetime line', () => {
    it( 'counts a subscription the gateway has not started', () => {
        mountLifetime( { pending: 1 } );

        expect( document.body.textContent ).toContain( '1 pending' );
    } );

    it( 'still counts the ones it always did', () => {
        mountLifetime( { past_due: 2, paused: 1 } );

        expect( document.body.textContent ).toContain( '2 past due' );
        expect( document.body.textContent ).toContain( '1 paused' );
    } );
} );

describe( 'the action menu', () => {
    const idsFor = ( status ) => actionsFor( plan( status ) ).map( ( a ) => a.id );

    it( 'offers nothing but ending it on a subscription that has not started', () => {
        expect( idsFor( 'pending' ) ).toEqual( [ 'cancel' ] );
    } );

    it( 'still offers the rest once it has', () => {
        expect( idsFor( 'active' ) ).toEqual( [ 'pause', 'skip_next', 'change_amount', 'cancel' ] );
    } );

    it( 'offers nothing at all once it has ended', () => {
        expect( idsFor( 'cancelled' ) ).toEqual( [] );
    } );
} );
