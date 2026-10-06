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

    root = document.createElement( 'div' );
    document.body.appendChild( root );
    render( <Dashboard />, root );
}

const card = () => root.querySelector( '.gratora-firstrun' );
const pressable = ( label ) => [ ...root.querySelectorAll( 'button' ) ].find( ( b ) => b.textContent.trim() === label );
const loads = () => apiFetch.mock.calls.filter( ( [ { path, method } ] ) => ! method && path.startsWith( '/gratora/v1/admin/dashboard' ) ).length;

beforeEach( () => {
    document.body.innerHTML = '';
    apiFetch.mockReset();
    window.history.replaceState( null, '', '/wp-admin/admin.php?page=gratora' );
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

test( 'the press that makes the site live is answered with one line, in place of the card', async () => {
    dashboardAnswering( payload( READY ), payload( null ) );
    await settle();

    pressable( 'Turn off test mode' ).click();
    await settle();

    expect( card() ).toBeNull();
    expect( root.textContent ).toContain( 'Test mode is off. Donations are real from now on.' );
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
