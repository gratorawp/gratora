/**
 * Two ways the portal could only be used with a mouse.
 *
 * The country field is required to save the account form, and its options were
 * buttons activated on mousedown: a donor on a keyboard could type a search and
 * never choose anything. The manage-subscription sheet bound its focus trap and
 * its Escape handler to the element it saw on the first render, and the sheet
 * swaps that element on every stage change, so both were gone the moment the
 * donor pressed a button inside it.
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
        country: '', total_donated_cents: 4000, unconverted_count: 0,
        donations_count: 1, first_donation_at: '2026-08-19 14:54:06',
        last_donation_at: '2026-08-19 14:54:06', primary_currency: 'USD',
        csrf: 'csrf-token', consents_pending: 0,
        ...overrides,
    };
}

function plan( overrides = {} ) {
    return {
        id: 38, status: 'active', amount_cents: 2500, currency: 'USD',
        interval_unit: 'month', interval_count: 1,
        next_payment_at: '2026-10-01 09:00:00', gateway: 'stripe',
        can_pause: true, can_update_payment_method: true, can_change_interval: true,
        frequency: 'monthly',
        frequency_options: [ 'weekly', 'biweekly', 'monthly', 'quarterly', 'yearly' ],
        ...overrides,
    };
}

/** Preact defers effects, so a render that has landed in the DOM may not have bound its listeners yet. */
async function settle() {
    for ( let i = 0; i < 5; i++ ) {
        await new Promise( ( r ) => requestAnimationFrame( () => r() ) );
        await new Promise( ( r ) => setTimeout( r, 0 ) );
    }
}

async function until( predicate, what ) {
    for ( let i = 0; i < 200; i++ ) {
        if ( predicate() ) return;
        await new Promise( ( r ) => setTimeout( r, 5 ) );
    }
    throw new Error( `timed out waiting for ${ what }` );
}

function button( label ) {
    return [ ...document.querySelectorAll( 'button' ) ].find( ( b ) => b.textContent.trim() === label );
}

function key( target, k, init = {} ) {
    const e = new window.KeyboardEvent( 'keydown', { key: k, bubbles: true, cancelable: true, ...init } );
    target.dispatchEvent( e );

    return e;
}

async function mount() {
    routes.me = () => jsonResponse( 200, me() );
    document.body.innerHTML = '<div id="gratora-donor-portal"></div>';
    jest.isolateModules( () => {
        require( '../../assets/donor-portal/index.jsx' );
    } );
    await until( () => button( 'Recurring' ), 'the portal to load' );
}

beforeEach( () => {
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

async function openCountryPicker() {
    await mount();
    button( 'Profile' ).click();
    await until( () => document.querySelector( '.dp-country input' ), 'the account form to render' );

    const input = document.querySelector( '.dp-country input' );
    input.focus();
    input.dispatchEvent( new window.Event( 'focus', { bubbles: true } ) );
    await until( () => document.querySelector( '.dp-country__list' ), 'the list to open' );

    return input;
}

test( 'a donor can choose a country without a mouse', async () => {
    const input = await openCountryPicker();

    input.value = 'Germ';
    input.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );
    await until( () => document.querySelector( '.dp-country__list li' ), 'the search to match' );

    key( input, 'Enter' );

    await until( () => input.value === 'Germany', 'the country to be chosen' );
    expect( input.value ).toBe( 'Germany' );
} );

test( 'the arrow keys move down the list', async () => {
    const input = await openCountryPicker();

    input.value = 'ital';
    input.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );
    await until( () => document.querySelector( '.dp-country__list li' ), 'the search to match' );

    key( input, 'ArrowDown' );
    key( input, 'ArrowUp' );
    key( input, 'Enter' );

    await until( () => input.value !== 'ital', 'a country to be chosen' );
    expect( input.value ).toBe( 'Italy' );
} );

test( 'the field tells a screen reader it is a combobox', async () => {
    const input = await openCountryPicker();

    expect( input.getAttribute( 'role' ) ).toBe( 'combobox' );
    expect( input.getAttribute( 'aria-expanded' ) ).toBe( 'true' );
    expect( document.querySelector( '.dp-country__list' ).getAttribute( 'role' ) ).toBe( 'listbox' );
} );

async function openSheet() {
    routes.me = () => jsonResponse( 200, me() );
    routes.recurring = () => jsonResponse( 200, [ plan() ] );

    document.body.innerHTML = '<div id="gratora-donor-portal"></div>';
    jest.isolateModules( () => {
        require( '../../assets/donor-portal/index.jsx' );
    } );

    await until( () => button( 'Recurring' ), 'the portal to load' );
    button( 'Recurring' ).click();
    await until( () => document.querySelector( '.dp-list__row' ), 'the list to render' );
    document.querySelector( '.dp-list__row' ).click();
    await until( () => document.querySelector( '.dp-modal' ), 'the sheet to open' );
    await settle();
}

test( 'Escape still closes the sheet after the donor has moved a stage inside it', async () => {
    await openSheet();

    button( 'Change frequency' ).click();
    await until( () => document.querySelector( '.dp-modal select' ), 'the frequency stage to render' );
    await settle();

    key( document.body, 'Escape' );

    await until( () => ! document.querySelector( '.dp-modal' ), 'the sheet to close' );
    expect( document.querySelector( '.dp-modal' ) ).toBeNull();
} );

test( 'Escape closes the sheet on the stage it opened on too', async () => {
    await openSheet();

    key( document.body, 'Escape' );

    await until( () => ! document.querySelector( '.dp-modal' ), 'the sheet to close' );
    expect( document.querySelector( '.dp-modal' ) ).toBeNull();
} );
