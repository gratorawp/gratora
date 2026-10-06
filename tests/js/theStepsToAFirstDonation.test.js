/**
 * The dashboard card walks a new site to its first real donation in four steps:
 * a page, a test donation, a way to take money, test mode off. Each step is read
 * from one fact the server sent, and says what to do next only when doing it
 * would work.
 */

import { firstRunSteps, primaryStep } from '../../assets/admin/dashboard/firstRunSteps';

const HREFS = {
    campaigns: '/wp-admin/admin.php?page=gratora-campaigns',
    payments:  '/wp-admin/admin.php?page=gratora-settings#gateways',
};

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

const TEST_DONATION = {
    amount_cents: 2603,
    currency:     'USD',
    donor:        'Maya Chen',
    url:          '/wp-admin/admin.php?page=gratora-donations&include_test=1',
};

const step = ( given, key ) => firstRunSteps( given, HREFS ).find( ( s ) => s.key === key );

test( 'there are four, in the order a site goes through them', () => {
    expect( firstRunSteps( facts(), HREFS ).map( ( s ) => [ s.key, s.title ] ) ).toEqual( [
        [ 'page', 'Create your donation page' ],
        [ 'test', 'Make a test donation' ],
        [ 'payments', 'Connect payments' ],
        [ 'live', 'Go live' ],
    ] );
} );

describe( 'the donation page', () => {
    test( 'a site with no campaign is offered one', () => {
        expect( step( facts(), 'page' ) ).toMatchObject( {
            done:   false,
            text:   'One page with a donation form, named after your organization. You can rename it, and delete it if you never use it.',
            action: { kind: 'create', label: 'Create the page' },
        } );
    } );

    test( 'a site whose campaigns are not published is sent to them, not given another', () => {
        expect( step( facts( { page: 'unpublished' } ), 'page' ) ).toMatchObject( {
            done:   false,
            text:   'You have a campaign, but none is published, so no page is taking donations yet.',
            action: { kind: 'link', label: 'Open campaigns', href: HREFS.campaigns },
        } );
    } );

    test( 'a live page is named and can be opened', () => {
        expect( step( withPage(), 'page' ) ).toMatchObject( {
            done:   true,
            text:   'Support Riverside Food Bank',
            action: { kind: 'link', label: 'View the page', href: PAGE, newTab: true },
        } );
    } );
} );

describe( 'the test donation', () => {
    test( 'it waits for a page', () => {
        expect( step( facts(), 'test' ) ).toMatchObject( {
            done:   false,
            text:   'Needs a donation page first.',
            action: null,
        } );
    } );

    test( 'with a page and test mode on, the page is one click away', () => {
        expect( step( withPage(), 'test' ) ).toMatchObject( {
            done:   false,
            text:   'Give with the Test donation method. No card is charged.',
            action: { kind: 'link', label: 'Open your donation page', href: PAGE, newTab: true },
        } );
    } );

    test( 'with test mode off and no way to take money, turning it on is offered', () => {
        expect( step( withPage( { test_mode: false } ), 'test' ) ).toMatchObject( {
            done:   false,
            text:   'Test mode lets you try a donation with no card. This site cannot take a real donation yet, so nothing is lost by turning it on.',
            action: { kind: 'test-on', label: 'Turn on test mode' },
        } );
    } );

    test( 'a site that already takes real donations is never offered test mode', () => {
        const given = withPage( { test_mode: false, payments: true, payment_methods: [ 'Stripe' ] } );

        expect( step( given, 'test' ) ).toMatchObject( {
            done:   false,
            text:   'This site already takes real donations.',
            action: null,
        } );
    } );

    test( 'the one that came in is shown, with who gave it', () => {
        expect( step( withPage( { test_donation: TEST_DONATION } ), 'test' ) ).toMatchObject( {
            done:   true,
            text:   'A $26.03 test donation from Maya Chen came in.',
            action: { kind: 'link', label: 'View it', href: TEST_DONATION.url },
        } );
    } );

    test( 'with no name to show, it is shown without one', () => {
        const given = withPage( { test_donation: { ...TEST_DONATION, donor: null } } );

        expect( step( given, 'test' ).text ).toBe( 'A $26.03 test donation came in.' );
    } );

    test( 'once it has come in it stays done, whatever happens to the page', () => {
        expect( step( facts( { page: 'unpublished', test_donation: TEST_DONATION } ), 'test' ).done ).toBe( true );
    } );
} );

describe( 'payments', () => {
    test( 'a site with nothing connected is sent to the payment settings', () => {
        expect( step( facts(), 'payments' ) ).toMatchObject( {
            done:   false,
            text:   'Add your Stripe or PayPal keys to take cards, or write bank details to take transfers.',
            action: { kind: 'link', label: 'Connect payments', href: HREFS.payments },
        } );
    } );

    test( 'what is connected is named', () => {
        const given = facts( { payments: true, payment_methods: [ 'Stripe', 'Offline donations' ] } );

        expect( step( given, 'payments' ) ).toMatchObject( {
            done:   true,
            text:   'Ready: Stripe, Offline donations',
            action: null,
        } );
    } );
} );

describe( 'going live', () => {
    test( 'with payments connected, test mode can be turned off', () => {
        expect( step( facts( { payments: true, payment_methods: [ 'Stripe' ] } ), 'live' ) ).toMatchObject( {
            done:   false,
            text:   'Turn off test mode. From then on, every donation is real and counts in your figures.',
            action: { kind: 'test-off', label: 'Turn off test mode' },
        } );
        expect( step( facts( { payments: true, payment_methods: [ 'Stripe' ] } ), 'live' ).action.disabled ).toBeFalsy();
    } );

    test( 'without payments the button is there and cannot be pressed, and says why', () => {
        expect( step( facts(), 'live' ) ).toMatchObject( {
            done:   false,
            text:   'Connect payments first. With test mode off and no payment method, the form could take nothing.',
            action: { kind: 'test-off', label: 'Turn off test mode', disabled: true },
        } );
    } );

    test( 'with test mode already off and no payments, there is nothing to press', () => {
        expect( step( facts( { test_mode: false } ), 'live' ) ).toMatchObject( {
            done:   false,
            text:   'Test mode is already off. The site is live as soon as payments are connected.',
            action: null,
        } );
    } );

    test( 'it is done when test mode is off and something can take money', () => {
        const given = facts( { test_mode: false, payments: true, payment_methods: [ 'Stripe' ] } );

        expect( step( given, 'live' ) ).toMatchObject( { done: true, action: null } );
    } );
} );

describe( 'which step carries the button', () => {
    const primary = ( given ) => primaryStep( firstRunSteps( given, HREFS ) );

    test( 'a new site: the page', () => {
        expect( primary( facts() ) ).toBe( 'page' );
    } );

    test( 'once there is a page: the test donation', () => {
        expect( primary( withPage() ) ).toBe( 'test' );
    } );

    test( 'once that has come in: payments', () => {
        expect( primary( withPage( { test_donation: TEST_DONATION } ) ) ).toBe( 'payments' );
    } );

    test( 'once payments are connected: going live', () => {
        const given = withPage( { test_donation: TEST_DONATION, payments: true, payment_methods: [ 'Stripe' ] } );

        expect( primary( given ) ).toBe( 'live' );
    } );

    test( 'a site whose campaigns are not published: still the page', () => {
        expect( primary( facts( { page: 'unpublished', test_donation: TEST_DONATION } ) ) ).toBe( 'page' );
    } );
} );
