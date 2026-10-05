/**
 * A submission's `extra` has two writers. A field an add-on registers adds to
 * it what the donor did with that field: a Gift Aid declaration, a tribute. A
 * form can also carry an `extra` of its own in its config, which is how a
 * fundraiser page says whose page the donation was made on.
 *
 * A form that had its own sent only its own, so on a fundraiser page the
 * donor's declaration and dedication never left the browser.
 *
 * Driven through the real runtime, because the defect is in how the request
 * body is put together at submit.
 */

const { settle, waitFor } = require( './support/waitFor' );

const REST = 'https://example.test/wp-json/gratora/v1/donations';

function config( overrides = {} ) {
    return {
        slug:     'fundraiser',
        form_id:  7,
        currency: 'USD',
        gateway:  'offline',
        layout:   'inline',
        rest:     REST,
        gateways: { options: [ { id: 'offline', label: 'Offline' } ] },
        steps: [
            { id: 'amount', type: 'amount', presets: [ 2500 ] },
            { id: 'donor', type: 'donor', items: [ { t: 'field', kind: 'email', required: true } ] },
            { id: 'submit', type: 'submit' },
        ],
        i18n: { donateNow: 'Donate now', processing: 'Processing', validation: { required: 'Required.', invalidEmail: 'Enter a valid email.' } },
        ...overrides,
    };
}

// The registry FormFieldAssets prints inline before any field bundle loads.
function registry() {
    const items = {};

    return {
        register: ( kind, entry ) => { items[ kind ] = entry; },
        get:      ( kind ) => items[ kind ] || null,
        all:      () => Object.keys( items ).map( ( kind ) => [ kind, items[ kind ] ] ),
    };
}

const posts = () => global.fetch.mock.calls
    .filter( ( [ url, options = {} ] ) => String( url ) === REST && options.method === 'POST' )
    .map( ( [ , options ] ) => JSON.parse( options.body ) );

async function donate( cfg, contribution ) {
    if ( contribution ) {
        window.gratora.formFields.register( 'declaration', { payload: () => contribution } );
    }

    const form = document.createElement( 'form' );
    form.className = 'gratora-donation-form';
    form.id = 'gratora-form-7';

    const json = document.createElement( 'script' );
    json.type = 'application/json';
    json.setAttribute( 'data-gratora-form-config', '' );
    json.textContent = JSON.stringify( cfg );
    form.appendChild( json );
    document.body.appendChild( form );

    jest.isolateModules( () => {
        require( '../../assets/donation-form/runtime.jsx' );
    } );
    await settle();

    const email = form.querySelector( 'input[type="email"]' );
    email.value = 'ada@example.test';
    email.dispatchEvent( new Event( 'input', { bubbles: true } ) );
    await settle();

    form.querySelector( '.gratora-form__button--primary' ).click();
    await waitFor( () => posts().length > 0, { what: 'the donation to be posted' } );

    return posts()[ 0 ];
}

beforeEach( () => {
    document.body.innerHTML = '';
    window.sessionStorage.clear();
    window.Element.prototype.scrollIntoView = () => {};
    window.gratora = {
        formFields:       registry(),
        default_currency: 'USD',
        number_format:    { decimalPlaces: 2, decimalSep: '.', thousandSep: ',', symbolPosition: 'before', symbol: '$' },
    };
    global.fetch = jest.fn( () => Promise.resolve( {
        ok:      true,
        status:  201,
        headers: { get: () => 'application/json' },
        json:    () => Promise.resolve( { reference: 'DON-1', status_token: 'st', status: 'pending', amount_cents: 2500, currency: 'USD', gateway: 'offline' } ),
    } ) );
} );

test( 'a form with an extra of its own still sends what its fields add', async () => {
    const body = await donate(
        config( { extra: { fundraiser_ctx: 'signed' } } ),
        { extra: { gift_aid: true } }
    );

    expect( body.extra ).toEqual( { fundraiser_ctx: 'signed', gift_aid: true } );
} );

test( 'where both name the same thing, the form\'s own value is the one sent', async () => {
    const body = await donate(
        config( { extra: { fundraiser_ctx: 'signed' } } ),
        { extra: { fundraiser_ctx: 'typed by somebody', tribute: { name: 'Rex' } } }
    );

    expect( body.extra ).toEqual( { fundraiser_ctx: 'signed', tribute: { name: 'Rex' } } );
} );

test( 'a form with no extra of its own sends what its fields add', async () => {
    const body = await donate( config(), { extra: { gift_aid: true } } );

    expect( body.extra ).toEqual( { gift_aid: true } );
} );

test( 'a form with an extra of its own and no field adding to it sends its own', async () => {
    const body = await donate( config( { extra: { fundraiser_ctx: 'signed' } } ), null );

    expect( body.extra ).toEqual( { fundraiser_ctx: 'signed' } );
} );

test( 'a form with neither sends none', async () => {
    const body = await donate( config(), null );

    expect( body.extra ).toBeUndefined();
} );
