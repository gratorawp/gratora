/**
 * Until a site takes a real donation, the dashboard shows what is left as four
 * steps. The card presses the server's own buttons: it makes the first page,
 * switches test mode, and can be put away by the person looking at it.
 */

import { render } from 'preact';
import apiFetch from '@wordpress/api-fetch';

import FirstRunCard from '../../assets/admin/dashboard/FirstRunCard';

const { settle } = require( './support/waitFor' );

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

const PAGE = 'https://example.org/support-riverside-food-bank/';

const facts = ( over = {} ) => ( {
    page:            'none',
    page_title:      null,
    page_url:        null,
    test_mode:       true,
    test_donation:   null,
    payments:        false,
    payment_methods: [],
    ...over,
} );

const withPage = ( over = {} ) => facts( { page: 'live', page_title: 'Support Riverside Food Bank', page_url: PAGE, ...over } );

let root;
let heard;

function card( given ) {
    heard = { onChanged: jest.fn(), onModeSwitched: jest.fn(), onHidden: jest.fn() };
    root = document.createElement( 'div' );
    document.body.appendChild( root );
    render( <FirstRunCard facts={ given } { ...heard } />, root );
    return root;
}

const steps = () => [ ...root.querySelectorAll( 'ol.gratora-firstrun__steps > li' ) ];
const stepNamed = ( title ) => steps().find( ( li ) => li.querySelector( 'h3' ).textContent === title );
const pressable = ( label ) => [ ...root.querySelectorAll( 'button' ) ].find( ( b ) => b.textContent.trim() === label );
const link = ( label ) => [ ...root.querySelectorAll( 'a' ) ].find( ( a ) => a.textContent.trim().startsWith( label ) );
const filled = () => [ ...root.querySelectorAll( '.gratora-firstrun__steps .gratora-btn--primary' ) ];
const lastRequest = () => apiFetch.mock.calls[ apiFetch.mock.calls.length - 1 ][ 0 ];

beforeEach( () => {
    document.body.innerHTML = '';
    apiFetch.mockReset();
    apiFetch.mockResolvedValue( {} );
    window.history.replaceState( null, '', '/wp-admin/admin.php?page=gratora' );
} );

test( 'it draws the four steps and how many are done', () => {
    card( withPage() );

    expect( steps().map( ( li ) => li.querySelector( 'h3' ).textContent ) ).toEqual( [
        'Create your donation page',
        'Make a test donation',
        'Connect payments',
        'Go live',
    ] );
    expect( root.querySelector( '.gratora-firstrun__progress' ).textContent ).toContain( '1 of 4 done' );
    expect( stepNamed( 'Create your donation page' ).textContent ).toContain( 'Done' );
    expect( stepNamed( 'Make a test donation' ).textContent ).toContain( 'Step 2' );
} );

test( 'one step carries the filled button: the first still to do', () => {
    card( withPage() );

    expect( filled() ).toHaveLength( 1 );
    expect( stepNamed( 'Make a test donation' ).contains( filled()[ 0 ] ) ).toBe( true );
    expect( filled()[ 0 ].getAttribute( 'href' ) ).toBe( PAGE );
    expect( filled()[ 0 ].getAttribute( 'target' ) ).toBe( '_blank' );
} );

test( 'creating the page asks the server for it and then for fresh facts', async () => {
    card( facts() );

    pressable( 'Create the page' ).click();
    await settle();

    expect( lastRequest() ).toEqual( { path: '/gratora/v1/admin/onboarding/starter-campaign', method: 'POST' } );
    expect( heard.onChanged ).toHaveBeenCalledTimes( 1 );
    expect( heard.onModeSwitched ).not.toHaveBeenCalled();
} );

test( 'a second press while the first is being answered sends nothing more', async () => {
    apiFetch.mockReturnValue( new Promise( () => {} ) );
    card( facts() );

    pressable( 'Create the page' ).click();
    await settle();
    pressable( 'Create the page' ).click();
    await settle();

    expect( apiFetch ).toHaveBeenCalledTimes( 1 );
    expect( pressable( 'Create the page' ).disabled ).toBe( true );
} );

