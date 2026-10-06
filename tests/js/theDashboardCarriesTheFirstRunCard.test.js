/**
 * The dashboard draws the first-run card when its payload carries the facts,
 * asks for the payload again after a press that changed them, and says so once
 * the last press has made the site live.
 */

import { render } from 'preact';
import apiFetch from '@wordpress/api-fetch';

import Dashboard from '../../assets/admin/dashboard/Dashboard';

const { settle } = require( './support/waitFor' );

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

jest.mock( '../../assets/admin/_shared/widgets/WidgetGrid', () => ( { __esModule: true, default: () => <div className="the-widgets" /> } ) );
jest.mock( '../../assets/admin/_shared/widgets/LayoutControls', () => ( { __esModule: true, default: () => null } ) );
jest.mock( '../../assets/admin/_shared/widgets/SectionBar', () => ( { __esModule: true, default: () => null } ) );
jest.mock( '../../assets/admin/_shared/widgets/RevenueChart', () => ( { __esModule: true, default: () => null } ) );
jest.mock( '../../assets/admin/_shared/widgets/ChannelBreakdown', () => ( { __esModule: true, default: () => null } ) );
jest.mock( '../../assets/admin/_shared/widgets/useGratoraLayout', () => ( {
    useGratoraLayout: () => ( { order: [], visibleOrder: [], hidden: [], loaded: true, moveTo() {}, hide() {}, unhide() {}, reset() {} } ),
} ) );

const NEW_SITE = {
    page:            'none',
    page_title:      null,
    page_url:        null,
    test_mode:       true,
    test_donation:   null,
    payments:        false,
    payment_methods: [],
};

const WITH_PAGE = {
    ...NEW_SITE,
    page:       'live',
    page_title: 'Support Riverside Food Bank',
    page_url:   'https://example.org/support-riverside-food-bank/',
};

const READY = { ...WITH_PAGE, payments: true, payment_methods: [ 'Stripe' ] };

const payload = ( firstRun ) => ( {
    range:         'last-30',
    test:          { includes_test: false, hidden: { donations: 0, plans: 0 } },
    review_prompt: false,
    first_run:     firstRun,
} );

let root;

/** Answers the dashboard's own request with each payload in turn, and any write with nothing. */
function dashboardAnswering( ...payloads ) {
    const answers = [ ...payloads ];
    apiFetch.mockImplementation( ( { path, method } ) => (
        ! method && path.startsWith( '/gratora/v1/admin/dashboard' )
            ? Promise.resolve( answers.length > 1 ? answers.shift() : answers[ 0 ] )
            : Promise.resolve( {} )
    ) );

    // The page before this one is gone, with whatever it was listening for.
    if ( root ) render( null, root );
    document.body.innerHTML = '';

    root = document.createElement( 'div' );
    document.body.appendChild( root );
    render( <Dashboard />, root );
}

const card = () => root.querySelector( '.gratora-firstrun' );
const pressable = ( label ) => [ ...root.querySelectorAll( 'button' ) ].find( ( b ) => b.textContent.trim() === label );
const loads = () => apiFetch.mock.calls.filter( ( [ { path, method } ] ) => ! method && path.startsWith( '/gratora/v1/admin/dashboard' ) ).length;

const realLocation = window.location;

beforeEach( () => {
    if ( root ) render( null, root );
    root = null;
    document.body.innerHTML = '';
    apiFetch.mockReset();
    window.sessionStorage.clear();
    delete window.location;
    window.location = { pathname: '/wp-admin/admin.php', search: '?page=gratora', hash: '', reload: jest.fn() };
} );

afterAll( () => {
    window.location = realLocation;
} );

test( 'a site with steps left sees the card, above everything else on the screen', async () => {
    dashboardAnswering( payload( NEW_SITE ) );
    await settle();

    const order = [ ...root.querySelector( '.gratora-dashboard' ).children ].map( ( part ) => part.className.split( ' ' )[ 0 ] );

    expect( card() ).not.toBeNull();
    expect( order.indexOf( 'gratora-firstrun' ) ).toBe( order.indexOf( 'gratora-page-head' ) + 1 );
    expect( order.indexOf( 'gratora-firstrun' ) ).toBeLessThan( order.indexOf( 'the-widgets' ) );
} );

test( 'a site with none left does not', async () => {
    dashboardAnswering( payload( null ) );
    await settle();

    expect( card() ).toBeNull();
} );

