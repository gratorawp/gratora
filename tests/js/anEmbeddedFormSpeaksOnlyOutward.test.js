/**
 * A donation form inside a frame on somebody else's page tells that page one
 * thing: how tall it is. Anything it accepted back would be the framing site
 * steering a payment screen, and a wildcard target would hand the message to
 * whichever site happened to be showing the frame.
 *
 * The handshake is also what sizes the frame, so it cannot wait on the form
 * hydrating: a runtime error would otherwise leave the donor a sliver of a
 * payment screen with no way to scroll it.
 */

const { settle } = require( './support/waitFor' );

const HOST = 'https://partner.test';

const embed = ( extra = {} ) => ( {
    origin:   HOST,
    key:      'ek_probe',
    tokenUrl: 'https://example.test/wp-json/gratora-embed/v1/token',
    ...extra,
} );

function config( overrides = {} ) {
    return {
        slug:     'probe',
        form_id:  7,
        currency: 'EUR',
        gateway:  'offline',
        layout:   'inline',
        rest:     'https://example.test/wp-json/gratora/v1/donations',
        steps: [
            { id: 'amount', type: 'amount', presets: [ 1000 ] },
            { id: 'submit', type: 'submit' },
        ],
        i18n: {
            error:     'Sorry, something went wrong. Please try again.',
            donateNow: 'Donate now',
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

// The runtime boots itself on import, so each page load needs its own copy.
async function boot() {
    jest.isolateModules( () => {
        require( '../../assets/donation-form/runtime.jsx' );
    } );

    await settle();
}

const realPost   = window.postMessage;
const realListen = window.addEventListener;

let posted = [];
let heard  = [];

beforeEach( () => {
    document.body.innerHTML = '';
    window.sessionStorage.clear();
    posted = [];
    heard  = [];

    // window.parent is this same window under jsdom, so one stand-in records
    // everything the runtime posts outward.
    window.postMessage = jest.fn( ( message, targetOrigin ) => {
        posted.push( { message, targetOrigin } );
    } );

    window.addEventListener = jest.fn( ( ...args ) => {
        heard.push( args[ 0 ] );

        return realListen.apply( window, args );
    } );
} );

afterEach( () => {
    window.postMessage      = realPost;
    window.addEventListener = realListen;
} );

describe( 'a form rendered into a host document', () => {
    test( 'it posts its height to the one origin that document names', async () => {
        addForm( 'gratora-form-1', config( { embed: embed() } ) );
        await boot();

        expect( posted ).toHaveLength( 1 );
        expect( posted[ 0 ].targetOrigin ).toBe( HOST );
        expect( posted[ 0 ].targetOrigin ).not.toBe( '*' );
        expect( posted[ 0 ].message ).toMatchObject( {
            source: 'gratora',
            v:      1,
            type:   'height',
            key:    'ek_probe',
        } );
        expect( typeof posted[ 0 ].message.height ).toBe( 'number' );
    } );

    test( 'nothing on the page listens to the site doing the framing', async () => {
        addForm( 'gratora-form-1', config( { embed: embed() } ) );
        await boot();

        expect( heard.filter( ( type ) => type === 'message' ) ).toHaveLength( 0 );
    } );

    test( 'a form that never hydrates still completes the handshake', async () => {
        // No steps: the runtime leaves the server-rendered markup alone and
        // mounts nothing, which is also what a thrown error looks like from the
        // host's side.
        const form = addForm( 'gratora-form-1', config( { embed: embed(), steps: [] } ) );
        await boot();

        expect( form.dataset.gratoraMounted ).toBeUndefined();
        expect( posted ).toHaveLength( 1 );
        expect( posted[ 0 ].message.type ).toBe( 'height' );
    } );

    test( 'an embed with nowhere to post to is not one', async () => {
        addForm( 'gratora-form-1', config( { embed: { key: 'ek_probe' } } ) );
        await boot();

        expect( posted ).toHaveLength( 0 );
    } );
} );

describe( 'a form on the charity\'s own page', () => {
    test( 'it says nothing to anybody', async () => {
        addForm( 'gratora-form-1', config() );
        await boot();

        expect( posted ).toHaveLength( 0 );
        expect( heard.filter( ( type ) => type === 'message' ) ).toHaveLength( 0 );
    } );

    test( 'it still renders, rather than refusing the way a framed form does', async () => {
        const form = addForm( 'gratora-form-1', config() );
        await boot();

        expect( form.dataset.gratoraFramed ).toBeUndefined();
        expect( form.dataset.gratoraMounted ).toBe( 'true' );
    } );
} );
