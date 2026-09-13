/**
 * A donor failing on two processors has two different things to go and do.
 *
 * The profile header takes whatever the server sends it, and the server used
 * to send one line however many causes there were. Both lines are of the same
 * kind, so anything here that treats the kind as an identity, keying on it or
 * picking one of them, silently drops the second answer.
 */

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

import { render } from 'preact';

import Header from '../../assets/admin/donors/profile/Header';

const KEYS = 'Stripe is not set up on this site, so nothing can be asked of it.';
const SCHEDULE = 'PayPal decides when to try a failed payment again and offers no way to ask it sooner.';

function mount( banners, onTabSwitch = () => {} ) {
    document.body.innerHTML = '<div id="root"></div>';

    render(
        <Header
            donor={ { id: 1, name: 'A', is_anonymous: false, redacted_at: null, first_donation_at: null } }
            recurring={ { plans: [] } }
            banners={ banners }
            onBack={ () => {} }
            onEdit={ () => {} }
            onTabSwitch={ onTabSwitch }
        />,
        document.getElementById( 'root' )
    );
}

const twoCauses = [
    { kind: 'past_due', message: `A renewal was declined. ${ KEYS }` },
    { kind: 'past_due', message: `A renewal was declined. ${ SCHEDULE }` },
];

it( 'reads out every cause it is given', () => {
    mount( twoCauses );

    expect( document.body.textContent ).toContain( KEYS );
    expect( document.body.textContent ).toContain( SCHEDULE );
} );

it( 'draws one banner per cause', () => {
    mount( twoCauses );

    expect( document.querySelectorAll( '.banner--warn' ) ).toHaveLength( 2 );
} );

/** The way to the failing rows belongs on each of them, not just the first. */
it( 'offers the way to the table on each', () => {
    mount( twoCauses );

    const buttons = [ ...document.querySelectorAll( '.banner--warn button' ) ];

    expect( buttons ).toHaveLength( 2 );
} );

/** A cause that is resolved leaves, and the one still standing keeps its text. */
it( 'keeps the remaining cause when another is resolved', () => {
    const onTabSwitch = () => {};

    mount( twoCauses, onTabSwitch );
    mount( [ twoCauses[ 1 ] ], onTabSwitch );

    expect( document.body.textContent ).toContain( SCHEDULE );
    expect( document.body.textContent ).not.toContain( KEYS );
    expect( document.querySelectorAll( '.banner--warn' ) ).toHaveLength( 1 );
} );

/** Redaction is a different kind and sits alongside them. */
it( 'keeps an unrelated banner beside them', () => {
    mount( [ { kind: 'redacted', message: 'This donor has been redacted.' }, ...twoCauses ] );

    expect( document.querySelectorAll( '.banner--info' ) ).toHaveLength( 1 );
    expect( document.querySelectorAll( '.banner--warn' ) ).toHaveLength( 2 );
} );
