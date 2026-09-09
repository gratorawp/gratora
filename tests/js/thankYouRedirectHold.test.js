/**
 * A form with a thank-you redirect replaces the page in the same block that
 * announces the donation. Anything a listener started and had not finished by
 * then went away with the document, which is how an add-on that reports the
 * conversion to an ad platform came to report nothing at all on exactly the
 * forms an organisation set up for it.
 *
 * Mounted through the real runtime, because the ordering between the
 * announcement and the navigation is the whole of the defect.
 */

let mockStatus = 'succeeded';

jest.mock( '../../assets/donation-form/util/stripe', () => {
    const actual = jest.requireActual( '../../assets/donation-form/util/stripe' );

    return {
        ...actual,
        resolveStripeReturn: () => Promise.resolve( mockStatus ),
        loadStripeJs:        () => Promise.resolve( () => ( {} ) ),
    };
} );

const REDIRECT = 'https://example.org/thank-you/';

let assigned;

// jsdom refuses both a spy on Location and a real navigation, so the whole
// object is swapped for a readable stand-in that records the destination.
function captureNavigation( search ) {
    assigned = [];
    delete window.location;
    Object.defineProperty( window, 'location', {
        configurable: true,
        writable:     true,
        value: {
            href:     'http://localhost/campaign/' + search,
            origin:   'http://localhost',
            pathname: '/campaign/',
            search,
            assign:   ( url ) => assigned.push( url ),
        },
    } );
}

function config() {
    return {
        slug:     'probe',
        form_id:  7,
        currency: 'EUR',
        gateway:  'stripe',
        layout:   'inline',
        stripe:   { publishableKey: 'pk_test_probe' },
        thanks:   { message: '', redirect: REDIRECT },
        steps: [ {
            id:    'amount',
            type:  'amount',
            items: [ { kind: 'amount', presets: [ 1000 ] } ],
        } ],
        i18n: {
            error:        'Sorry, something went wrong. Please try again.',
            notCompleted: 'Your payment was not completed.',
            thanks:       'Thank you for your donation!',
            confirming:   'Confirming your payment…',
            donateAgain:  'Donate again',
        },
    };
}

function addForm( cfg ) {
    const form = document.createElement( 'form' );
    form.className = 'gratora-donation-form';
    form.id = 'gratora-form-1';

    const json = document.createElement( 'script' );
    json.type = 'application/json';
    json.setAttribute( 'data-gratora-form-config', '' );
    json.textContent = JSON.stringify( cfg );
    form.appendChild( json );

    document.body.appendChild( form );

    return form;
}

function returningFrom( reference ) {
    const search = '?gratora_return=1&gratora_ref=' + reference
        + '&payment_intent_client_secret=pi_probe_secret';
    captureNavigation( search );
    window.sessionStorage.setItem( 'gratora:pending-donation', JSON.stringify( {
        reference,
        statusToken: 'tok',
        formKey:     'gratora-form-1',
        amountCents: 1000,
        currency:    'EUR',
    } ) );
}

async function boot() {
    jest.isolateModules( () => {
        require( '../../assets/donation-form/runtime.jsx' );
    } );

    await tick();
    await tick();
}

function tick() {
    return new Promise( ( resolve ) => setTimeout( resolve, 50 ) );
}

let listener = null;

// One jsdom window serves the whole file, so a listener left attached would
// hold the next test's redirect open.
function listen( handler ) {
    listener = handler;
    window.addEventListener( 'gratora:donation:completed', listener );
}

beforeEach( () => {
    document.body.innerHTML = '';
    window.sessionStorage.clear();
    mockStatus = 'succeeded';
    returningFrom( 'GRATORA-2026-00042' );
} );

afterEach( () => {
    if ( listener ) window.removeEventListener( 'gratora:donation:completed', listener );
    listener = null;
    jest.useRealTimers();
} );

describe( 'a thank-you redirect and the listeners it leaves behind', () => {
    test( 'a listener that asked to be waited for finishes before the page is replaced', async () => {
        let settled = false;
        let navigatedBeforeSettling = null;

        listen( ( event ) => {
            event.detail.waitUntil( tick().then( () => {
                navigatedBeforeSettling = assigned.length > 0;
                settled = true;
            } ) );
        } );

        addForm( config() );
        await boot();
        await tick();
        await tick();

        expect( settled ).toBe( true );
        expect( navigatedBeforeSettling ).toBe( false );
        expect( assigned ).toEqual( [ REDIRECT ] );
    } );

    test( 'a listener that never settles does not strand the donor on the form', async () => {
        jest.useFakeTimers();

        listen( ( event ) => {
            event.detail.waitUntil( new Promise( () => {} ) );
        } );

        addForm( config() );

        jest.isolateModules( () => {
            require( '../../assets/donation-form/runtime.jsx' );
        } );

        await jest.advanceTimersByTimeAsync( 100 );
        expect( assigned ).toEqual( [] );

        await jest.advanceTimersByTimeAsync( 5000 );
        expect( assigned ).toEqual( [ REDIRECT ] );
    } );

    test( 'a form nobody is listening to redirects without waiting', async () => {
        addForm( config() );
        await boot();

        expect( assigned ).toEqual( [ REDIRECT ] );
    } );
} );
