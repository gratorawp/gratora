/**
 * Receipts & tax hands the donor a PDF. It cannot do that through window.open:
 * the fresh download link is minted at click time, so the open happens a round
 * trip after the tap that asked for it, and Safari (popup blocking on by
 * default on iOS) refuses a window nobody gestured for. Nothing throws, so the
 * donor taps a button that does nothing and says nothing.
 *
 * The annual statement in the same component already fetches the bytes and
 * clicks a synthesized anchor, which is not popup-gated.
 */

const PORTAL_REST = 'https://example.test/wp-json/dono/v1/portal/';
const RECEIPT_URL = 'https://example.test/wp-json/dono/v1/receipts/9/download?token=fresh';

let routes = {};
let clickedAnchors = [];

function jsonResponse( status, body ) {
    return Promise.resolve( {
        ok:      status >= 200 && status < 300,
        status,
        headers: { get: () => 'application/json' },
        json:    () => Promise.resolve( body ),
    } );
}

function pdfResponse() {
    return Promise.resolve( {
        ok:      true,
        status:  200,
        headers: { get: () => 'application/pdf' },
        blob:    () => Promise.resolve( new window.Blob( [ '%PDF-1.4' ], { type: 'application/pdf' } ) ),
    } );
}

function me() {
    return {
        id:                  4,
        name:                'Alice Okafor',
        first_name:          'Alice',
        total_donated_cents: 0,
        donations_count:     0,
        first_donation_at:   null,
        primary_currency:    'USD',
        csrf:                'csrf-token',
        consents_pending:    0,
    };
}

async function boot() {
    document.body.innerHTML = '<div id="dono-donor-portal"></div>';

    jest.isolateModules( () => {
        require( '../../assets/donor-portal/index.jsx' );
    } );

    await settle();
}

function text() {
    return document.getElementById( 'dono-donor-portal' ).textContent;
}

const tick = () => new Promise( ( r ) => setTimeout( r, 10 ) );

const find = ( label ) => [ ...document.querySelectorAll( 'button' ) ]
    .find( ( b ) => b.textContent.trim() === label );

// Each tab settles over several renders (its own request, then the year clamp),
// so wait for the control rather than for a number of milliseconds.
async function clickButton( label ) {
    for ( let i = 0; i < 50 && ! find( label ); i++ ) {
        await tick();
    }
    const button = find( label );
    expect( button ).toBeTruthy();
    button.click();
    await tick();
}

async function settle() {
    for ( let i = 0; i < 5; i++ ) {
        await tick();
    }
}

beforeEach( () => {
    routes = {};
    clickedAnchors = [];
    window.history.replaceState( {}, '', '/portal/' );
    window.donoPortal = { rest: PORTAL_REST, nonce: '', token: 'portal-token' };
    window.dono = {
        default_currency: 'USD',
        number_format: { decimalPlaces: 2, decimalSep: '.', thousandSep: ',', symbolPosition: 'before', symbol: '$' },
    };

    window.open = jest.fn();
    // jsdom implements neither, and the anchor path is the thing under test.
    window.URL.createObjectURL = jest.fn( () => 'blob:receipt' );
    window.URL.revokeObjectURL = jest.fn();
    jest.spyOn( window.HTMLAnchorElement.prototype, 'click' ).mockImplementation( function () {
        clickedAnchors.push( this );
    } );

    routes.me       = () => jsonResponse( 200, me() );
    routes.receipts = () => jsonResponse( 200, [
        { id: 9, receipt_number: 'RCPT-0009', renderer_id: 'generic', issued_at: '2026-02-03 10:00:00', donation_id: 5 },
    ] );

    global.fetch = jest.fn( ( url ) => {
        const raw = String( url );
        if ( raw.startsWith( RECEIPT_URL ) ) return pdfResponse();

        const path  = raw.replace( PORTAL_REST, '' );
        const route = routes[ path ];
        if ( typeof route === 'function' ) return route();

        return jsonResponse( 200, {} );
    } );
} );

afterEach( () => {
    jest.restoreAllMocks();
} );

async function openReceipts() {
    await boot();
    await clickButton( 'Receipts & tax' );
}

test( 'the receipt reaches the donor without asking for a popup', async () => {
    routes[ 'receipts/9/download-url' ] = () => jsonResponse( 200, { url: RECEIPT_URL } );

    await openReceipts();
    await clickButton( 'Download' );
    await settle();

    expect( window.open ).not.toHaveBeenCalled();
    expect( global.fetch.mock.calls.map( ( c ) => String( c[ 0 ] ) ) ).toContain( RECEIPT_URL );
    expect( clickedAnchors ).toHaveLength( 1 );
    expect( clickedAnchors[ 0 ].getAttribute( 'download' ) ).toBe( 'receipt-RCPT-0009.pdf' );
    expect( clickedAnchors[ 0 ].getAttribute( 'href' ) ).toBe( 'blob:receipt' );
} );

test( 'a receipt the server will not hand over says so', async () => {
    routes[ 'receipts/9/download-url' ] = () => jsonResponse( 404, { message: 'Receipt not found.' } );

    await openReceipts();
    await clickButton( 'Download' );
    await settle();

    expect( text() ).toContain( 'Receipt not found.' );
    expect( clickedAnchors ).toHaveLength( 0 );
} );

test( 'a link that arrives without a url is not a silent no-op', async () => {
    routes[ 'receipts/9/download-url' ] = () => jsonResponse( 200, {} );

    await openReceipts();
    await clickButton( 'Download' );
    await settle();

    expect( text() ).toContain( 'Could not open the receipt' );
} );

test( 'and a fetch of the document that fails is reported, not swallowed', async () => {
    routes[ 'receipts/9/download-url' ] = () => jsonResponse( 200, { url: RECEIPT_URL } );
    global.fetch.mockImplementation( ( url ) => {
        const raw = String( url );
        if ( raw.startsWith( RECEIPT_URL ) ) {
            return Promise.resolve( { ok: false, status: 403, headers: { get: () => 'application/json' }, json: () => Promise.resolve( {} ) } );
        }
        const route = routes[ raw.replace( PORTAL_REST, '' ) ];
        return typeof route === 'function' ? route() : jsonResponse( 200, {} );
    } );

    await openReceipts();
    await clickButton( 'Download' );
    await settle();

    expect( text() ).toContain( 'Could not open the receipt' );
    expect( clickedAnchors ).toHaveLength( 0 );
} );

test( 'the annual statement saves through the same anchor', async () => {
    routes[ `annual-statement/${ new Date().getFullYear() }` ] = () => pdfResponse();

    await openReceipts();
    await clickButton( 'Download statement' );
    await settle();

    expect( clickedAnchors ).toHaveLength( 1 );
    expect( clickedAnchors[ 0 ].getAttribute( 'download' ) )
        .toBe( `dono-annual-${ new Date().getFullYear() }.pdf` );
} );

/**
 * rest_url() answers with home_url's host. On an install that serves the portal
 * on both the apex and www, the link it mints names whichever one the donor is
 * not on, and a cross-origin fetch of it is refused.
 */
test( 'the document is fetched on the origin the portal itself talks to', async () => {
    routes[ 'receipts/9/download-url' ] = () => jsonResponse( 200, {
        url: 'https://www.example.test/wp-json/dono/v1/receipts/9/download?token=fresh',
    } );

    await openReceipts();
    await clickButton( 'Download' );
    await settle();

    expect( global.fetch.mock.calls.map( ( c ) => String( c[ 0 ] ) ) ).toContain( RECEIPT_URL );
    expect( clickedAnchors ).toHaveLength( 1 );
} );
