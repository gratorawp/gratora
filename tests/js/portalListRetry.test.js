/**
 * A 500 or a dropped connection replaced a whole portal tab with one red
 * sentence and nothing else. Switching tabs and back would re-mount and retry,
 * but nothing on screen said so, so the donor's only route out was knowing to
 * reload the page.
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
        id: 4, name: 'Alice Okafor', first_name: 'Alice', last_name: 'Okafor',
        country: 'DE', total_donated_cents: 4000, unconverted_count: 0,
        donations_count: 2, first_donation_at: '2026-08-19 14:54:06',
        last_donation_at: '2026-08-19 14:54:06', primary_currency: 'USD',
        csrf: 'csrf-token', consents_pending: 0,
        ...overrides,
    };
}

const donation = ( i ) => ( {
    id: i, reference: `FUNDKIT-${ i }`, amount_cents: 1000, fee_covered_cents: 0,
    refunded_cents: 0, currency: 'USD', frequency: 'one_time',
    paid_at: '2026-08-19 14:54:06', is_anonymous: false,
} );

async function until( predicate, what ) {
    for ( let i = 0; i < 200; i++ ) {
        if ( predicate() ) return;
        await new Promise( ( r ) => setTimeout( r, 5 ) );
    }
    throw new Error( `timed out waiting for ${ what }` );
}

async function settle() {
    for ( let i = 0; i < 5; i++ ) {
        await new Promise( ( r ) => requestAnimationFrame( () => r() ) );
        await new Promise( ( r ) => setTimeout( r, 0 ) );
    }
}

const button = ( label ) => [ ...document.querySelectorAll( 'button' ) ]
    .find( ( b ) => b.textContent.trim() === label );

const text = () => document.getElementById( 'fundkit-donor-portal' ).textContent;

async function mount() {
    routes.me = () => jsonResponse( 200, me() );
    document.body.innerHTML = '<div id="fundkit-donor-portal"></div>';
    jest.isolateModules( () => {
        require( '../../assets/donor-portal/index.jsx' );
    } );
    await until( () => button( 'Donations' ), 'the portal to load' );
    await settle();
}

beforeEach( () => {
    routes = {};
    window.history.replaceState( {}, '', '/portal/' );
    window.fundkitPortal = { rest: '/wp-json/fundkit/v1/portal/', nonce: '', token: 'portal-token' };
    window.fundkit = {
        default_currency: 'USD',
        number_format: { decimalPlaces: 2, decimalSep: '.', thousandSep: ',', symbolPosition: 'before', symbol: '$' },
    };
    global.fetch = jest.fn( ( url ) => {
        const path  = String( url ).replace( '/wp-json/fundkit/v1/portal/', '' );
        const route = routes[ path ];

        return typeof route === 'function' ? route() : jsonResponse( 200, {} );
    } );
} );

function failsOnce( body ) {
    let calls = 0;

    return () => {
        calls += 1;

        return calls === 1
            ? jsonResponse( 500, { message: 'Request failed' } )
            : jsonResponse( 200, body );
    };
}

it( 'offers a way back after a failed donations load', async () => {
    routes.donations = failsOnce( { items: [ donation( 1 ) ], total: 1 } );

    await mount();
    button( 'Donations' ).click();
    await until( () => /Request failed/.test( text() ), 'the failure to render' );

    const retry = button( 'Try again' );
    expect( retry ).toBeTruthy();

    retry.click();
    await until( () => document.querySelector( '.dp-list__row' ), 'the list to render on the retry' );

    expect( text() ).not.toContain( 'Request failed' );
} );

it( 'and after a failed recurring load', async () => {
    routes.recurring = failsOnce( [] );

    await mount();
    button( 'Recurring' ).click();
    await until( () => /Request failed/.test( text() ), 'the failure to render' );

    expect( button( 'Try again' ) ).toBeTruthy();

    button( 'Try again' ).click();
    await until( () => ! /Request failed/.test( text() ), 'the retry to clear the failure' );
} );

it( 'and after a failed preferences load', async () => {
    routes.preferences = failsOnce( { email_optin: true } );

    await mount();
    button( 'Preferences' ).click();
    await until( () => /Request failed/.test( text() ), 'the failure to render' );

    expect( button( 'Try again' ) ).toBeTruthy();

    button( 'Try again' ).click();
    await until( () => ! /Request failed/.test( text() ), 'the retry to clear the failure' );
} );
