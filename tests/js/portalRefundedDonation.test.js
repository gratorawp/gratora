/**
 * The lifetime figure on the overview is net of refunds; a donation row is the
 * gross amount. On a partly refunded donation the two disagree by exactly the
 * refund, and the donor is left to guess which number is wrong. The row has to
 * say what came back.
 *
 * Mounts the real portal client: the disagreement is between two screens, not
 * inside either one.
 */

let routes = {};

function jsonResponse( status, body ) {
    return Promise.resolve( {
        ok:      status >= 200 && status < 300,
        status,
        headers: { get: () => 'application/json' },
        json:    () => Promise.resolve( body ),
    } );
}

function me( overrides = {} ) {
    return {
        id:                  4,
        name:                'Alice Okafor',
        first_name:          'Alice',
        last_name:           'Okafor',
        country:             '',
        // Net of the $10.00 refund below.
        total_donated_cents: 4000,
        unconverted_count:   0,
        donations_count:     1,
        first_donation_at:   '2026-08-19 14:54:06',
        last_donation_at:    '2026-08-19 14:54:06',
        primary_currency:    'USD',
        csrf:                'csrf-token',
        consents_pending:    0,
        ...overrides,
    };
}

function donation( overrides = {} ) {
    return {
        id:                10,
        reference:         'DONO-2026-00001',
        amount_cents:      5000,
        fee_covered_cents: 0,
        refunded_cents:    1000,
        currency:          'USD',
        frequency:         'one_time',
        gateway:           'offline',
        campaign_id:       null,
        form_id:           null,
        paid_at:           '2026-08-19 14:54:06',
        is_anonymous:      false,
        give_again_url:    null,
        ...overrides,
    };
}

// Waits for what the next assertion needs rather than for a fixed span. A
// sleep long enough on one machine is short on a loaded one, and a screen that
// has not rendered yet reads as a screen that renders nothing, so a test built
// on sleeps both flakes and passes for the wrong reason.
async function until( predicate, what ) {
    for ( let i = 0; i < 200; i++ ) {
        if ( predicate() ) return;
        await new Promise( ( r ) => setTimeout( r, 5 ) );
    }

    throw new Error( `timed out waiting for ${ what }; screen was: ${ text() }` );
}

// The runtime mounts itself on import, so each test needs its own module copy.
async function boot() {
    document.body.innerHTML = '<div id="dono-donor-portal"></div>';

    jest.isolateModules( () => {
        require( '../../assets/donor-portal/index.jsx' );
    } );

    await until(
        () => [ ...document.querySelectorAll( 'button' ) ].some( ( b ) => b.textContent.trim() === 'Donations' ),
        'the portal to finish loading'
    );
}

function text() {
    return document.getElementById( 'dono-donor-portal' ).textContent;
}

async function clickButton( label ) {
    const button = [ ...document.querySelectorAll( 'button' ) ]
        .find( ( b ) => b.textContent.trim() === label );
    expect( button ).toBeTruthy();
    button.click();

    await until( () => document.querySelector( '.dp-list__row' ), `${ label } to render its rows` );
}

async function openRow() {
    document.querySelector( '.dp-list__row' ).click();

    // The detail head, which every donation has, so this waits for the screen
    // rather than for the refund block a test may be asserting is absent.
    await until( () => document.querySelector( '.dp-detail__head' ), 'the donation detail to render' );
}

beforeEach( () => {
    routes = {};
    window.history.replaceState( {}, '', '/portal/' );
    window.donoPortal = { rest: '/wp-json/dono/v1/portal/', nonce: '', token: 'portal-token' };
    window.dono = {
        default_currency: 'USD',
        number_format: { decimalPlaces: 2, decimalSep: '.', thousandSep: ',', symbolPosition: 'before', symbol: '$' },
    };
    global.fetch = jest.fn( ( url ) => {
        const path  = String( url ).replace( '/wp-json/dono/v1/portal/', '' );
        const route = routes[ path ];
        if ( typeof route === 'function' ) return route();

        return jsonResponse( 200, {} );
    } );
} );

test( 'the row says what came back, so it can be read against a net lifetime total', async () => {
    routes.me = () => jsonResponse( 200, me() );
    routes.donations = () => jsonResponse( 200, [ donation() ] );

    await boot();
    await clickButton( 'Donations' );

    expect( text() ).toContain( '$50.00' );
    expect( text() ).toContain( '$10.00 refunded' );
} );

test( 'the donation itself states the refund and what the organization kept', async () => {
    routes.me = () => jsonResponse( 200, me() );
    routes.donations = () => jsonResponse( 200, [ donation() ] );
    routes[ 'donations/DONO-2026-00001' ] = () => jsonResponse( 200, donation() );

    await boot();
    await clickButton( 'Donations' );
    await openRow();

    expect( document.querySelector( '.dp-detail__refund' ).textContent )
        .toContain( '$10.00 was refunded to you' );
    expect( document.querySelector( '.dp-detail__refund' ).textContent )
        .toContain( 'Net $40.00' );
} );

test( 'a donation nobody refunded says nothing about refunds', async () => {
    routes.me = () => jsonResponse( 200, me( { total_donated_cents: 5000 } ) );
    routes.donations = () => jsonResponse( 200, [ donation( { refunded_cents: 0 } ) ] );
    routes[ 'donations/DONO-2026-00001' ] = () => jsonResponse( 200, donation( { refunded_cents: 0 } ) );

    await boot();
    await clickButton( 'Donations' );

    expect( text() ).toContain( '$50.00' );
    expect( text() ).not.toContain( 'refunded' );

    await openRow();

    expect( document.querySelector( '.dp-detail__refund' ) ).toBeNull();
} );
