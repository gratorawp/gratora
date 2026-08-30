/**
 * A donor sent to their bank comes back on a fresh page load, carrying nothing
 * but the markers on the URL. What the form does with those markers is the only
 * account of the payment the donor gets in the browser.
 *
 * Mounted through the real runtime rather than against the helpers, because the
 * defects are in the wiring: which form claims the markers, and which sentence
 * the donor is shown for a payment that did not complete.
 */

const CANCELLED  = 'Your payment was not completed, so nothing has been charged. Please try again when you are ready.';
const GENERIC    = 'Sorry, something went wrong. Please try again.';
const THANKS     = 'Thank you for your donation!';
const UNRESOLVED = 'We could not check on your payment, and your bank may still have taken it. Please do not pay again yet.';
const CHECK      = 'Check again';

// Prefixed so the jest.mock factory below may close over it.
let mockStatus = 'succeeded';

// The load failure the donor hits when js.stripe.com is blocked on the way
// back, which is a different thing from an intent parked mid-authentication.
const UNREACHABLE = '__stripe_unreachable__';

jest.mock( '../../assets/donation-form/util/stripe', () => {
    const actual = jest.requireActual( '../../assets/donation-form/util/stripe' );

    return {
        ...actual,
        // The only part of the return that talks to Stripe. Everything else,
        // including the ownership test and the status mapping, stays real.
        resolveStripeReturn: () => ( mockStatus === UNREACHABLE
            ? Promise.reject( new Error( 'stripe-load-failed' ) )
            : Promise.resolve( mockStatus ) ),
        loadStripeJs:        () => Promise.resolve( () => ( {} ) ),
    };
} );

function config( overrides = {} ) {
    return {
        slug:     'probe',
        form_id:  7,
        currency: 'EUR',
        gateway:  'stripe',
        layout:   'inline',
        stripe:   { publishableKey: 'pk_test_probe' },
        steps: [ {
            id:    'amount',
            type:  'amount',
            items: [ { kind: 'amount', presets: [ 1000 ] } ],
        } ],
        i18n: {
            error:            GENERIC,
            notCompleted:     CANCELLED,
            returnUnresolved: UNRESOLVED,
            checkAgain:       CHECK,
            thanks:       THANKS,
            confirming:   'Confirming your payment…',
            donateAgain:  'Donate again',
        },
        ...overrides,
    };
}

function addForm( id, cfg ) {
    const form = document.createElement( 'form' );
    form.className = 'fundkit-donation-form';
    form.id = id;

    const json = document.createElement( 'script' );
    json.type = 'application/json';
    json.setAttribute( 'data-fundkit-form-config', '' );
    json.textContent = JSON.stringify( cfg );
    form.appendChild( json );

    document.body.appendChild( form );

    return form;
}

function returningFrom( reference, formKey ) {
    window.history.replaceState( {}, '', '/campaign/?fundkit_return=1&fundkit_ref=' + reference
        + '&payment_intent_client_secret=pi_probe_secret' );
    window.sessionStorage.setItem( 'fundkit:pending-donation', JSON.stringify( {
        reference,
        statusToken: 'tok',
        formKey,
        amountCents: 1000,
        currency:    'EUR',
    } ) );
}

// The runtime boots itself on import, so each test needs its own module copy.
async function boot() {
    jest.isolateModules( () => {
        require( '../../assets/donation-form/runtime.jsx' );
    } );

    // preact defers effects behind a frame, the retrieve resolves on a promise
    // after that, and the outcome renders on the frame after that.
    await new Promise( ( r ) => setTimeout( r, 50 ) );
    await new Promise( ( r ) => setTimeout( r, 50 ) );
}

beforeEach( () => {
    document.body.innerHTML = '';
    window.sessionStorage.clear();
    mockStatus = 'succeeded';
} );

describe( 'a redirect that did not end in a payment', () => {
    test( 'a donor who cancelled at their bank is told nothing was charged', async () => {
        returningFrom( 'FUNDKIT-2026-00042', 'fundkit-form-1' );
        const form = addForm( 'fundkit-form-1', config() );

        mockStatus = 'requires_payment_method';
        await boot();

        expect( form.textContent ).toContain( CANCELLED );
        expect( form.textContent ).not.toContain( GENERIC );
    } );

    test( 'a completed payment clears the markers behind it', async () => {
        returningFrom( 'FUNDKIT-2026-00047', 'fundkit-form-1' );
        addForm( 'fundkit-form-1', config() );

        await boot();

        expect( window.location.search ).not.toContain( 'payment_intent_client_secret' );
    } );
} );

