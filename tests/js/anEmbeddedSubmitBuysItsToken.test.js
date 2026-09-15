/**
 * A host document is served without a form token: it carries a public key
 * instead, and trades that key for a token at submit time. What the donation
 * route is then sent, and what it is not sent, is the whole of the difference
 * between a donation made from a frame and one made from the charity's page.
 *
 * The form on the charity's own page has to be untouched by all of it, so every
 * claim here is made twice: once with a host document, once without.
 */

const { settle } = require( './support/waitFor' );

const ERROR    = 'Sorry, something went wrong. Please try again.';
const REST     = 'https://example.test/wp-json/gratora/v1/donations';
const TOKEN_URL = 'https://example.test/wp-json/gratora-embed/v1/token';

const embed = () => ( { origin: 'https://partner.test', key: 'ek_probe', tokenUrl: TOKEN_URL } );

function config( overrides = {} ) {
    return {
        slug:     'probe',
        form_id:  4,
        currency: 'USD',
        gateway:  'offline',
        layout:   'inline',
        rest:     REST,
        spam:     { formToken: 'ft_from_the_page' },
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
            error:        ERROR,
            donateNow:    'Donate now',
            processing:   'Processing',
            pendingTitle: 'Almost done',
            thanks:       'Thank you for your donation!',
        },
        ...overrides,
    };
}

function addForm( id, cfg ) {
    const form = document.createElement( 'form' );
    form.className = 'gratora-donation-form';
    form.id = id;

    const json = document.createElement( 'script' );
    json.type = 'application/json';
    json.setAttribute( 'data-gratora-form-config', '' );
    json.textContent = JSON.stringify( cfg );
    form.appendChild( json );

    document.body.appendChild( form );

    return form;
}

async function boot() {
    jest.isolateModules( () => {
        require( '../../assets/donation-form/runtime.jsx' );
    } );

    await settle();
}

async function donate( form ) {
    form.querySelector( '.gratora-form__button--primary' ).click();
    await settle();
}

const paid = {
    reference:    'GRATORA-2026-00042',
    status_token: 'tok',
    status:       'paid',
    gateway:      'offline',
    amount_cents: 2500,
    currency:     'USD',
};

let calls = [];
let mintOk = true;

const stash = () => JSON.parse( window.sessionStorage.getItem( 'gratora:pending-donation' ) || '{}' );
const donationCall = () => calls.find( ( c ) => c.url === REST );

beforeEach( () => {
    document.body.innerHTML = '';
    window.sessionStorage.clear();
    window.history.replaceState( {}, '', '/campaign/' );
    calls  = [];
    mintOk = true;

    global.fetch = jest.fn( ( url, init = {} ) => {
        const body = init.body ? JSON.parse( init.body ) : null;
        calls.push( { url, method: init.method || 'GET', body } );

        if ( url === TOKEN_URL ) {
            return Promise.resolve( {
                ok:   mintOk,
                json: () => Promise.resolve( mintOk ? { token: 'ft_minted' } : { message: 'no' } ),
            } );
        }

        return Promise.resolve( { ok: true, json: () => Promise.resolve( paid ) } );
    } );
} );

describe( 'a donation made from a host document', () => {
    test( 'the key is traded for a token, and the donation carries both', async () => {
        const form = addForm( 'gratora-form-1', config( { embed: embed() } ) );
        await boot();
        await donate( form );

        expect( calls[ 0 ].url ).toBe( TOKEN_URL );
        expect( calls[ 0 ].method ).toBe( 'POST' );
        expect( calls[ 0 ].body ).toEqual( { key: 'ek_probe' } );

        expect( donationCall().body ).toMatchObject( {
            _ft:    'ft_minted',
            _ek:    'ek_probe',
            _proof: 'embed',
        } );
    } );

    test( 'a refused exchange stops the donation and says so in the form\'s own words', async () => {
        mintOk = false;
        const form = addForm( 'gratora-form-1', config( { embed: embed() } ) );
        await boot();
        await donate( form );

        expect( donationCall() ).toBeUndefined();
        expect( form.textContent ).toContain( ERROR );
    } );

    test( 'nothing is sent about where the donor came from', async () => {
        window.history.replaceState( {}, '', '/embed/?utm_source=partner&utm_medium=frame' );

        const form = addForm( 'gratora-form-1', config( { embed: embed() } ) );
        await boot();
        await donate( form );

        expect( donationCall().body.source_attribution ).toBeUndefined();
    } );

    test( 'an amount on the address is ignored, because the address is not the donor\'s', async () => {
        window.history.replaceState( {}, '', '/embed/?gratora_amount=9900&gratora_currency=USD' );

        const form = addForm( 'gratora-form-1', config( { embed: embed() } ) );
        await boot();
        await donate( form );

        expect( donationCall().body.amount_cents ).toBe( 2500 );
    } );

    test( 'the stash left for the return carries no donor address', async () => {
        const form = addForm( 'gratora-form-1', config( { embed: embed() } ) );
        await boot();
        await donate( form );

        expect( stash().reference ).toBe( paid.reference );
        expect( Object.keys( stash() ) ).not.toContain( 'email' );
    } );
} );

describe( 'the same form on the charity\'s own page', () => {
    test( 'it buys nothing and sends the token the page was rendered with', async () => {
        const form = addForm( 'gratora-form-1', config() );
        await boot();
        await donate( form );

        expect( calls ).toHaveLength( 1 );
        expect( calls[ 0 ].url ).toBe( REST );
        expect( calls[ 0 ].body._ft ).toBe( 'ft_from_the_page' );
        expect( Object.keys( calls[ 0 ].body ) ).not.toContain( '_ek' );
        expect( Object.keys( calls[ 0 ].body ) ).not.toContain( '_proof' );
    } );

    test( 'it still reports where the donor came from', async () => {
        window.history.replaceState( {}, '', '/campaign/?utm_source=newsletter' );

        const form = addForm( 'gratora-form-1', config() );
        await boot();
        await donate( form );

        expect( donationCall().body.source_attribution ).toMatchObject( { utm_source: 'newsletter' } );
    } );

    test( 'a link that names an amount still opens on it', async () => {
        window.history.replaceState( {}, '', '/campaign/?gratora_amount=9900&gratora_currency=USD' );

        const form = addForm( 'gratora-form-1', config() );
        await boot();
        await donate( form );

        expect( donationCall().body.amount_cents ).toBe( 9900 );
    } );

    test( 'it still stashes the address the return screen offers the portal to', async () => {
        const form = addForm( 'gratora-form-1', config() );
        await boot();
        await donate( form );

        expect( Object.keys( stash() ) ).toContain( 'email' );
    } );
} );
