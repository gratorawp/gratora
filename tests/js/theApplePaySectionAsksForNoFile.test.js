/**
 * Stripe verifies the domain and offers no file to download, so the section
 * registers the domain and reports what Stripe says.
 */

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );
jest.mock( '@wordpress/api-fetch', () => jest.fn() );

jest.mock( '../../assets/admin/_shared/notify', () => ( {
    __esModule: true,
    notify:  { success: jest.fn(), error: jest.fn(), warning: jest.fn() },
    default: { success: jest.fn(), error: jest.fn(), warning: jest.fn() },
} ) );

import { render } from 'preact';
import apiFetch from '@wordpress/api-fetch';

import StripeKeysCard from '../../assets/admin/settings/panels/StripeKeysCard';

const { waitFor } = require( './support/waitFor' );

const settings = { value: ( key, fallback ) => fallback, setValue: () => () => {} };

const status = ( live, test = { status: 'unknown', message: '' } ) => ( {
    connected:          true,
    can_charge:         true,
    account:            { has_test: true, has_live: true },
    webhook_url:        'https://example.test/wp-json/gratora/v1/webhooks/stripe',
    has_webhook_secret: true,
    test_donation_made: true,
    apple_pay:          { domain: 'example.test', test, live },
} );

const root    = () => document.getElementById( 'root' );
const section = () => [ ...document.querySelectorAll( '.gratora-stripe-mode' ) ]
    .find( ( el ) => el.querySelector( 'strong' )?.textContent === 'Apple Pay' );
const button  = ( label ) => [ ...section().querySelectorAll( 'button' ) ].find( ( b ) => b.textContent.trim() === label );

async function show( current ) {
    apiFetch.mockImplementation( ( { path } ) => Promise.resolve(
        path.endsWith( '/apple-pay' ) ? { apple_pay: { status: 'active', message: '' } } : current
    ) );
    render( <StripeKeysCard s={ settings } />, root() );
    await waitFor( () => apiFetch.mock.calls.length > 0 && !! document.querySelector( '.gratora-card__head--toggle' ), { what: 'the loaded card' } );

    const head = document.querySelector( '.gratora-card__head--toggle' );
    if ( head.getAttribute( 'aria-expanded' ) === 'false' ) head.click();
    await waitFor( () => !! section(), { what: 'the Apple Pay section' } );
}

beforeEach( () => {
    apiFetch.mockReset();
    document.body.innerHTML = '<div id="root"></div>';
} );

afterEach( () => {
    render( null, root() );
} );

it( 'registers the domain for every saved mode with nothing to paste', async () => {
    await show( status( { status: 'unknown', message: '' } ) );

    expect( section().querySelector( 'textarea' ) ).toBeNull();
    expect( section().textContent ).not.toMatch( /file/i );
    expect( section().textContent ).toContain( 'Not set up' );

    button( 'Enable Apple Pay' ).click();

    const sent = () => apiFetch.mock.calls.map( ( [ call ] ) => call ).filter( ( call ) => call.path.endsWith( '/apple-pay' ) );
    await waitFor( () => sent().length === 2, { what: 'one call per saved mode' } );
    expect( sent().map( ( call ) => call.data ) ).toEqual( [ { mode: 'test' }, { mode: 'live' } ] );
} );

it( 'says Verified when Stripe does and offers only a fresh check', async () => {
    const verified = { status: 'active', message: '' };
    await show( status( verified, verified ) );

    expect( section().textContent ).toContain( 'Verified' );
    expect( section().textContent ).toContain( 'Live: verified' );
    expect( button( 'Check again' ) ).toBeTruthy();
    expect( button( 'Replace file' ) ).toBeUndefined();
    expect( button( 'Enable Apple Pay' ) ).toBeUndefined();
} );

it( 'says Not verified with the reason Stripe gave', async () => {
    await show( status( { status: 'inactive', message: 'Stripe could not reach this domain.' } ) );

    expect( section().textContent ).toContain( 'Not verified' );
    expect( section().textContent ).toContain( 'Stripe could not reach this domain.' );
    expect( button( 'Check again' ) ).toBeTruthy();
} );
