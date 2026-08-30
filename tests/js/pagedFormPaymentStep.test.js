/**
 * On a multi-page form the payment step renders inside the gateway block, and
 * PagedView draws only the current page's steps. The fallback that replaces the
 * body when there is nowhere to host the payment UI asked whether the form HAS
 * a gateway block, not whether one is ON SCREEN, so a form whose block sits on
 * an earlier page than the submit reached `payment` status with the payment UI
 * rendered nowhere at all: a blank form and a donation already created that
 * could never be paid.
 *
 * gatewaysExplainedBeside() in the same file exists precisely because an author
 * can "put the block on another page", so this is a configuration the product
 * already knows about rather than a hypothetical.
 */

const { settle } = require( './support/waitFor' );

const CLIENT_SECRET = 'pi_test_secret_123';

function config( steps ) {
    return {
        slug:     'appeal',
        form_id:  7,
        currency: 'USD',
        gateway:  'stripe',
        layout:   'paged',
        rest:     'https://example.test/wp-json/fundkit/v1/donations',
        stripe:   { publishableKey: 'pk_test_123' },
        gateways: { options: [ { id: 'stripe', label: 'Card' } ] },
        // Non-empty pages is what puts the runtime into PagedView.
        pages: [ { title: 'Amount' }, { title: 'Details' }, { title: 'Confirm' } ],
        steps,
        i18n: {
            donateNow:  'Donate now',
            processing: 'Processing',
            next:       'Next',
            prev:       'Back',
            error:      'Something went wrong.',
        },
    };
}

const gatewayBlock = { kind: 'payment-gateways' };

// The block on page 0, the submit two pages later. Legal, and the shape that
// used to render no payment UI at all.
const BLOCK_ON_AN_EARLIER_PAGE = [
    { id: 'amount', type: 'amount', page: 0, presets: [ 2500 ], items: [ gatewayBlock ] },
    { id: 'donor',  type: 'donor',  page: 1 },
    { id: 'submit', type: 'submit', page: 2 },
];

function addForm( cfg ) {
    const form = document.createElement( 'form' );
    form.className = 'fundkit-donation-form';
    form.id = 'fundkit-form-7';

    const json = document.createElement( 'script' );
    json.type = 'application/json';
    json.setAttribute( 'data-fundkit-form-config', '' );
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

const primary = () => document.querySelector( '.fundkit-form__button--primary' );
const back    = () => document.querySelector( '.fundkit-form__button--secondary' );

beforeEach( () => {
    document.body.innerHTML = '';
    window.fundkit = {
        default_currency: 'USD',
        number_format: { decimalPlaces: 2, decimalSep: '.', thousandSep: ',', symbolPosition: 'before', symbol: '$' },
    };
    global.fetch = jest.fn( () => Promise.resolve( {
        ok:   true,
        json: () => Promise.resolve( {
            reference:     'DN-1',
            client_secret: CLIENT_SECRET,
            gateway:       'stripe',
        } ),
    } ) );
} );

/** Walk to the last page, then submit. */
async function submitFromLastPage() {
    for ( let i = 0; i < 5; i++ ) {
        const b = primary();
        expect( b ).toBeTruthy();
        // Read the label BEFORE clicking: preact reuses the node, so after the
        // re-render this same element already carries the next page's label.
        const wasSubmit = /donate/i.test( b.textContent );
        b.click();
        await settle();
        if ( wasSubmit ) return;
    }
    throw new Error( 'never reached the submit' );
}

const paymentPanel = () => document.querySelector( '.fundkit-form--payment' );

test( 'a block on an earlier page still gets somewhere to draw the payment step', async () => {
    await boot( config( BLOCK_ON_AN_EARLIER_PAGE ) );
    await submitFromLastPage();

    expect( global.fetch ).toHaveBeenCalled();
    expect( paymentPanel() ).toBeTruthy();
    expect( paymentPanel().textContent ).toContain( 'Complete your donation' );
} );

test( 'a block on the submit page is still hosted there, not replaced', async () => {
    // The normal shape: the gateway block sits with the submit, so the payment
    // step renders inside it and the form stays on screen behind.
    await boot( config( [
        { id: 'amount', type: 'amount', page: 0, presets: [ 2500 ] },
        { id: 'donor',  type: 'donor',  page: 1 },
        { id: 'submit', type: 'submit', page: 2, items: [ gatewayBlock ] },
    ] ) );
    await submitFromLastPage();

    expect( global.fetch ).toHaveBeenCalled();
    // No full-width takeover: the page's own steps are still rendered.
    expect( document.querySelector( '.fundkit-form--payment' ) ).toBeNull();
} );

test( 'Back is disabled while a submit is in flight', async () => {
    // A submit that never resolves leaves the form in `submitting`.
    global.fetch = jest.fn( () => new Promise( () => {} ) );

    await boot( config( BLOCK_ON_AN_EARLIER_PAGE ) );

    primary().click();
    await settle();
    expect( back() ).toBeTruthy();
    expect( back().disabled ).toBe( false );

    primary().click();
    await settle();
    primary().click();
    await settle();

    expect( back() ).toBeTruthy();
    expect( back().disabled ).toBe( true );
} );

/**
 * The donation route is public: the nonce rides along only so a logged-in donor
 * is recognised. WordPress rejects a PRESENT-and-stale one at the
 * authentication layer, before any permission callback, so a member who left
 * the form open overnight read a bare "Cookie check failed" under an intact
 * form, and every retry sent the same dead nonce baked into the config.
 */
describe( 'a stale nonce does not cost the donation', () => {
    const okBody = { reference: 'DN-1', client_secret: 'sec', gateway: 'stripe' };

    const staleNonce = () => ( {
        ok:     false,
        status: 403,
        clone() { return this; },
        json:   () => Promise.resolve( {
            code:    'rest_cookie_invalid_nonce',
            message: 'Cookie check failed',
        } ),
    } );

    const accepted = () => ( {
        ok:     true,
        status: 200,
        clone() { return this; },
        json:   () => Promise.resolve( okBody ),
    } );

    test( 'the submit is retried once without it, and goes through', async () => {
        const sent = [];
        global.fetch = jest.fn( ( url, opts ) => {
            sent.push( opts.headers[ 'X-WP-Nonce' ] );
            return Promise.resolve( sent.length === 1 ? staleNonce() : accepted() );
        } );

        await boot( { ...config( BLOCK_ON_AN_EARLIER_PAGE ), nonce: 'dead-nonce' } );
        await submitFromLastPage();

        expect( sent ).toEqual( [ 'dead-nonce', undefined ] );
        expect( document.querySelector( '.fundkit-form--payment' ) ).toBeTruthy();
    } );

    test( 'a 403 that is not about the nonce is not retried', async () => {
        global.fetch = jest.fn( () => Promise.resolve( {
            ok:     false,
            status: 403,
            clone() { return this; },
            json:   () => Promise.resolve( { code: 'fundkit_forbidden', message: 'No.' } ),
        } ) );

        await boot( { ...config( BLOCK_ON_AN_EARLIER_PAGE ), nonce: 'live-nonce' } );
        await submitFromLastPage();

        expect( global.fetch ).toHaveBeenCalledTimes( 1 );
    } );
} );
