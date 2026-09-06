/**
 * A donor who ticks "cover the fees" on a monthly donation is shown Amount $50.00,
 * Processing fee $2.30, Total $52.30 on the review step, presses Donate, and
 * the thank-you card says "Amount $52.30". Same word, different number, one
 * screen apart, on the figure their card was charged.
 *
 * The card has only the charged total to draw: the submit response carries
 * amount_cents and the redirect stash amountCents, neither of which splits the
 * fee back out. So the label follows the number.
 */

// Prefixed so the jest.mock factory below may close over it.
let mockStatus = 'succeeded';

jest.mock( '../../assets/donation-form/util/stripe', () => {
    const actual = jest.requireActual( '../../assets/donation-form/util/stripe' );

    return {
        ...actual,
        resolveStripeReturn: () => Promise.resolve( mockStatus ),
        loadStripeJs:        () => Promise.resolve( () => ( {} ) ),
    };
} );

const I18N = {
    amount:  'Amount',
    total:   'Total',
    thanks:  'Thank you for your donation!',
    error:   'Sorry, something went wrong.',
    monthly: 'Monthly',
    confirming: 'Confirming your payment…',
};

function addForm() {
    const form = document.createElement( 'form' );
    form.className = 'fundkit-donation-form';
    form.id = 'fundkit-form-7';

    const json = document.createElement( 'script' );
    json.type = 'application/json';
    json.setAttribute( 'data-fundkit-form-config', '' );
    json.textContent = JSON.stringify( {
        slug:     'probe',
        form_id:  7,
        currency: 'USD',
        gateway:  'stripe',
        layout:   'inline',
        stripe:   { publishableKey: 'pk_test_probe' },
        steps:    [ { id: 'amount', type: 'amount', items: [ { kind: 'amount', presets: [ 5000 ] } ] } ],
        i18n:     I18N,
    } );
    form.appendChild( json );

    document.body.appendChild( form );

    return form;
}

function returningFrom( frequency ) {
    window.history.replaceState( {}, '', '/campaign/?fundkit_return=1&fundkit_ref=FUNDKIT-2026-00050'
        + '&payment_intent_client_secret=pi_probe_secret' );
    window.sessionStorage.setItem( 'fundkit:pending-donation', JSON.stringify( {
        reference:   'FUNDKIT-2026-00050',
        statusToken: 'tok',
        formKey:     'fundkit-form-7',
        amountCents: 5230,
        currency:    'USD',
        frequency,
    } ) );
}

async function boot() {
    jest.isolateModules( () => { require( '../../assets/donation-form/runtime.jsx' ); } );
    await new Promise( ( r ) => setTimeout( r, 50 ) );
    await new Promise( ( r ) => setTimeout( r, 50 ) );
}

const receiptLabel = () =>
    document.querySelector( '.fundkit-form__summary--receipt dt' )?.textContent.trim() ?? null;

beforeEach( () => {
    document.body.innerHTML = '';
    window.sessionStorage.clear();
    mockStatus = 'succeeded';
    window.fundkit = {
        default_currency: 'USD',
        number_format: { decimalPlaces: 2, decimalSep: '.', thousandSep: ',', symbolPosition: 'before', symbol: '$' },
    };
} );

afterEach( () => { delete window.fundkit; } );

it( 'calls the charged figure a total on a recurring donation', async () => {
    returningFrom( 'monthly' );
    addForm();
    await boot();

    expect( receiptLabel() ).toBe( 'Total' );
    expect( document.body.textContent ).toContain( '$52.30' );
} );

it( 'and the same on a one-off, so the word never moves', async () => {
    returningFrom( 'one_time' );
    addForm();
    await boot();

    expect( receiptLabel() ).toBe( 'Total' );
} );
