/**
 * Each add-on card shows the state this site has the add-on in, and offers
 * activation only where the server supplied a way to do it.
 */

import { render } from 'preact';

import Addons from '../../assets/admin/addons/Addons';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

const ACTIVATE = 'http://example.test/wp-admin/plugins.php?action=activate&plugin=gratora-events%2Fgratora-events.php&_wpnonce=abc';

const addon = ( over ) => ( {
    slug:        'events',
    name:        'Event Tickets',
    description: 'Sell tickets to fundraising events.',
    icon:        'ticket',
    url:         'https://gratora.net/add-ons/events/',
    free:        false,
    status:      'available',
    activateUrl: '',
    ...over,
} );

function card( props ) {
    const root = document.createElement( 'div' );
    document.body.appendChild( root );
    render( <Addons addons={ [ addon( props ) ] } />, root );
    return root.querySelector( '.gratora-addon' );
}

const pill = ( li ) => li.querySelector( '.gratora-pill' )?.textContent ?? null;
const activate = ( li ) => [ ...li.querySelectorAll( 'a' ) ].find( ( a ) => a.textContent === 'Activate' );

beforeEach( () => {
    document.body.innerHTML = '';
} );

test( 'an installed add-on is activated at the address the server gave', () => {
    const li = card( { status: 'installed', activateUrl: ACTIVATE } );

    expect( pill( li ) ).toBe( 'Installed' );
    expect( activate( li ).getAttribute( 'href' ) ).toBe( ACTIVATE );
    expect( activate( li ).getAttribute( 'aria-label' ) ).toBe( 'Activate Event Tickets' );
} );

test( 'an installed add-on the reader cannot activate has no button', () => {
    const li = card( { status: 'installed' } );

    expect( pill( li ) ).toBe( 'Installed' );
    expect( activate( li ) ).toBeUndefined();
} );

test( 'an active add-on says so', () => {
    const li = card( { status: 'active' } );

    expect( pill( li ) ).toBe( 'Active' );
    expect( activate( li ) ).toBeUndefined();
} );

test( 'an add-on the site does not have links to its page in a new tab', () => {
    const li = card( {} );
    const more = li.querySelector( '.gratora-addon__more' );

    expect( pill( li ) ).toBeNull();
    expect( more.getAttribute( 'href' ) ).toBe( 'https://gratora.net/add-ons/events/' );
    expect( more.getAttribute( 'target' ) ).toBe( '_blank' );
    expect( more.getAttribute( 'aria-label' ) ).toBe( 'Learn more about Event Tickets (opens in a new tab)' );
} );

test( 'a card draws the icon the server names for it', () => {
    const tile = ( icon ) => card( { icon } ).querySelector( '.gratora-addon__tile svg' );

    expect( tile( 'ticket' ) ).not.toBeNull();
    expect( tile( 'mail' ) ).not.toBeNull();
} );

test( 'the free importer says it is free until the site has it', () => {
    expect( pill( card( { slug: 'give-importer', free: true } ) ) ).toBe( 'Free' );
    expect( pill( card( { slug: 'give-importer', free: true, status: 'active' } ) ) ).toBe( 'Active' );
} );
