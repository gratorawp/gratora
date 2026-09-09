/**
 * Two rows on the two screens a donor reads most carefully.
 *
 * The review step's whole job is letting them check what they entered, and it
 * printed a two-letter country code and an Email row with nothing after it. The
 * thank-you card then labelled the fee-inclusive charge "Amount" whenever the
 * donation was recurring, which is the figure the step before it calls Total.
 */

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

import { render } from 'preact';

import ConfirmStep from '../../assets/donation-form/steps/ConfirmStep';

const I18N = {
    amount:    'Amount',
    total:     'Total',
    donor:     'Donor',
    email:     'Email',
    country:   'Country',
    fee:       'Processing fee',
    gateway:   'Payment method',
    frequency: 'Frequency',
};

const CONFIG = { i18n: I18N, gateways: { options: [ { id: 'stripe', label: 'Card' } ] } };

const state = ( values ) => ( {
    currency: 'USD',
    gateway:  'stripe',
    steps:    [],
    values: {
        amount_cents: 5000,
        cover_fees:   false,
        first_name:   'Ada',
        last_name:    'Lovelace',
        email:        'ada@example.test',
        profile:      { country: 'DE' },
        ...values,
    },
} );

let realDisplayNames;

beforeEach( () => {
    // The runner's ICU data is not the subject, so the lookup is pinned.
    realDisplayNames = Intl.DisplayNames;
    Intl.DisplayNames = function () {
        return { of: ( code ) => ( code === 'DE' ? 'Deutschland' : code ) };
    };
    document.documentElement.lang = 'de';
    document.body.innerHTML = '<div id="root"></div>';
} );

afterEach( () => {
    Intl.DisplayNames = realDisplayNames;
    document.documentElement.lang = '';
} );

const mount = ( s ) => render( <ConfirmStep state={ s } config={ CONFIG } />, document.getElementById( 'root' ) );

const rowValue = ( label ) => {
    const row = [ ...document.querySelectorAll( '.gratora-form__summary-row' ) ]
        .find( ( r ) => r.querySelector( 'dt' )?.textContent.trim() === label );

    return row ? row.querySelector( 'dd' ).textContent.trim() : null;
};

it( 'names the country the donor picked, not its code', () => {
    mount( state() );

    expect( rowValue( 'Country' ) ).toBe( 'Deutschland' );
    expect( document.body.textContent ).not.toContain( '>DE<' );
} );

it( 'leaves out an email row it has no email for', () => {
    mount( state( { email: '' } ) );

    expect( rowValue( 'Email' ) ).toBeNull();
} );

it( 'still shows the email when there is one', () => {
    mount( state() );

    expect( rowValue( 'Email' ) ).toBe( 'ada@example.test' );
} );