test( 'a refusal is read out, and the button works again', async () => {
    apiFetch.mockRejectedValue( { message: 'This site already has a campaign.' } );
    card( facts() );

    pressable( 'Create the page' ).click();
    await settle();

    expect( root.querySelector( '[role="alert"]' ).textContent ).toBe( 'This site already has a campaign.' );
    expect( heard.onChanged ).not.toHaveBeenCalled();
    expect( pressable( 'Create the page' ).disabled ).toBe( false );
} );

test( 'turning test mode on saves it through the payment settings', async () => {
    card( withPage( { test_mode: false } ) );

    pressable( 'Turn on test mode' ).click();
    await settle();

    expect( lastRequest() ).toEqual( { path: '/gratora/v1/admin/settings/gateways', method: 'PUT', data: { test_mode: true } } );
    expect( heard.onModeSwitched ).toHaveBeenCalledWith( true );
} );

test( 'turning test mode off saves it and says which way the switch went', async () => {
    card( withPage( { payments: true, payment_methods: [ 'Stripe' ] } ) );

    pressable( 'Turn off test mode' ).click();
    await settle();

    expect( lastRequest() ).toEqual( { path: '/gratora/v1/admin/settings/gateways', method: 'PUT', data: { test_mode: false } } );
    expect( heard.onModeSwitched ).toHaveBeenCalledWith( false );
} );

test( 'a switch that was refused is not announced', async () => {
    apiFetch.mockRejectedValue( { message: 'Sorry, you are not allowed to do that.' } );
    card( withPage( { payments: true, payment_methods: [ 'Stripe' ] } ) );

    pressable( 'Turn off test mode' ).click();
    await settle();

    expect( heard.onModeSwitched ).not.toHaveBeenCalled();
    expect( root.querySelector( '[role="alert"]' ).textContent ).toBe( 'Sorry, you are not allowed to do that.' );
} );

test( 'with no payments, going live cannot be pressed and the button carries its reason', () => {
    card( withPage() );

    const button = pressable( 'Turn off test mode' );
    const reason = document.getElementById( button.getAttribute( 'aria-describedby' ) );

    expect( button.disabled ).toBe( true );
    expect( reason.textContent ).toBe( 'Connect payments first. With test mode off and no payment method, the form could take nothing.' );
} );

test( 'hide puts it away for this person', async () => {
    card( facts() );

    pressable( 'Hide' ).click();
    await settle();

    expect( lastRequest() ).toEqual( { path: '/gratora/v1/admin/me/first-run', method: 'POST' } );
    expect( heard.onHidden ).toHaveBeenCalledTimes( 1 );
} );

test( 'the demo opens in a new tab and is marked as coming from the dashboard', () => {
    card( facts() );

    const demo = link( 'Open the demo' );

    expect( demo.getAttribute( 'href' ) ).toBe( 'https://gratora.net/demo/?utm_source=plugin&utm_medium=dashboard' );
    expect( demo.getAttribute( 'target' ) ).toBe( '_blank' );
    expect( demo.textContent ).toContain( '(opens in a new tab)' );
} );

test( 'the full check is one link away, on the Setup tab', () => {
    card( facts() );

    expect( link( 'See the full setup check' ).getAttribute( 'href' ) ).toBe( '/wp-admin/admin.php?page=gratora-settings#setup' );
} );

test( 'payments are connected on the Payment gateways tab, and campaigns published on their own screen', () => {
    card( facts( { page: 'closed' } ) );

    expect( link( 'Connect payments' ).getAttribute( 'href' ) ).toBe( '/wp-admin/admin.php?page=gratora-settings#gateways' );
    expect( link( 'Open campaigns' ).getAttribute( 'href' ) ).toBe( '/wp-admin/admin.php?page=gratora-campaigns' );
} );
