/**
 * Country is required on the donor's profile, and the portal picker read and
 * searched English names only, on a screen the site had otherwise translated.
 * A German donor typing "Deutschland" matched nothing.
 *
 * The donation form's picker was localised and this one was left behind, so
 * both now read the same helper.
 */

const ORIGINAL = Intl.DisplayNames;

// A stand-in rather than a locale switch: jsdom's ICU data varies by build, and
// what is under test is that the picker asks at all.
class GermanNames {
    of( code ) {
        return { DE: 'Deutschland', FR: 'Frankreich', GB: 'Vereinigtes Konigreich' }[ code ] || code;
    }
}

let routes = {};

function jsonResponse( status, body ) {
    return Promise.resolve( {
        ok: status >= 200 && status < 300,
        status,
        headers: { get: () => 'application/json' },
        json: () => Promise.resolve( body ),
    } );
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

async function openProfile( country = '' ) {
    routes.profile = () => jsonResponse( 200, {
        email: 'alice@example.test', first_name: 'Alice', last_name: 'Okafor',
        phone: '', company: '', avatar_url: '', country,
    } );
    routes.me = () => jsonResponse( 200, {
        id: 4, name: 'Alice Okafor', first_name: 'Alice', last_name: 'Okafor',
        country, total_donated_cents: 0, unconverted_count: 0, donations_count: 0,
        primary_currency: 'USD', csrf: 'csrf-token', consents_pending: 0,
    } );

    document.body.innerHTML = '<div id="gratora-donor-portal"></div>';
    jest.isolateModules( () => {
        require( '../../assets/donor-portal/index.jsx' );
    } );
    await until( () => button( 'Profile' ), 'the portal to load' );

    button( 'Profile' ).click();
    await until( () => document.querySelector( '.dp-country input' ), 'the country picker' );
    await settle();

    return document.querySelector( '.dp-country input' );
}

const options = () => [ ...document.querySelectorAll( '.dp-country__list [role="option"]' ) ]
    .map( ( o ) => o.textContent );

async function search( input, term ) {
    input.focus();
    input.value = term;
    input.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );
    await settle();
}

beforeEach( () => {
    jest.resetModules();
    Intl.DisplayNames = GermanNames;
    routes = {};
    window.history.replaceState( {}, '', '/portal/' );
    window.gratoraPortal = { rest: '/wp-json/gratora/v1/portal/', nonce: '', token: 'portal-token' };
    window.gratora = {
        default_currency: 'USD',
        number_format: { decimalPlaces: 2, decimalSep: '.', thousandSep: ',', symbolPosition: 'before', symbol: '$' },
    };
    global.fetch = jest.fn( ( url ) => {
        const path = String( url ).replace( '/wp-json/gratora/v1/portal/', '' );
        const route = routes[ path ];

        return typeof route === 'function' ? route() : jsonResponse( 200, {} );
    } );
} );

afterEach( () => {
    Intl.DisplayNames = ORIGINAL;
} );

it( 'matches a search in the reader own language', async () => {
    const input = await openProfile();
    await search( input, 'Deutsch' );

    expect( options().join( ' ' ) ).toContain( 'Deutschland' );
} );

it( 'still matches the English name, so an existing habit keeps working', async () => {
    const input = await openProfile();
    await search( input, 'Germany' );

    expect( options().join( ' ' ) ).toContain( 'Deutschland' );
} );

it( 'still matches the code', async () => {
    const input = await openProfile();
    await search( input, 'de' );

    expect( options().join( ' ' ) ).toContain( 'Deutschland' );
} );

it( 'reads the donor stored country back in their own language', async () => {
    const input = await openProfile( 'DE' );

    expect( input.value ).toBe( 'Deutschland' );
} );

it( 'shows the chosen country in that language after picking it', async () => {
    const input = await openProfile();
    await search( input, 'Frank' );

    document.querySelector( '.dp-country__list [role="option"] button' )
        .dispatchEvent( new window.MouseEvent( 'mousedown', { bubbles: true } ) );
    await settle();

    expect( input.value ).toBe( 'Frankreich' );
} );
