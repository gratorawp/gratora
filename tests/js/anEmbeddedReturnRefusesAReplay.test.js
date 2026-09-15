/**
 * A donor sent to their bank comes back on a URL anybody can write. On the
 * charity's own page a form reads a return it cannot tie to a submission,
 * because a charity may write its own return URL and the alternative is showing
 * a paying donor nothing.
 *
 * Inside a frame that reasoning inverts: the party who wrote the URL and the
 * party who can empty this document's storage are the same party. A client
 * secret from any past donation on the account, replayed there, otherwise
 * renders a genuine branded thank-you over somebody else's theft. So the stash
 * must name the reference the URL names, and the server must agree the money
 * arrived.
 */

const { settle } = require( './support/waitFor' );

const THANKS     = 'Thank you for your donation!';
const UNRESOLVED = 'We could not check on your payment, and your bank may still have taken it.';
const REST       = 'https://example.test/wp-json/gratora/v1/donations';
const REFERENCE  = 'GRATORA-2026-00042';

let mockStatus = 'succeeded';

jest.mock( '../../assets/donation-form/util/stripe', () => {
    const actual = jest.requireActual( '../../assets/donation-form/util/stripe' );

    return {
        ...actual,
        // The only part of the return that talks to Stripe. The ownership test
        // and the status mapping stay real.
        resolveStripeReturn: () => Promise.resolve( mockStatus ),
        loadStripeJs:        () => Promise.resolve( () => ( {} ) ),
    };
} );

const embed = () => ( { origin: 'https://partner.test', key: 'ek_probe', tokenUrl: 'https://example.test/t' } );

function config( overrides = {} ) {
    return {
        slug:     'probe',
        form_id:  7,
        currency: 'EUR',
        gateway:  'stripe',
        layout:   'inline',
        rest:     REST,
        stripe:   { publishableKey: 'pk_test_probe' },
        steps: [ {
            id:    'amount',
            type:  'amount',
            items: [ { kind: 'amount', presets: [ 1000 ] } ],
        } ],
        i18n: {
            error:            'Sorry, something went wrong. Please try again.',
            notCompleted:     'Your payment was not completed, so nothing has been charged.',
            returnUnresolved: UNRESOLVED,
            checkAgain:       'Check again',
            thanks:           THANKS,
            confirming:       'Confirming your payment…',
            donateAgain:      'Donate again',
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

function returningFrom( reference ) {
    const ref = reference === null ? '' : `&gratora_ref=${ reference }`;
    window.history.replaceState( {}, '', `/embed/?gratora_return=1${ ref }`
        + '&payment_intent_client_secret=pi_probe_secret' );
}

function stashed( reference ) {
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

    await settle();
    await settle();
}

let serverStatus = 'paid';
let statusCalls  = [];

beforeEach( () => {
    document.body.innerHTML = '';
    window.sessionStorage.clear();
    mockStatus   = 'succeeded';
    serverStatus = 'paid';
    statusCalls  = [];

    global.fetch = jest.fn( ( url ) => {
        statusCalls.push( String( url ) );

        return Promise.resolve( {
            ok:   true,
            json: () => Promise.resolve( {
                reference:    REFERENCE,
                status:       serverStatus,
                amount_cents: 1000,
                currency:     'EUR',
            } ),
        } );
    } );
} );

describe( 'a return URL inside a host document', () => {
    test( 'one this browser never submitted for is not a donation', async () => {
        returningFrom( REFERENCE );

        const form = addForm( 'gratora-form-1', config( { embed: embed() } ) );
        await boot();

        expect( form.textContent ).not.toContain( THANKS );
        expect( statusCalls ).toHaveLength( 0 );
    } );

    test( 'one naming no reference is not this donor\'s either', async () => {
        returningFrom( null );
        stashed( REFERENCE );

        const form = addForm( 'gratora-form-1', config( { embed: embed() } ) );
        await boot();

        expect( form.textContent ).not.toContain( THANKS );
        expect( statusCalls ).toHaveLength( 0 );
    } );

    test( 'one naming a different donation is not this donor\'s either', async () => {
        returningFrom( 'GRATORA-2026-09999' );
        stashed( REFERENCE );

        const form = addForm( 'gratora-form-1', config( { embed: embed() } ) );
        await boot();

        expect( form.textContent ).not.toContain( THANKS );
        expect( statusCalls ).toHaveLength( 0 );
    } );

    test( 'a payment the server has not seen is not turned into a thank-you', async () => {
        returningFrom( REFERENCE );
        stashed( REFERENCE );
        serverStatus = 'pending';

        const form = addForm( 'gratora-form-1', config( { embed: embed() } ) );
        await boot();

        expect( form.textContent ).not.toContain( THANKS );
        expect( form.textContent ).toContain( UNRESOLVED );
        // The markers survive, so a donor whose webhook is late can check again.
        expect( window.location.search ).toContain( 'payment_intent_client_secret' );
    } );

    test( 'the donation the server confirms is thanked, and the token never rode the URL back', async () => {
        returningFrom( REFERENCE );
        stashed( REFERENCE );

        const form = addForm( 'gratora-form-1', config( { embed: embed() } ) );
        await boot();

        expect( form.textContent ).toContain( THANKS );
        expect( statusCalls ).toHaveLength( 1 );
        expect( statusCalls[ 0 ] ).toContain( `${ REST }/${ REFERENCE }` );
        expect( statusCalls[ 0 ] ).toContain( 'status_token=tok' );
        expect( window.location.search ).not.toContain( 'payment_intent_client_secret' );
    } );
} );

describe( 'the same return on the charity\'s own page', () => {
    test( 'a donor whose storage was emptied is still shown their outcome', async () => {
        returningFrom( REFERENCE );

        const form = addForm( 'gratora-form-1', config() );
        await boot();

        expect( form.textContent ).toContain( THANKS );
    } );

    test( 'the outcome is Stripe\'s to give, with no second round trip', async () => {
        returningFrom( REFERENCE );
        stashed( REFERENCE );

        const form = addForm( 'gratora-form-1', config() );
        await boot();

        expect( form.textContent ).toContain( THANKS );
        expect( statusCalls ).toHaveLength( 0 );
    } );
} );
