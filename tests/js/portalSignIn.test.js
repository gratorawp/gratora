/**
 * The two moments in the portal client that a PHP test cannot reach: what a
 * donor sees when the link they just spent does not leave them signed in, and
 * what it takes to destroy every sign-in link on the account.
 *
 * rest_do_request has no cookie jar, so an integration test cannot tell a
 * session that held from one that did not. This mounts the real client.
 */

const NO_SESSION = 'this browser did not keep you signed in';

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

// The runtime mounts itself on import, so each test needs its own module copy.
async function boot() {
    document.body.innerHTML = '<div id="dono-donor-portal"></div>';

    jest.isolateModules( () => {
        require( '../../assets/donor-portal/index.jsx' );
    } );

    // preact defers effects behind a frame; exchange and me resolve after that.
    for ( let i = 0; i < 4; i++ ) {
        await new Promise( ( r ) => setTimeout( r, 20 ) );
    }
}

function text() {
    return document.getElementById( 'dono-donor-portal' ).textContent;
}

// Awaited, because the form's submit handler closes over the state of the
// render that attached it: typing has to reach a re-render before submitting.
function type( selector, value ) {
    const field = document.querySelector( selector );
    expect( field ).toBeTruthy();
    field.value = value;
    field.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );

    return new Promise( ( r ) => setTimeout( r, 20 ) );
}

// jsdom does not run the form-submission algorithm behind a submit button.
function submitForm() {
    const form = document.querySelector( '.dp-signin form' );
    expect( form ).toBeTruthy();
    form.dispatchEvent( new window.Event( 'submit', { bubbles: true, cancelable: true } ) );

    return new Promise( ( r ) => setTimeout( r, 20 ) );
}

function clickButton( label ) {
    const button = [ ...document.querySelectorAll( 'button' ) ]
        .find( ( b ) => b.textContent.trim() === label );
    expect( button ).toBeTruthy();
    button.click();

    return new Promise( ( r ) => setTimeout( r, 20 ) );
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
        const path = String( url ).replace( '/wp-json/dono/v1/portal/', '' );
        const route = routes[ path ];
        if ( typeof route === 'function' ) return route();

        // Everything a tab fetches once the portal renders.
        return jsonResponse( 200, {} );
    } );
} );

describe( 'a sign-in link the browser does not keep', () => {
    test( 'the donor is told why, rather than dropped on a blank form', async () => {
        window.history.replaceState( {}, '', '/portal/?token=magic' );
        routes.exchange = () => jsonResponse( 200, { ok: true, donor_id: 4, csrf: 'csrf-token' } );
        // What a cross-origin exchange leaves behind: the cookie it set was
        // never stored, so the very next call has no session.
        routes.me = () => jsonResponse( 401, { message: 'Session expired.' } );

        await boot();

        expect( text() ).toContain( NO_SESSION );
    } );

    test( 'and a link that does keep the session says nothing of the sort', async () => {
        window.history.replaceState( {}, '', '/portal/?token=magic' );
        routes.exchange = () => jsonResponse( 200, { ok: true, donor_id: 4, csrf: 'csrf-token' } );
        routes.me = () => jsonResponse( 200, me() );

        await boot();

        expect( text() ).toContain( 'Hi, Alice.' );
        expect( text() ).not.toContain( NO_SESSION );
    } );
} );

describe( 'signing up', () => {
    /**
     * A name typed against an address that already has a claim on it is
     * dropped, and the 200 cannot say so without answering whether that address
     * has a signup waiting. So the rule is stated to everyone who signs up.
     */
    test( 'the donor is told the name may not be theirs to set here', async () => {
        routes.me = () => jsonResponse( 401, { message: 'Session expired.' } );
        routes.register = () => jsonResponse( 200, { ok: true } );

        await boot();
        await clickButton( 'Create an account' );

        await type( 'input[autocomplete="given-name"]', 'Alice' );
        await type( 'input[type="email"]', 'alice@example.test' );

        await submitForm();

        expect( text() ).toContain( 'Your name is taken from your first signup' );
    } );

    test( 'and asking for a sign-in link is not told anything of the sort', async () => {
        routes.me = () => jsonResponse( 401, { message: 'Session expired.' } );
        routes[ 'send-link' ] = () => jsonResponse( 200, { ok: true } );

        await boot();

        await type( 'input[type="email"]', 'alice@example.test' );

        await submitForm();

        expect( text() ).toContain( 'Check your email' );
        expect( text() ).not.toContain( 'Your name is taken from your first signup' );
    } );
} );

describe( 'signing out everywhere', () => {
    test( 'one click does not destroy every link on the account', async () => {
        routes.me = () => jsonResponse( 200, me() );

        await boot();
        await clickButton( 'Sign out everywhere' );

        expect( global.fetch.mock.calls.map( ( c ) => String( c[ 0 ] ) ) )
            .not.toContain( '/wp-json/dono/v1/portal/logout-everywhere' );
        expect( text() ).toContain( 'cancels any sign-in link that was never opened' );
    } );

    test( 'confirming it does', async () => {
        routes.me = () => jsonResponse( 200, me() );
        // Never resolves: the client reloads the page in finally(), which jsdom
        // cannot do, and the call itself is what this asserts.
        routes[ 'logout-everywhere' ] = () => new Promise( () => {} );

        await boot();
        await clickButton( 'Sign out everywhere' );
        await clickButton( 'Yes, sign out everywhere' );

        const posted = global.fetch.mock.calls
            .find( ( c ) => String( c[ 0 ] ).endsWith( '/logout-everywhere' ) );
        expect( posted ).toBeTruthy();
        expect( posted[ 1 ].method ).toBe( 'POST' );
        expect( posted[ 1 ].headers[ 'X-Dono-Csrf' ] ).toBe( 'csrf-token' );
    } );

    test( 'and backing out of it leaves the ordinary way out', async () => {
        routes.me = () => jsonResponse( 200, me() );

        await boot();
        await clickButton( 'Sign out everywhere' );
        await clickButton( 'Keep me signed in' );

        expect( text() ).toContain( 'Sign out everywhere' );
        expect( global.fetch.mock.calls.map( ( c ) => String( c[ 0 ] ) ) )
            .not.toContain( '/wp-json/dono/v1/portal/logout-everywhere' );
    } );
} );
