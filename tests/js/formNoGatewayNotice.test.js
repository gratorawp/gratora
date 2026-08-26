/**
 * The Donate button goes dead when nothing on the form can take money, and the
 * only thing that says why is the payment-gateways block. All three shipped
 * templates carry it, so the default path explains itself, but an author who
 * removes it leaves a donor pressing a button that will never work with nothing
 * on screen telling them so. The author is not the one who suffers, so a
 * warning on the admin readiness screen does not settle it.
 */

const NONE      = 'Online donations are unavailable right now. Please try again later.';
const CURRENCY  = 'No payment method here accepts %s. Choose another currency to continue.';

function config( overrides = {} ) {
    return {
        slug:     'appeal',
        form_id:  7,
        currency: 'USD',
        gateway:  'offline',
        layout:   'inline',
        rest:     'https://example.test/wp-json/giveflow/v1/donations',
        gateways: { options: [] },
        steps: [
            { id: 'amount', type: 'amount', presets: [ 2500 ] },
            { id: 'submit', type: 'submit' },
        ],
        i18n: {
            donateNow:            'Donate now',
            processing:           'Processing',
            noGatewayAvailable:   NONE,
            noGatewayForCurrency: CURRENCY,
        },
        ...overrides,
    };
}

// The block as the shortcode emits it into a donor step's items.
const gatewayBlock = { kind: 'payment-gateways' };

function addForm( cfg ) {
    const form = document.createElement( 'form' );
    form.className = 'giveflow-donation-form';
    form.id = 'giveflow-form-7';

    const json = document.createElement( 'script' );
    json.type = 'application/json';
    json.setAttribute( 'data-giveflow-form-config', '' );
    json.textContent = JSON.stringify( cfg );
    form.appendChild( json );

    document.body.appendChild( form );
    return form;
}

async function boot( cfg ) {
    addForm( cfg );
    jest.isolateModules( () => {
        require( '../../assets/donation-form/runtime.jsx' );
    } );
    await settle();
}

const { settle } = require( './support/waitFor' );

const button = () => document.querySelector( '.giveflow-form__button--primary' );
const notices = () => [ ...document.querySelectorAll( '.giveflow-form__gateways-empty' ) ]
    .map( ( n ) => n.textContent );

beforeEach( () => {
    document.body.innerHTML = '';
    window.giveflow = {
        default_currency: 'USD',
        number_format: { decimalPlaces: 2, decimalSep: '.', thousandSep: ',', symbolPosition: 'before', symbol: '$' },
    };
    global.fetch = jest.fn( () => Promise.reject( new Error( 'no request expected' ) ) );
} );

test( 'a form with no gateway block still says why its Donate button is dead', async () => {
    await boot( config() );

    expect( button() ).toBeTruthy();
    expect( button().disabled ).toBe( true );
    expect( notices() ).toEqual( [ NONE ] );
} );

// The gateway section says it where the author kept the block, so a second copy
// beside the button would be the same sentence twice.
test( 'a form that kept the block is not told twice', async () => {
    await boot( config( {
        steps: [
            { id: 'amount', type: 'amount', presets: [ 2500 ] },
            { id: 'donor',  type: 'donor', items: [ gatewayBlock ] },
            { id: 'submit', type: 'submit' },
        ],
    } ) );

    expect( notices() ).toEqual( [ NONE ] );
} );

test( 'the notice names the currency when the currency is what rules them out', async () => {
    await boot( config( {
        currency: 'INR',
        gateways: {
            options: [ {
                id: 'stripe', label: 'Card', currencies: [ 'USD' ], countries: [ '*' ], frequencies: [ 'one_time' ],
            } ],
        },
    } ) );

    expect( notices() ).toEqual( [ 'No payment method here accepts INR. Choose another currency to continue.' ] );
} );

test( 'a form that can take money says nothing', async () => {
    await boot( config( {
        gateways: {
            options: [ {
                id: 'offline', label: 'Bank transfer', currencies: [ '*' ], countries: [ '*' ], frequencies: [ 'one_time' ],
            } ],
        },
    } ) );

    expect( notices() ).toEqual( [] );
    expect( button().disabled ).toBe( false );
} );

/**
 * A paged form puts its submit on the last page. A gateway block on page one is
 * no use to a donor looking at the button on page three.
 */
test( 'a paged form explains itself on the page the submit is on', async () => {
    await boot( config( {
        pages: [ { title: 'Amount' }, { title: 'Details' } ],
        steps: [
            { id: 'amount', type: 'amount', page: 0, presets: [ 2500 ] },
            { id: 'pay',    type: 'donor',  page: 0, items: [ gatewayBlock ] },
            { id: 'submit', type: 'submit', page: 1 },
        ],
    } ) );

    // Page one hosts the block, so the section it renders is what speaks there.
    expect( document.querySelectorAll( '.giveflow-form__payment .giveflow-form__gateways-empty' ) ).toHaveLength( 1 );

    document.querySelector( '.giveflow-form__button--primary' ).click();
    await settle();

    expect( document.querySelector( '.giveflow-form__page-title' ).textContent ).toBe( 'Details' );
    expect( notices() ).toEqual( [ NONE ] );
    // And here it is the submit speaking, because the section stayed behind.
    expect( document.querySelectorAll( '.giveflow-form__payment' ) ).toHaveLength( 0 );
} );
