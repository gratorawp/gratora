/**
 * On the bar progress style the Back arrow stayed in the header once the
 * Payment Element mounted. The reducer discards the click at that status, so
 * the donor presses a control that does nothing on the one screen where they
 * most want a way out.
 */

const { settle } = require( './support/waitFor' );

jest.mock( '../../assets/donation-form/util/stripe', () => {
    const actual = jest.requireActual( '../../assets/donation-form/util/stripe' );

    return {
        ...actual,
        loadStripeJs: () => Promise.resolve( () => ( {
            elements: () => ( {
                create: () => ( {
                    on: ( ev, cb ) => { if ( ev === 'ready' ) cb(); },
                    mount() {}, destroy() {}, update() {},
                } ),
                update() {},
            } ),
            confirmPayment: () => new Promise( () => {} ),
        } ) ),
    };
} );

const gatewayBlock = { kind: 'payment-gateways' };

const CONFIG = {
    slug:     'appeal',
    form_id:  7,
    currency: 'USD',
    gateway:  'stripe',
    layout:   'paged',
    rest:     'https://example.test/wp-json/fundkit/v1/donations',
    stripe:   { publishableKey: 'pk_test_probe' },
    gateways: { options: [ { id: 'stripe', label: 'Card' } ] },
    pages:    [ { title: 'Amount' }, { title: 'Confirm' } ],
    pageNav:  { progressStyle: 'bar' },
    // The gateway block on the LAST page, beside the submit, so the payment UI
    // has somewhere on-screen to mount and PagedView keeps rendering.
    steps: [
        { id: 'amount', type: 'amount', page: 0, presets: [ 2500 ] },
        { id: 'submit', type: 'submit', page: 1, items: [ gatewayBlock ] },
    ],
    i18n: {
        donateNow: 'Donate now', processing: 'Processing', next: 'Next', prev: 'Back',
        error: 'Oops.', pay: 'Pay', cancel: 'Cancel',
    },
};

function addForm() {
    const form = document.createElement( 'form' );
    form.className = 'fundkit-donation-form';
    form.id = 'fundkit-form-7';

    const json = document.createElement( 'script' );
    json.type = 'application/json';
    json.setAttribute( 'data-fundkit-form-config', '' );
    json.textContent = JSON.stringify( CONFIG );
    form.appendChild( json );

    document.body.appendChild( form );
}

const primary = () => document.querySelector( '.fundkit-form__button--primary' );
const backButton = () => document.querySelector( 'button.fundkit-form__bar-back' );

beforeEach( () => {
    document.body.innerHTML = '';
    window.fundkit = {
        default_currency: 'USD',
        number_format: { decimalPlaces: 2, decimalSep: '.', thousandSep: ',', symbolPosition: 'before', symbol: '$' },
    };
    global.fetch = jest.fn( () => Promise.resolve( {
        ok:   true,
        json: () => Promise.resolve( {
            reference: 'DN-1', client_secret: 'pi_1_secret_abc', gateway: 'stripe',
        } ),
    } ) );
} );

afterEach( () => { delete window.fundkit; } );

async function boot() {
    addForm();
    jest.isolateModules( () => { require( '../../assets/donation-form/runtime.jsx' ); } );
    await settle();

    // Pick the preset, or the submit is refused for a missing amount and the
    // form walks back to the page the invalid field is on.
    const preset = document.querySelector( '.fundkit-form__amount-preset' )
        || [ ...document.querySelectorAll( 'button' ) ].find( ( b ) => /25/.test( b.textContent ) );
    if ( preset ) {
        preset.click();
        await settle();
    }
}

it( 'offers Back on an ordinary page', async () => {
    await boot();

    primary().click();
    await settle();

    expect( backButton() ).toBeTruthy();
} );

it( 'takes it away once the payment step is up', async () => {
    await boot();

    primary().click();
    await settle();
    expect( backButton() ).toBeTruthy();

    // Read the label BEFORE clicking: preact reuses the node, so afterwards the
    // same element already carries the next label.
    for ( let i = 0; i < 5; i++ ) {
        const b = primary();
        if ( ! b ) break;
        const wasSubmit = /donate/i.test( b.textContent );
        b.click();
        await settle();
        if ( wasSubmit ) break;
    }
    // formRootClass marks the payment status with --settled.
    for ( let i = 0; i < 10 && ! document.querySelector( '.fundkit-form--settled' ); i++ ) {
        await settle();
    }

    expect( document.querySelector( '.fundkit-form--settled' ) ).toBeTruthy();
    expect( backButton() ).toBeNull();
    // The placeholder stays, so the title does not jump.
    expect( document.querySelector( 'span.fundkit-form__bar-back' ) ).toBeTruthy();
} );
