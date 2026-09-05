/**
 * A donor can move their own cadence where the processor allows it. Moving
 * from monthly to weekly quadruples what they give in a year, so the control
 * has to say that before they commit to it.
 *
 * Mounts the real portal client, because the capability comes off the payload
 * and the button has to be absent on a rail that cannot honour it.
 */

let routes = {};
let posted = [];

function jsonResponse( status, body ) {
    return Promise.resolve( {
        ok:      status >= 200 && status < 300,
        status,
        headers: { get: () => 'application/json' },
        json:    () => Promise.resolve( body ),
    } );
}

function me() {
    return {
        id:                  4,
        name:                'Alice Okafor',
        first_name:          'Alice',
        last_name:           'Okafor',
        country:             '',
        total_donated_cents: 4000,
        unconverted_count:   0,
        donations_count:     1,
        first_donation_at:   '2026-08-19 14:54:06',
        last_donation_at:    '2026-08-19 14:54:06',
        primary_currency:    'USD',
        csrf:                'csrf-token',
        consents_pending:    0,
    };
}

function plan( overrides = {} ) {
    return {
        id:                  38,
        status:              'active',
        amount_cents:        2500,
        currency:            'USD',
        interval_unit:       'month',
        interval_count:      1,
        next_payment_at:     '2026-10-01 09:00:00',
        gateway:             'stripe',
        can_pause:           true,
        can_update_payment_method: true,
        can_change_interval: true,
        frequency:           'monthly',
        frequency_options:   [ 'weekly', 'biweekly', 'monthly', 'quarterly', 'yearly' ],
        ...overrides,
    };
}

async function until( predicate, what ) {
    for ( let i = 0; i < 200; i++ ) {
        if ( predicate() ) return;
        await new Promise( ( r ) => setTimeout( r, 5 ) );
    }

    throw new Error( `timed out waiting for ${ what }; screen was: ${ text() }` );
}

function text() {
    return document.getElementById( 'fundkit-donor-portal' ).textContent;
}

function button( label ) {
    return [ ...document.querySelectorAll( 'button' ) ].find( ( b ) => b.textContent.trim() === label );
}

async function openSheet( row = plan() ) {
    routes.me = () => jsonResponse( 200, me() );
    routes.recurring = () => jsonResponse( 200, [ row ] );

    document.body.innerHTML = '<div id="fundkit-donor-portal"></div>';
    jest.isolateModules( () => {
        require( '../../assets/donor-portal/index.jsx' );
    } );

    await until( () => button( 'Recurring' ), 'the portal to finish loading' );
    button( 'Recurring' ).click();
    await until( () => document.querySelector( '.dp-list__row' ), 'the recurring list to render' );

    document.querySelector( '.dp-list__row' ).click();
    await until( () => document.querySelector( '.dp-modal' ), 'the manage sheet to open' );
}

beforeEach( () => {
    routes = {};
    posted  = [];
    window.history.replaceState( {}, '', '/portal/' );
    window.fundkitPortal = { rest: '/wp-json/fundkit/v1/portal/', nonce: '', token: 'portal-token' };
    window.fundkit = {
        default_currency: 'USD',
        number_format: { decimalPlaces: 2, decimalSep: '.', thousandSep: ',', symbolPosition: 'before', symbol: '$' },
    };
    global.fetch = jest.fn( ( url, init ) => {
        const path = String( url ).replace( '/wp-json/fundkit/v1/portal/', '' );
        if ( init && init.method === 'POST' ) {
            posted.push( { path, body: JSON.parse( init.body ) } );
        }
        const route = routes[ path ];
        if ( typeof route === 'function' ) return route();

        return jsonResponse( 200, {} );
    } );
} );

test( 'a donor sends the frequency they picked, not the one they started on', async () => {
    await openSheet();

    button( 'Change frequency' ).click();
    await until( () => document.querySelector( '.dp-modal select' ), 'the frequency control to render' );

    const select = document.querySelector( '.dp-modal select' );
    select.value = 'weekly';
    select.dispatchEvent( new Event( 'change', { bubbles: true } ) );

    await until( () => ! button( 'Save new frequency' ).disabled, 'the save button to enable' );
    button( 'Save new frequency' ).click();

    await until( () => posted.some( ( p ) => p.path === 'recurring/38/action' ), 'the action to be sent' );
    expect( posted.find( ( p ) => p.path === 'recurring/38/action' ).body )
        .toEqual( { action: 'change_interval', frequency: 'weekly' } );
} );

test( 'the yearly total moves with the cadence, so a shorter one reads as more money', async () => {
    await openSheet();

    button( 'Change frequency' ).click();
    await until( () => document.querySelector( '.dp-modal select' ), 'the frequency control to render' );

    // $25.00 monthly.
    expect( text() ).toContain( '$300.00' );

    const select = document.querySelector( '.dp-modal select' );
    select.value = 'weekly';
    select.dispatchEvent( new Event( 'change', { bubbles: true } ) );

    await until( () => text().includes( '$1,300.00' ), 'the yearly total to follow the selection' );
    expect( text() ).not.toContain( '$300.00 a year' );
} );

test( 'picking the cadence they already have sends nothing', async () => {
    await openSheet();

    button( 'Change frequency' ).click();
    await until( () => document.querySelector( '.dp-modal select' ), 'the frequency control to render' );

    expect( document.querySelector( '.dp-modal select' ).value ).toBe( 'monthly' );
    expect( button( 'Save new frequency' ).disabled ).toBe( true );
    expect( posted ).toEqual( [] );
} );

test( 'a rail that cannot move a mandate does not offer the control', async () => {
    await openSheet( plan( { can_change_interval: false, gateway: 'gocardless' } ) );

    expect( button( 'Change frequency' ) ).toBeUndefined();
    expect( button( 'Change amount' ) ).toBeTruthy();
} );

test( 'a finished subscription is offered no changes at all', async () => {
    await openSheet( plan( { status: 'cancelled' } ) );

    expect( button( 'Change frequency' ) ).toBeUndefined();
    expect( button( 'Change amount' ) ).toBeUndefined();
} );
