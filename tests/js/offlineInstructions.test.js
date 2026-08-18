/**
 * A donation the org banks by hand ends on an instructions screen. The donor
 * still has a transfer to make, and the row that screen names is the queue
 * entry the money is matched against when it lands.
 *
 * The stash that lets a redirect gateway pick a donation back up outlives a
 * reload, and this screen is the end of the road for a donation no browser is
 * coming back to. Left in place, a donor who reloads and submits again sends
 * that reference and its status token, asking the server to treat an awaited
 * transfer as an attempt somebody abandoned.
 */

import { PENDING_KEY } from '../../assets/donation-form/util/pending';

const PENDING_TITLE   = 'Almost done';
const PENDING_MESSAGE = 'Please send your transfer quoting the reference below.';

function config( overrides = {} ) {
    return {
        slug:     'transfer',
        form_id:  4,
        currency: 'USD',
        gateway:  'offline',
        layout:   'inline',
        rest:     'https://example.test/wp-json/dono/v1/donations',
        gateways: {
            options: [ {
                id:          'offline',
                label:       'Bank transfer',
                currencies:  [ '*' ],
                countries:   [ '*' ],
                frequencies: [ 'one_time' ],
            } ],
        },
        steps: [
            // Preselected by initialState, so the donor has a valid amount
            // without touching anything.
            { id: 'amount', type: 'amount', presets: [ 2500 ] },
            { id: 'submit', type: 'submit' },
        ],
        i18n: {
            error:          'Sorry, something went wrong. Please try again.',
            donateNow:      'Donate now',
            donateAgain:    'Donate again',
            processing:     'Processing',
            pendingTitle:   PENDING_TITLE,
            pendingMessage: PENDING_MESSAGE,
        },
        ...overrides,
    };
}

function addForm( id, cfg ) {
    const form = document.createElement( 'form' );
    form.className = 'dono-donation-form';
    form.id = id;

    const json = document.createElement( 'script' );
    json.type = 'application/json';
    json.setAttribute( 'data-dono-form-config', '' );
    json.textContent = JSON.stringify( cfg );
    form.appendChild( json );

    document.body.appendChild( form );

    return form;
}

// The runtime boots itself on import, so each page load needs its own copy.
async function boot() {
    jest.isolateModules( () => {
        require( '../../assets/donation-form/runtime.jsx' );
    } );

    await settle();
}

async function settle() {
    await new Promise( ( r ) => setTimeout( r, 20 ) );
    await new Promise( ( r ) => setTimeout( r, 20 ) );
}

async function donate( form ) {
    form.querySelector( '.dono-form__button--primary' ).click();
    await settle();
}

// What the server answers for a donation it has recorded but not been paid.
function awaitingTransfer( reference ) {
    return {
        reference,
        status_token: 'tok-' + reference,
        status:       'pending',
        gateway:      'offline',
        amount_cents: 2500,
        currency:     'USD',
    };
}

let sent = [];

beforeEach( () => {
    document.body.innerHTML = '';
    window.sessionStorage.clear();
    sent = [];

    let n = 0;
    global.fetch = jest.fn( ( url, init ) => {
        sent.push( JSON.parse( init.body ) );
        n += 1;

        return Promise.resolve( {
            ok:   true,
            json: () => Promise.resolve( awaitingTransfer( 'DONO-2026-0000' + n ) ),
        } );
    } );
} );

describe( 'the instructions screen for a donation banked by hand', () => {
    test( 'it drops the stash, so nothing is left naming the awaited transfer', async () => {
        const form = addForm( 'dono-form-1', config() );
        await boot();
        await donate( form );

        expect( form.textContent ).toContain( PENDING_MESSAGE );
        expect( form.textContent ).toContain( 'DONO-2026-00001' );
        expect( window.sessionStorage.getItem( PENDING_KEY ) ).toBeNull();
    } );

    test( 'a donor who reloads and submits again posts no claim on the first row', async () => {
        const first = addForm( 'dono-form-1', config() );
        await boot();
        await donate( first );

        // The reload: same tab and same session storage, a fresh page and a
        // fresh runtime.
        document.body.innerHTML = '';
        const second = addForm( 'dono-form-1', config() );
        await boot();
        await donate( second );

        expect( sent ).toHaveLength( 2 );
        expect( sent[ 1 ]._retry ).toBeUndefined();
    } );

    test( 'the donation the donor is looking at survives the stash going', async () => {
        const form = addForm( 'dono-form-1', config() );
        await boot();
        await donate( form );

        // The amount is what tells the donor how much to transfer, and on a
        // gateway that navigated away and back the stash is its only source.
        expect( form.querySelector( '.dono-form__summary--receipt' ) ).not.toBeNull();
        expect( form.textContent ).toContain( '25' );
    } );
} );
