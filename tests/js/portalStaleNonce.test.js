/**
 * A donor who is also a WordPress user gets an X-WP-Nonce baked into the portal
 * page at render. It dies on the ordinary wp_rest tick, or the moment they log
 * out of WordPress in another tab, while their portal session stays perfectly
 * good. WordPress refuses a present-and-stale nonce at the authentication
 * layer, ahead of every permission callback, so from that moment the whole
 * portal is gone and the sign-in that would recover it is refused too.
 */

const { settle } = require( './support/waitFor' );

const STALE = 'stale-n0nce';

let routes = {};
let seen   = [];

function jsonResponse( status, body ) {
    return Promise.resolve( {
        ok:      status >= 200 && status < 300,
        status,
        headers: { get: () => 'application/json' },
        json:    () => Promise.resolve( body ),
    } );
}

const nonceRefused = () => jsonResponse( 403, {
    code:    'rest_cookie_invalid_nonce',
    message: 'Cookie check failed',
} );

function me( overrides = {} ) {
    return {
        id:                  4,
        name:                'Alice Okafor',
        first_name:          'Alice',
        last_name:           'Okafor',
        country:             '',
        total_donated_cents: 0,
        unconverted_count:   0,
        donations_count:     0,
        first_donation_at:   null,
        last_donation_at:    null,
        primary_currency:    'USD',
        csrf:                'csrf-token',
        consents_pending:    0,
        ...overrides,
    };
}

/** Every route answers the nonce-bearing attempt with the refusal core makes. */
function refuseTheNonce( path, thenReturn ) {
    routes[ path ] = ( nonce ) => ( nonce ? nonceRefused() : thenReturn() );
}

async function boot() {
    document.body.innerHTML = '<div id="gratora-donor-portal"></div>';

    jest.isolateModules( () => {
        require( '../../assets/donor-portal/index.jsx' );
    } );

    await settle();
}

const text = () => document.getElementById( 'gratora-donor-portal' ).textContent;

const noncesFor = ( path ) => seen.filter( ( s ) => s.path === path ).map( ( s ) => s.nonce );

function type( selector, value ) {
    const field = document.querySelector( selector );
    expect( field ).toBeTruthy();
    field.value = value;
    field.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );

    return settle();
}

function submitForm() {
    const form = document.querySelector( '.dp-signin form' );
    expect( form ).toBeTruthy();
    form.dispatchEvent( new window.Event( 'submit', { bubbles: true, cancelable: true } ) );

    return settle();
}

function clickButton( label ) {
    const button = [ ...document.querySelectorAll( 'button' ) ]
        .find( ( b ) => b.textContent.trim() === label );
    expect( button ).toBeTruthy();
    button.click();

    return settle();
}

beforeEach( () => {
    routes = {};
    seen   = [];
    window.history.replaceState( {}, '', '/portal/' );
    window.gratoraPortal = { rest: '/wp-json/gratora/v1/portal/', nonce: STALE, token: 'portal-token' };
    window.gratora = {
        default_currency: 'USD',
        number_format: { decimalPlaces: 2, decimalSep: '.', thousandSep: ',', symbolPosition: 'before', symbol: '$' },
    };

    global.fetch = jest.fn( ( url, init = {} ) => {
        const path  = String( url ).replace( '/wp-json/gratora/v1/portal/', '' );
        const nonce = ( init.headers || {} )[ 'X-WP-Nonce' ] || '';
        seen.push( { path, nonce } );

        const route = routes[ path ];
        if ( typeof route === 'function' ) return route( nonce );

        return jsonResponse( 200, {} );
    } );
} );

test( 'a stale nonce does not throw a signed-in donor out of the portal', async () => {
    refuseTheNonce( 'me', () => jsonResponse( 200, me() ) );

    await boot();

    expect( noncesFor( 'me' ).slice( 0, 2 ) ).toEqual( [ STALE, '' ] );
    expect( text() ).toContain( 'Hi, Alice.' );
    expect( text() ).not.toContain( 'Your session expired' );
    expect( text() ).not.toContain( 'Cookie check failed' );
} );

test( 'and the dead nonce is not handed on to the add-on tabs', async () => {
    refuseTheNonce( 'me', () => jsonResponse( 200, me() ) );

    await boot();

    // extContext passes cfg.nonce straight to every add-on panel, which has its
    // own client and would go on paying for a nonce proven dead here.
    expect( window.gratoraPortal.nonce ).toBe( '' );
    expect( seen.filter( ( s ) => s.nonce !== '' ) ).toHaveLength( 1 );
} );

/**
 * The nonce usually dies mid-session, on the ordinary wp_rest tick or when the
 * donor logs out of WordPress in another tab, so the refusal arrives while the
 * portal is already open and the sign-out bounce is armed.
 */
test( 'a nonce that dies mid-session does not bounce the donor to sign-in', async () => {
    routes.me = () => jsonResponse( 200, me() );
    refuseTheNonce( 'profile', () => jsonResponse( 200, { first_name: 'Alice', last_name: 'Okafor' } ) );

    await boot();
    await clickButton( 'Profile' );

    expect( noncesFor( 'profile' ) ).toEqual( [ STALE, '' ] );
    expect( text() ).not.toContain( 'Your session expired' );
    expect( text() ).not.toContain( 'Cookie check failed' );
} );

test( 'a stale nonce does not refuse the sign-in that would recover from it', async () => {
    routes.me = () => jsonResponse( 401, { code: 'gratora_no_session', message: 'Not signed in.' } );
    refuseTheNonce( 'send-link', () => jsonResponse( 200, { ok: true } ) );

    await boot();

    await type( '.dp-signin input[type="email"]', 'alice@example.test' );
    await submitForm();

    expect( noncesFor( 'send-link' ) ).toEqual( [ STALE, '' ] );
    expect( text() ).toContain( 'Check your email' );
    expect( text() ).not.toContain( 'Cookie check failed' );
} );

test( 'a stale nonce does not refuse a data export', async () => {
    routes.me = () => jsonResponse( 200, me() );
    refuseTheNonce( 'data-export', () => Promise.resolve( {
        ok:      true,
        status:  200,
        headers: { get: () => 'application/json' },
        blob:    () => Promise.resolve( { size: 2 } ),
    } ) );

    const clicked = [];
    window.URL.createObjectURL  = () => 'blob:x';
    window.URL.revokeObjectURL  = () => {};
    const realClick = window.HTMLAnchorElement.prototype.click;
    window.HTMLAnchorElement.prototype.click = function () { clicked.push( this.download ); };

    try {
        await boot();
        await clickButton( 'Profile' );
        await clickButton( 'Download my data' );
    } finally {
        window.HTMLAnchorElement.prototype.click = realClick;
    }

    expect( noncesFor( 'data-export' ) ).toEqual( [ STALE, '' ] );
    expect( clicked ).toEqual( [ 'my-data.json' ] );
    expect( text() ).not.toContain( 'Export failed.' );
    expect( text() ).not.toContain( 'Cookie check failed' );
} );

test( 'a refusal that is not about the nonce is reported as it stands, not retried', async () => {
    routes.me      = () => jsonResponse( 200, me() );
    routes.profile = () => jsonResponse( 403, { code: 'gratora_forbidden', message: 'Not allowed.' } );

    await boot();
    await clickButton( 'Profile' );

    expect( noncesFor( 'profile' ) ).toEqual( [ STALE ] );
    expect( text() ).toContain( 'Your session expired' );
} );
