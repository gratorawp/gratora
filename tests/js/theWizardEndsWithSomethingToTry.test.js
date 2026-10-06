/**
 * The setup wizard used to end on a list of what was still missing, payment
 * keys first. It ends on something to try: one button that makes a donation
 * page and opens it, and the demo for anyone who would rather look first.
 */

import { render } from 'preact';
import apiFetch from '@wordpress/api-fetch';

import { ChecklistStep } from '../../assets/admin/onboarding/Onboarding';

const { settle } = require( './support/waitFor' );

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

const PAGE      = 'https://example.org/support-riverside-food-bank/';
const DASHBOARD = '/wp-admin/admin.php?page=gratora';
const CAMPAIGNS = '/wp-admin/admin.php?page=gratora-campaigns';

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

let root;
const realLocation = window.location;

function lastScreen( given ) {
    root = document.createElement( 'div' );
    document.body.appendChild( root );
    render( <ChecklistStep facts={ given } dashboardUrl={ DASHBOARD } campaignsUrl={ CAMPAIGNS } />, root );
    return root;
}

const items = () => [ ...root.querySelectorAll( '.gratora-onboarding__checklist > li' ) ];

const read = ( item ) => ( {
    title: item.querySelector( '.gratora-onboarding__checklist-title' ).textContent,
    text:  item.querySelector( '.gratora-onboarding__checklist-desc' ).textContent,
    cta:   item.querySelector( '.gratora-btn' ).textContent.replace( '(opens in a new tab)', '' ).trim(),
} );

const cta = ( item ) => item.querySelector( '.gratora-btn' );

beforeEach( () => {
    document.body.innerHTML = '';
    apiFetch.mockReset();
    delete window.location;
    window.location = { href: DASHBOARD };
} );

afterAll( () => {
    window.location = realLocation;
} );

test( 'it says setup is done and what comes next', () => {
    lastScreen( facts() );

    expect( root.querySelector( '.gratora-onboarding__headline' ).textContent ).toBe( "You're set up" );
    expect( root.querySelector( '.gratora-onboarding__subtitle' ).textContent )
        .toBe( 'Your organization details are saved. Next, see a donation go through.' );
} );

describe( 'the first thing it offers', () => {
    test( 'a new site: a page made for it, and a test donation on it', () => {
        lastScreen( facts() );

        expect( read( items()[ 0 ] ) ).toEqual( {
            title: 'Make a test donation',
            text:  'We will add one donation page to your site, named after your organization, and open it. Test mode is on, so no card is charged.',
            cta:   'Create the page and try it',
        } );
    } );

    test( 'a site with test mode off: the page, with no promise of a test', () => {
        lastScreen( facts( { test_mode: false } ) );

        expect( read( items()[ 0 ] ) ).toEqual( {
            title: 'Create your donation page',
            text:  'We will add one donation page to your site, named after your organization, and open it.',
            cta:   'Create the page',
        } );
    } );

    test( 'a site whose campaigns take no donations: the way to them, and no second campaign', () => {
        lastScreen( facts( { page: 'closed' } ) );

        expect( read( items()[ 0 ] ) ).toEqual( {
            title: 'Open a campaign for donations',
            text:  'You have a campaign, but none of them is taking donations right now.',
            cta:   'Open campaigns',
        } );
        expect( cta( items()[ 0 ] ).getAttribute( 'href' ) ).toBe( CAMPAIGNS );
    } );

    test( 'a site that has a live page and test mode on: a test donation on that page', () => {
        lastScreen( facts( { page: 'live', page_title: 'Support Riverside Food Bank', page_url: PAGE } ) );

        expect( read( items()[ 0 ] ) ).toEqual( {
            title: 'Make a test donation',
            text:  'Open your page and give with the Test donation method. No card is charged.',
            cta:   'Open the page',
        } );
        expect( cta( items()[ 0 ] ).getAttribute( 'href' ) ).toBe( PAGE );
    } );

    test( 'a site that has a live page and test mode off: the page, and nothing about a test', () => {
        lastScreen( facts( { page: 'live', page_title: 'Support Riverside Food Bank', page_url: PAGE, test_mode: false } ) );

        expect( read( items()[ 0 ] ) ).toEqual( {
            title: 'Your donation page',
            text:  'Your page is published.',
            cta:   'Open the page',
        } );
    } );
} );

test( 'the button makes the page and sends this tab to it', async () => {
    apiFetch.mockResolvedValue( { campaign_id: 7, page_url: PAGE } );
    lastScreen( facts() );

    cta( items()[ 0 ] ).click();
    await settle();

    expect( apiFetch ).toHaveBeenCalledWith( { path: '/gratora/v1/admin/onboarding/starter-campaign', method: 'POST' } );
    expect( window.location.href ).toBe( PAGE );
} );

test( 'a second press while the page is being made sends nothing more', async () => {
    apiFetch.mockReturnValue( new Promise( () => {} ) );
    lastScreen( facts() );

    cta( items()[ 0 ] ).click();
    await settle();
    cta( items()[ 0 ] ).click();
    await settle();

    expect( apiFetch ).toHaveBeenCalledTimes( 1 );
} );

test( 'a page that could not be made says why, and the button works again', async () => {
    apiFetch.mockRejectedValue( { message: 'This site already has a campaign.' } );
    lastScreen( facts() );

    cta( items()[ 0 ] ).click();
    await settle();

    expect( root.querySelector( '[role="alert"]' ).textContent ).toBe( 'This site already has a campaign.' );
    expect( window.location.href ).toBe( DASHBOARD );
    expect( cta( items()[ 0 ] ).disabled ).toBe( false );
} );

test( 'the second thing it offers is the demo, in a new tab, marked as coming from setup', () => {
    lastScreen( facts() );

    expect( read( items()[ 1 ] ) ).toEqual( {
        title: 'See it with a year of sample data',
        text:  'A demo site opens in a new tab. Nothing is added to your site.',
        cta:   'Open the demo',
    } );
    expect( cta( items()[ 1 ] ).getAttribute( 'href' ) ).toBe( 'https://gratora.net/demo/?utm_source=plugin&utm_medium=setup' );
    expect( cta( items()[ 1 ] ).getAttribute( 'target' ) ).toBe( '_blank' );
    expect( cta( items()[ 1 ] ).textContent ).toContain( '(opens in a new tab)' );
} );

test( 'only the first button is filled', () => {
    lastScreen( facts() );

    expect( cta( items()[ 0 ] ).className ).toContain( 'gratora-btn--primary' );
    expect( cta( items()[ 1 ] ).className ).not.toContain( 'gratora-btn--primary' );
} );

test( 'there are two things on it and neither asks for payment keys', () => {
    lastScreen( facts() );

    expect( items() ).toHaveLength( 2 );
    expect( root.textContent ).not.toMatch( /gateway/i );
} );

test( 'the way out leads to the dashboard', () => {
    lastScreen( facts() );

    const out = root.querySelector( '.gratora-onboarding__checklist-skip' );

    expect( out.textContent ).toBe( 'Go to the dashboard' );
    expect( out.getAttribute( 'href' ) ).toBe( DASHBOARD );
} );