describe( 'a return the browser could not resolve', () => {
    const { settle } = require( './support/waitFor' );

    test( 'an intent parked mid-authentication is not answered with try again', async () => {
        returningFrom( 'FUNDKIT-2026-00043', 'fundkit-form-1' );
        const form = addForm( 'fundkit-form-1', config() );

        mockStatus = 'requires_action';
        await boot();

        expect( form.textContent ).toContain( UNRESOLVED );
        expect( form.textContent ).not.toContain( GENERIC );
        expect( form.textContent ).not.toContain( CANCELLED );
    } );

    test( 'Stripe being unreachable on the way back is not answered with try again', async () => {
        returningFrom( 'FUNDKIT-2026-00048', 'fundkit-form-1' );
        const form = addForm( 'fundkit-form-1', config() );

        mockStatus = UNREACHABLE;
        await boot();

        expect( form.textContent ).toContain( UNRESOLVED );
        expect( form.textContent ).not.toContain( GENERIC );
    } );

    test( 'the markers survive, so reloading the page runs the check again', async () => {
        returningFrom( 'FUNDKIT-2026-00049', 'fundkit-form-1' );
        addForm( 'fundkit-form-1', config() );

        mockStatus = UNREACHABLE;
        await boot();

        expect( window.location.search ).toContain( 'payment_intent_client_secret=pi_probe_secret' );
        expect( window.location.search ).toContain( 'fundkit_return=1' );

        // The reload a donor would do, on the same URL they were left with.
        document.body.innerHTML = '';
        const reloaded = addForm( 'fundkit-form-1', config() );
        mockStatus = 'succeeded';
        await boot();

        expect( reloaded.textContent ).toContain( THANKS );
    } );

    test( 'checking again from the screen settles a payment that has since gone through', async () => {
        returningFrom( 'FUNDKIT-2026-00050', 'fundkit-form-1' );
        const form = addForm( 'fundkit-form-1', config() );

        mockStatus = 'requires_action';
        await boot();

        const retry = [ ...form.querySelectorAll( 'button' ) ]
            .find( ( b ) => b.textContent.trim() === CHECK );
        expect( retry ).toBeDefined();

        mockStatus = 'succeeded';
        retry.click();
        await settle();
        await settle();

        expect( form.textContent ).toContain( THANKS );
        expect( window.location.search ).not.toContain( 'payment_intent_client_secret' );
    } );
} );

describe( 'two forms on one page', () => {
    test( 'the form the donor submitted from claims the return, not the first one', async () => {
        returningFrom( 'FUNDKIT-2026-00044', 'fundkit-form-2' );
        const first  = addForm( 'fundkit-form-1', config() );
        const second = addForm( 'fundkit-form-2', config() );

        await boot();

        expect( second.textContent ).toContain( THANKS );
        expect( first.textContent ).not.toContain( THANKS );
    } );

    test( 'a stash naming a form that is no longer on the page still reaches the donor', async () => {
        returningFrom( 'FUNDKIT-2026-00045', 'fundkit-form-9' );
        const only = addForm( 'fundkit-form-1', config() );

        await boot();

        expect( only.textContent ).toContain( THANKS );
    } );

    test( 'only the form that claimed the return opens its own modal', async () => {
        // Storage refused, so nothing names a form and every ownership test
        // falls through. One form claims the return and renders the outcome; a
        // second modal springing open beside it holds a blank form and no
        // account of the payment, which reads as a second donation being asked
        // for.
        window.history.replaceState( {}, '', '/campaign/?fundkit_return=1&fundkit_ref=FUNDKIT-2026-00046'
            + '&payment_intent_client_secret=pi_probe_secret' );

        const first  = addForm( 'fundkit-form-1', config( { layout: 'modal' } ) );
        const second = addForm( 'fundkit-form-2', config( { layout: 'modal' } ) );

        await boot();

        expect( first.querySelector( '.fundkit-modal' ) ).not.toBeNull();
        expect( first.textContent ).toContain( THANKS );
        expect( second.querySelector( '.fundkit-modal' ) ).toBeNull();
    } );
} );
