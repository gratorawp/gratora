/**
 * Until PayPal credentials are saved the card says how to get them and offers
 * checks meanwhile; after a test donation the live credentials come first.
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

import PayPalKeysCard from '../../assets/admin/settings/panels/PayPalKeysCard';

const { waitFor } = require( './support/waitFor' );

const settings = { value: ( key, fallback ) => fallback, setValue: () => () => {} };

const NOTHING_SAVED = {
    connected:          false,
    account:            null,
    webhook_url:        'https://example.test/wp-json/gratora/v1/webhooks/paypal',
    test_donation_made: false,
};

const LIVE_SAVED = {
    ...NOTHING_SAVED,
    connected: true,
    account:   { has_test: false, has_live: true, client_id_live: 'AeA1QIZ', webhook_test: false, webhook_live: false },
};

const root     = () => document.getElementById( 'root' );
const text     = () => document.body.textContent;
const modes    = () => [ ...document.querySelectorAll( '.gratora-stripe-mode__head strong' ) ].map( ( el ) => el.textContent );
const button   = ( label ) => [ ...document.querySelectorAll( 'button' ) ].find( ( b ) => b.textContent.trim() === label );
const hookFold = () => document.querySelector( '[aria-controls="gratora-paypal-webhooks"]' );

async function show( status, props = {} ) {
    apiFetch.mockResolvedValue( status );
    render( <PayPalKeysCard s={ settings } { ...props } />, root() );
    await waitFor( () => apiFetch.mock.calls.length > 0 && ! text().includes( 'Checking' ), { what: 'the loaded card' } );

    const head = document.querySelector( '.gratora-card__head--toggle' );
    if ( head.getAttribute( 'aria-expanded' ) === 'false' ) head.click();
    await waitFor( () => modes().length === 2, { what: 'both credential boxes' } );
}

beforeEach( () => {
    apiFetch.mockReset();
    document.body.innerHTML = '<div id="root"></div>';
} );

afterEach( () => {
    render( null, root() );
} );

it( 'shows the steps and the checks offer while no credentials are saved', async () => {
    const onOffline = jest.fn();
    await show( NOTHING_SAVED, { onOffline } );

    expect( text() ).toContain( 'Open a PayPal Business account' );
    expect( text() ).toContain( 'Switch to Live and press Create App.' );
    expect( modes() ).toEqual( [ 'Sandbox credentials', 'Live credentials' ] );
    expect( hookFold().getAttribute( 'aria-expanded' ) ).toBe( 'false' );

    button( 'Set up checks and bank transfers' ).click();
    expect( onOffline ).toHaveBeenCalledTimes( 1 );
} );

it( 'puts the live credentials first once a test donation has been made', async () => {
    await show( { ...NOTHING_SAVED, test_donation_made: true } );

    expect( modes() ).toEqual( [ 'Live credentials', 'Sandbox credentials (optional)' ] );
} );

it( 'drops the steps once credentials are saved and opens the webhook settings until a webhook id is', async () => {
    await show( LIVE_SAVED, { onOffline: jest.fn() } );

    expect( text() ).not.toContain( 'Open a PayPal Business account' );
    expect( button( 'Set up checks and bank transfers' ) ).toBeUndefined();
    expect( hookFold().getAttribute( 'aria-expanded' ) ).toBe( 'true' );
    expect( text() ).toContain( 'Webhook endpoint' );

    render( null, root() );
    await show( { ...LIVE_SAVED, account: { ...LIVE_SAVED.account, webhook_live: true } } );

    expect( hookFold().getAttribute( 'aria-expanded' ) ).toBe( 'false' );
} );
