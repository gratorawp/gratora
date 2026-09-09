/**
 * confirmPayment usually resolves with an error object, but Stripe.js throws on
 * a client_secret and elements mismatch and on some internal failures. The
 * throw skipped every setPaying( false ) below it, so Pay stayed disabled and
 * so did Cancel: the donor is left on a payment screen with two dead buttons
 * and a card that has not been charged.
 */

jest.mock( '../../assets/donation-form/util/stripe', () => {
    const actual = jest.requireActual( '../../assets/donation-form/util/stripe' );

    return {
        ...actual,
        loadStripeJs: () => Promise.resolve( () => ( {
            elements: () => ( {
                create: () => ( {
                    on: ( ev, cb ) => { if ( ev === 'ready' ) cb(); },
                    mount() {},
                    destroy() {},
                    update() {},
                } ),
                update() {},
            } ),
            confirmPayment: () => Promise.reject( new Error( 'IntegrationError' ) ),
        } ) ),
    };
} );

import { render } from 'preact';

import StripePayment from '../../assets/donation-form/components/StripePayment';

const I18N = {
    error:      'Sorry, something went wrong. Please try again.',
    pay:        'Pay',
    cancel:     'Cancel',
    processing: 'Processing',
};

const CONFIG = {
    currency: 'USD',
    stripe:   { publishableKey: 'pk_test_probe' },
    i18n:     I18N,
    rest:     'https://example.test/wp-json/gratora/v1/donations',
};

const PAYMENT = {
    reference:   'DN-1',
    statusToken: 'tok',
    clientSecret: 'pi_1_secret_abc',
    amountCents: 2500,
    currency:    'USD',
};

const settle = () => new Promise( ( r ) => setTimeout( r, 60 ) );

const button = ( label ) => [ ...document.querySelectorAll( 'button' ) ]
    .find( ( b ) => new RegExp( label, 'i' ).test( b.textContent ) );

beforeEach( () => {
    document.body.innerHTML = '<div id="root"></div>';
    window.gratora = {
        default_currency: 'USD',
        number_format: { decimalPlaces: 2, decimalSep: '.', thousandSep: ',', symbolPosition: 'before', symbol: '$' },
    };
} );

afterEach( () => { delete window.gratora; } );

it( 'leaves the donor a way out when confirmPayment throws', async () => {
    render(
        <StripePayment config={ CONFIG } payment={ PAYMENT } dispatch={ () => {} } />,
        document.getElementById( 'root' )
    );
    await settle();

    const pay = button( 'Pay' );
    expect( pay ).toBeTruthy();

    pay.click();
    await settle();

    expect( document.body.textContent ).toContain( I18N.error );
    expect( button( 'Pay' ).disabled ).toBe( false );
} );
