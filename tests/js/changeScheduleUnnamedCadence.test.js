/**
 * A plan on a cadence this product has no name for (an imported one billing
 * every six months, say) opened Change schedule with "Every month" already
 * selected, as though that were its current schedule. One click retimed the
 * donor to six times the frequency they agreed to.
 */

import { render } from 'preact';
import apiFetch from '@wordpress/api-fetch';

jest.mock( '@wordpress/api-fetch', () => jest.fn( () => Promise.resolve( {} ) ) );
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

jest.mock( '../../assets/admin/_shared/caps', () => ( { userCan: () => true } ) );

const PlanActionDialog = require( '../../assets/admin/_shared/recurring/PlanActions' ).default;

const settle = () => new Promise( ( resolve ) => setTimeout( resolve, 20 ) );

const PLAN = {
    id: 38,
    status: 'active',
    amount_cents: 2500,
    currency: 'EUR',
    interval_unit: 'month',
    interval_count: 6,
    can_change_interval: true,
    frequency_options: [ 'weekly', 'biweekly', 'monthly', 'quarterly', 'yearly' ],
};

let root = null;

async function open( plan ) {
    if ( root ) render( null, root );
    document.body.innerHTML = '<div id="root"></div>';
    root = document.getElementById( 'root' );

    render(
        <PlanActionDialog plan={ plan } action="change_interval" onClose={ () => {} } onDone={ () => {} } />,
        root
    );
    await settle();
}

const apply = () => [ ...document.querySelectorAll( 'button' ) ]
    .filter( ( b ) => ! /cancel/i.test( b.textContent.trim() ) )
    .pop()
    .click();

beforeEach( () => {
    apiFetch.mockClear();
    document.body.innerHTML = '';
} );

it( 'preselects nothing when the plan is on a cadence with no name', async () => {
    await open( { ...PLAN, frequency: null } );

    expect( document.querySelector( 'select' ).value ).toBe( '' );
} );

it( 'refuses to apply until a schedule is chosen', async () => {
    await open( { ...PLAN, frequency: null } );

    apply();
    await settle();

    expect( apiFetch ).not.toHaveBeenCalled();
    expect( document.body.textContent ).toContain( 'Choose a schedule' );
} );

it( 'still shows the plan its own schedule when it has one', async () => {
    await open( { ...PLAN, frequency: 'quarterly', interval_count: 3 } );

    expect( document.querySelector( 'select' ).value ).toBe( 'quarterly' );
} );

it( 'still refuses a change to the schedule it is already on', async () => {
    await open( { ...PLAN, frequency: 'quarterly', interval_count: 3 } );

    apply();
    await settle();

    expect( apiFetch ).not.toHaveBeenCalled();
    expect( document.body.textContent ).toContain( 'already' );
} );

it( 'sends the change once a different schedule is chosen', async () => {
    await open( { ...PLAN, frequency: null } );

    const select = document.querySelector( 'select' );
    select.value = 'yearly';
    select.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );
    await settle();

    apply();
    await settle();

    expect( apiFetch ).toHaveBeenCalled();
    expect( apiFetch.mock.calls[ 0 ][ 0 ].data.frequency ).toBe( 'yearly' );
} );
