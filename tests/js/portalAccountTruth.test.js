/**
 * Four ways the portal showed the donor something other than what it held.
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

function me( overrides = {} ) {
    return {
        id: 4, name: 'Alice Okafor', first_name: 'Alice', last_name: 'Okafor',
        country: 'DE', total_donated_cents: 4000, unconverted_count: 0,
        donations_count: 140, first_donation_at: '2026-08-19 14:54:06',
        last_donation_at: '2026-08-19 14:54:06', primary_currency: 'USD',
        csrf: 'csrf-token', consents_pending: 1,
        ...overrides,
    };
}

function donation( i ) {
    return {
        id: i, reference: `FUNDKIT-${ i }`, amount_cents: 1000, fee_covered_cents: 0,
        refunded_cents: 0, currency: 'USD', frequency: 'one_time',
        paid_at: '2026-08-19 14:54:06', is_anonymous: false,
    };
}

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

async function mount( overrides = {} ) {
    routes.me = () => jsonResponse( 200, me( overrides ) );
    document.body.innerHTML = '<div id="fundkit-donor-portal"></div>';
    jest.isolateModules( () => {
        require( '../../assets/donor-portal/index.jsx' );
    } );
    await until( () => button( 'Donations' ), 'the portal to load' );
    await settle();
}

beforeEach( () => {
    routes = {};
    posted = [];
    window.history.replaceState( {}, '', '/portal/' );
    window.fundkitPortal = { rest: '/wp-json/fundkit/v1/portal/', nonce: '', token: 'portal-token' };
    window.fundkit = {
        default_currency: 'USD',
        number_format: { decimalPlaces: 2, decimalSep: '.', thousandSep: ',', symbolPosition: 'before', symbol: '$' },
    };
    global.fetch = jest.fn( ( url, init ) => {
        const path = String( url ).replace( '/wp-json/fundkit/v1/portal/', '' );
        if ( init && init.method === 'POST' ) posted.push( { path, body: JSON.parse( init.body ) } );
        const route = routes[ path ];

        return typeof route === 'function' ? route() : jsonResponse( 200, {} );
    } );
} );

test( 'a truncated donation list says how many there really are', async () => {
    routes.donations = () => jsonResponse( 200, {
        items: Array.from( { length: 100 }, ( _, i ) => donation( i + 1 ) ),
        total: 140,
    } );

    await mount();
    button( 'Donations' ).click();
    await until( () => document.querySelector( '.dp-list__row' ), 'the list to render' );

    expect( text() ).toContain( '140' );
    expect( text() ).toContain( '100' );
} );

test( 'a list that holds everything says nothing about totals', async () => {
    routes.donations = () => jsonResponse( 200, {
        items: [ donation( 1 ), donation( 2 ) ],
        total: 2,
    } );

    await mount( { donations_count: 2 } );
    button( 'Donations' ).click();
    await until( () => document.querySelector( '.dp-list__row' ), 'the list to render' );

    expect( document.querySelector( '.dp-list__note' ) ).toBeNull();
} );

test( 'resolving every stale consent clears the banner on the other tabs', async () => {
    const stale = [ { key: 'newsletter', label: 'Newsletter', granted: true, required: false, stale: true } ];
    const fresh = [ { key: 'newsletter', label: 'Newsletter', granted: true, required: false, stale: false } ];

    routes.consents = () => jsonResponse( 200, stale );
    await mount();

    button( 'Consents' ).click();
    await until( () => document.querySelector( '.dp-consent' ), 'the consents tab to render' );

    routes.consents = () => jsonResponse( 200, fresh );
    button( 'Keep as is' ).click();
    await until( () => posted.some( ( p ) => p.path === 'consents' ), 'the confirmation to be sent' );
    await settle();

    button( 'Overview' ).click();
    await settle();

    expect( text() ).not.toContain( 'need an update' );
} );

test( 'a second consent click while the first is in flight is ignored', async () => {
    const list = [
        { key: 'newsletter', label: 'Newsletter', granted: false, required: false, stale: false },
        { key: 'updates', label: 'Updates', granted: false, required: false, stale: false },
    ];

    let release;
    routes.consents = () => jsonResponse( 200, list );
    await mount();

    button( 'Consents' ).click();
    await until( () => document.querySelectorAll( '.dp-consent input' ).length === 2, 'the boxes to render' );

    routes.consents = () => new Promise( ( r ) => { release = () => r( { ok: true, status: 200, headers: { get: () => 'application/json' }, json: () => Promise.resolve( list ) } ); } );

    const boxes = [ ...document.querySelectorAll( '.dp-consent input' ) ];
    boxes[ 0 ].click();
    await settle();
    boxes[ 1 ].click();
    await settle();

    expect( posted.filter( ( p ) => p.path === 'consents' ) ).toHaveLength( 1 );

    if ( release ) release();
} );

test( 'the download statement button says it is working and refuses a second press', async () => {
    let release;
    routes[ 'annual-statement/2026' ] = () => new Promise( ( r ) => { release = () => r( { ok: true, status: 200, headers: { get: () => 'application/pdf' }, blob: () => Promise.resolve( new Blob() ) } ); } );
    routes.receipts = () => jsonResponse( 200, [] );

    await mount();
    button( 'Receipts & tax' ).click();
    await until( () => button( 'Download statement' ), 'the tab to render' );

    button( 'Download statement' ).click();
    await settle();

    expect( button( 'Download statement' ) ).toBeUndefined();
    expect( button( 'Preparing…' ).disabled ).toBe( true );

    if ( release ) release();
} );

test( 'text the donor typed but never picked does not survive leaving the field', async () => {
    routes.profile = () => jsonResponse( 200, { first_name: 'Alice', last_name: 'Okafor', country: 'DE' } );
    await mount( { country: 'DE' } );

    button( 'Profile' ).click();
    await until( () => document.querySelector( '.dp-country input' ), 'the account form to render' );

    const input = document.querySelector( '.dp-country input' );
    expect( input.value ).toBe( 'Germany' );

    input.focus();
    input.dispatchEvent( new window.Event( 'focus', { bubbles: true } ) );
    await settle();

    const field = document.querySelector( '.dp-country input' );
    field.value = 'Atlanti';
    field.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );
    await settle();

    // Re-queried, because the field it typed into may not be the node preact
    // is rendering by the time the reset lands.
    expect( document.querySelector( '.dp-country input' ).value ).toBe( 'Atlanti' );

    field.dispatchEvent( new window.Event( 'blur', { bubbles: true } ) );
    await new Promise( ( r ) => setTimeout( r, 200 ) );
    await settle();

    expect( document.querySelector( '.dp-country input' ).value ).toBe( 'Germany' );
} );

test( 'and a donor with no country saved is left with an empty field', async () => {
    routes.profile = () => jsonResponse( 200, { first_name: 'Alice', last_name: 'Okafor', country: '' } );
    await mount( { country: '' } );

    button( 'Profile' ).click();
    await until( () => document.querySelector( '.dp-country input' ), 'the account form to render' );

    const input = document.querySelector( '.dp-country input' );
    input.focus();
    input.value = 'Atlanti';
    input.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );
    await settle();

    input.dispatchEvent( new window.Event( 'blur', { bubbles: true } ) );
    await new Promise( ( r ) => setTimeout( r, 200 ) );
    await settle();

    expect( document.querySelector( '.dp-country input' ).value ).toBe( '' );
} );
