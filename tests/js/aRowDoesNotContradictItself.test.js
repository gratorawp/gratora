/**
 * A next charge the row itself calls overdue, beside a Health column reading
 * OK.
 *
 * Health knew two things: renewals the gateway reported as failed, and
 * problems logged against the plan. A charge that was due and simply never
 * happened is neither, so the column said the plan was fine while the cell
 * next to it said the money was twelve days late.
 */

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

import { render } from 'preact';

import { renderHealth, isOverdue } from '../../assets/admin/_shared/recurring/planColumns';

const DAY = 86400000;
const at = ( offsetDays ) =>
    new Date( Date.now() + offsetDays * DAY ).toISOString().slice( 0, 19 ).replace( 'T', ' ' );

const plan = ( over = {} ) => ( {
    id: 1,
    status: 'active',
    failed_renewals_count: 0,
    errors: [],
    next_payment_at: at( 10 ),
    ...over,
} );

function health( item ) {
    document.body.innerHTML = '<div id="root"></div>';
    render( renderHealth( item ), document.getElementById( 'root' ) );

    return document.body.textContent.trim();
}

beforeEach( () => {
    window.gratora = { plan_statuses: [
        { value: 'active', label: 'Active', variant: 'green', terminal: false, unstarted: false },
        { value: 'cancelled', label: 'Cancelled', variant: 'gray', terminal: true, unstarted: false },
        { value: 'expired', label: 'Expired', variant: 'gray', terminal: true, unstarted: false },
    ] };
} );

it( 'does not call a plan fine when its charge never happened', () => {
    expect( health( plan( { next_payment_at: at( -12 ) } ) ) ).not.toBe( 'OK' );
} );

it( 'says nothing was heard, which is what is true', () => {
    expect( health( plan( { next_payment_at: at( -12 ) } ) ) ).toBe( 'Nothing heard' );
} );

it( 'still says OK while the charge is in the future', () => {
    expect( health( plan() ) ).toBe( 'OK' );
} );

/** A recorded failure is the better answer, and it goes on winning. */
it( 'prefers a reported failure to its own inference', () => {
    expect( health( plan( { next_payment_at: at( -12 ), failed_renewals_count: 2 } ) ) ).toBe( '2 failures' );
} );

it( 'prefers a logged problem too', () => {
    expect( health( plan( { next_payment_at: at( -12 ), errors: [ { reason: 'x' } ] } ) ) ).toBe( '1 problem' );
} );

/** A plan that has ended has no charge to be late for. */
it.each( [ [ 'cancelled' ], [ 'expired' ] ] )( 'says nothing about a %s plan', ( status ) => {
    expect( health( plan( { status, next_payment_at: at( -12 ) } ) ) ).toBe( 'OK' );
} );

it( 'says nothing about a plan with no next charge at all', () => {
    expect( isOverdue( plan( { next_payment_at: null } ) ) ).toBe( false );
} );