test( 'a press that changed the facts is followed by the new ones', async () => {
    dashboardAnswering( payload( NEW_SITE ), payload( WITH_PAGE ) );
    await settle();
    expect( card().textContent ).toContain( '0 of 4 done' );

    pressable( 'Create the page' ).click();
    await settle();

    expect( loads() ).toBe( 2 );
    expect( card().textContent ).toContain( '1 of 4 done' );
} );

// The admin bar's test mode badge is drawn by the server, so a switch of mode
// is followed by a fresh page rather than left beside a badge that is wrong.
test( 'switching test mode off loads the screen again', async () => {
    dashboardAnswering( payload( READY ) );
    await settle();

    pressable( 'Turn off test mode' ).click();
    await settle();

    expect( window.location.reload ).toHaveBeenCalledTimes( 1 );
} );

test( 'so does switching it on', async () => {
    dashboardAnswering( payload( { ...WITH_PAGE, test_mode: false } ) );
    await settle();

    pressable( 'Turn on test mode' ).click();
    await settle();

    expect( window.location.reload ).toHaveBeenCalledTimes( 1 );
} );

test( 'the screen that follows going live says so once, in place of the card', async () => {
    dashboardAnswering( payload( READY ) );
    await settle();
    pressable( 'Turn off test mode' ).click();
    await settle();

    dashboardAnswering( payload( null ) );
    await settle();

    expect( card() ).toBeNull();
    expect( root.textContent ).toContain( 'Test mode is off. Donations are real from now on.' );

    dashboardAnswering( payload( null ) );
    await settle();

    expect( root.textContent ).not.toContain( 'Test mode is off.' );
} );

test( 'switching test mode on is followed by no such line', async () => {
    dashboardAnswering( payload( { ...WITH_PAGE, test_mode: false } ) );
    await settle();
    pressable( 'Turn on test mode' ).click();
    await settle();

    // Answered with no card, which the switch on its own could not produce:
    // the direction of the switch is what has to keep the line away.
    dashboardAnswering( payload( null ) );
    await settle();

    expect( root.textContent ).not.toContain( 'Test mode is off.' );
} );

// Test mode can be turned off on a site that still has no page. That site is
// not live, keeps its card, and is not told that donations are real.
test( 'a site that turned test mode off and still has steps left is not told it is live', async () => {
    dashboardAnswering( payload( { ...NEW_SITE, payments: true, payment_methods: [ 'Stripe' ] } ) );
    await settle();
    pressable( 'Turn off test mode' ).click();
    await settle();

    dashboardAnswering( payload( { ...NEW_SITE, test_mode: false, payments: true, payment_methods: [ 'Stripe' ] } ) );
    await settle();

    expect( card() ).not.toBeNull();
    expect( root.textContent ).not.toContain( 'Test mode is off. Donations are real' );
} );

// The step it pushes hardest opens the donation page in another tab. Coming
// back from it has to show the donation that was just made.
test( 'coming back to the tab asks again while the card is showing', async () => {
    dashboardAnswering( payload( WITH_PAGE ), payload( { ...WITH_PAGE, test_donation: { amount_cents: 2603, currency: 'USD', donor: 'Maya Chen', url: '#' } } ) );
    await settle();
    expect( card().textContent ).toContain( '1 of 4 done' );

    document.dispatchEvent( new Event( 'visibilitychange' ) );
    await settle();

    expect( loads() ).toBe( 2 );
    expect( card().textContent ).toContain( '2 of 4 done' );
} );

test( 'coming back to a dashboard with no card asks for nothing', async () => {
    dashboardAnswering( payload( null ) );
    await settle();

    document.dispatchEvent( new Event( 'visibilitychange' ) );
    await settle();

    expect( loads() ).toBe( 1 );
} );

test( 'once the card is hidden, the keyboard is left on the heading of the screen', async () => {
    dashboardAnswering( payload( NEW_SITE ) );
    await settle();

    pressable( 'Hide' ).click();
    await settle();

    expect( document.activeElement.tagName ).toBe( 'H1' );
} );

test( 'a site that was live all along is told nothing of the kind', async () => {
    dashboardAnswering( payload( null ) );
    await settle();

    expect( root.textContent ).not.toContain( 'Test mode is off.' );
} );

test( 'hiding the card removes it at once', async () => {
    dashboardAnswering( payload( NEW_SITE ) );
    await settle();

    pressable( 'Hide' ).click();
    await settle();

    expect( card() ).toBeNull();
} );
