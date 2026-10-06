/**
 * A form with nothing switched on tells a donor only that it cannot take a
 * donation. The person who manages the plugin is shown why, with a way to put
 * it right, and the summary stops naming a payment method that is not there.
 */

const { settle } = require( './support/waitFor' );

const NONE     = 'Online donations are unavailable right now. Please try again later.';
const CURRENCY = 'No payment method here accepts %s. Choose another currency to continue.';

const OWNER = {
    text:      'Only you can see this. This form cannot take a donation, because no payment method is switched on. Connect payments, or turn on test mode.',
    linkLabel: 'Open payment settings',
    linkUrl:   'https://example.test/wp-admin/admin.php?page=gratora-settings#gateways',
};

const BANK = { id: 'offline', label: 'Bank transfer', currencies: [ '*' ], countries: [ '*' ], frequencies: [ 'one_time' ] };

const gatewayBlock = { kind: 'payment-gateways' };
const summaryBlock = { kind: 'summary' };

function config( overrides = {} ) {
    return {
        slug:     'appeal',
        form_id:  7,
        currency: 'USD',
        gateway:  'offline',
        layout:   'inline',
        rest:     'https://example.test/wp-json/gratora/v1/donations',
        gateways: { options: [] },
        steps: [
            { id: 'amount', type: 'amount', presets: [ 2500 ] },
            { id: 'review', type: 'donor', items: [ gatewayBlock, summaryBlock ] },
            { id: 'submit', type: 'submit' },
        ],
        i18n: {
            donateNow:            'Donate now',
            processing:           'Processing',
            amount:               'Amount',
            total:                'Total',
            paymentMethod:        'Payment method',
            noGatewayAvailable:   NONE,
            noGatewayForCurrency: CURRENCY,
        },
        ...overrides,
    };
}

async function boot( cfg ) {
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
}

const notices = () => [ ...document.querySelectorAll( '.gratora-form__gateways-empty' ) ];
const summaryLabels = () => [ ...document.querySelectorAll( '.gratora-form__summary-row dt' ) ].map( ( dt ) => dt.textContent );

beforeEach( () => {
    document.body.innerHTML = '';
    window.gratora = {
        default_currency: 'USD',
        number_format: { decimalPlaces: 2, decimalSep: '.', thousandSep: ',', symbolPosition: 'before', symbol: '$' },
    };
    global.fetch = jest.fn( () => Promise.reject( new Error( 'no request expected' ) ) );
} );

test( 'whoever manages the plugin reads why, with a link to where it is put right', async () => {
    await boot( config( { ownerNotice: OWNER } ) );

    expect( notices() ).toHaveLength( 1 );
    expect( notices()[ 0 ].textContent ).toContain( OWNER.text );
    expect( notices()[ 0 ].textContent ).not.toContain( NONE );

    const link = notices()[ 0 ].querySelector( 'a' );
    expect( link.textContent ).toBe( 'Open payment settings' );
    expect( link.getAttribute( 'href' ) ).toBe( OWNER.linkUrl );
} );

test( 'a donor reads only that it cannot', async () => {
    await boot( config() );

    expect( notices().map( ( n ) => n.textContent ) ).toEqual( [ NONE ] );
    expect( document.querySelector( '.gratora-form__gateways-empty a' ) ).toBeNull();
} );

test( 'on a form with no payment section the same line stands beside the button', async () => {
    await boot( config( {
        ownerNotice: OWNER,
        steps: [
            { id: 'amount', type: 'amount', presets: [ 2500 ] },
            { id: 'submit', type: 'submit' },
        ],
    } ) );

    expect( notices() ).toHaveLength( 1 );
    expect( notices()[ 0 ].textContent ).toContain( OWNER.text );
} );

// Something is switched on here, so "nothing is switched on" would be false,
// and the currency is the thing to change.
test( 'when the currency is what rules the methods out, the owner is told that instead', async () => {
    await boot( config( {
        ownerNotice: OWNER,
        currency:    'INR',
        gateways:    { options: [ { id: 'stripe', label: 'Card', currencies: [ 'USD' ], countries: [ '*' ], frequencies: [ 'one_time' ] } ] },
    } ) );

    expect( notices().map( ( n ) => n.textContent ) ).toEqual( [ 'No payment method here accepts INR. Choose another currency to continue.' ] );
} );

test( 'the summary names no payment method when there is none', async () => {
    await boot( config() );

    expect( summaryLabels() ).toEqual( [ 'Amount', 'Total' ] );
} );

test( 'the summary names the method there is', async () => {
    await boot( config( { gateways: { options: [ BANK ] } } ) );

    expect( summaryLabels() ).toEqual( [ 'Amount', 'Payment method', 'Total' ] );
    expect( document.querySelector( '.gratora-form__summary' ).textContent ).toContain( 'Bank transfer' );
} );
