/**
 * Cancelling a subscription is irreversible and sat behind the same accent
 * primary button as Pause, reading "Apply change". Nothing in the confirm
 * control named the act, and the unknown isDestructive prop was forwarded to
 * the DOM as isdestructive="true" rather than styling anything.
 */

import { render } from 'preact';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );
jest.mock( '@wordpress/api-fetch', () => jest.fn( () => new Promise( () => {} ) ) );

import PlanActionDialog from '../../assets/admin/_shared/recurring/PlanActions';

const plan = {
    id: 1,
    status: 'active',
    amount_cents: 2500,
    currency: 'USD',
    interval_unit: 'month',
    interval_count: 1,
};

function open( action ) {
    document.body.innerHTML = '<div id="root"></div>';

    render(
        <PlanActionDialog plan={ plan } action={ action } onClose={ () => {} } onDone={ () => {} } />,
        document.getElementById( 'root' )
    );

    return document.body;
}

const confirmButton = ( host ) =>
    [ ...host.querySelectorAll( 'button' ) ].find( ( b ) => /Cancel subscription|Apply change|Retry now/.test( b.textContent ) );

it( 'names the act on the confirm button', () => {
    expect( confirmButton( open( 'cancel' ) ).textContent ).toContain( 'Cancel subscription' );
} );

it( 'draws it as the destructive choice it is', () => {
    expect( confirmButton( open( 'cancel' ) ).className ).toContain( 'gratora-btn--danger' );
} );

it( 'forwards no unknown prop to the DOM', () => {
    expect( confirmButton( open( 'cancel' ) ).getAttribute( 'isdestructive' ) ).toBeNull();
} );

it( 'leaves a reversible change as an ordinary primary', () => {
    const button = confirmButton( open( 'pause' ) );

    expect( button.textContent ).toContain( 'Apply change' );
    expect( button.className ).toContain( 'gratora-btn--primary' );
} );

it( 'keeps the retry wording it already had', () => {
    expect( confirmButton( open( 'retry' ) ).textContent ).toContain( 'Retry now' );
} );
