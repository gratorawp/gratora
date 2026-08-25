/**
 * A required consent purpose on the donation form. The consent row the server
 * writes is evidence of something the donor did, so the control has to be one
 * they can actually operate, and pressing Donate without operating it has to
 * stop rather than post a granted consent nobody granted.
 */

const CONSENT_ID = 'campaign_news';

function config( checked ) {
    return {
        slug:     'consent',
        form_id:  7,
        currency: 'USD',
        gateway:  'offline',
        layout:   'inline',
        rest:     'https://example.test/wp-json/dono/v1/donations',
        gateways: {
            options: [ {
                id:          'offline',
                label:       'Bank transfer',
                currencies:  [ '*' ],
                countries:   [ '*' ],
                frequencies: [ 'one_time' ],
            } ],
            default: 'offline',
        },
        steps: [
            { id: 'amount', type: 'amount', presets: [ 2500 ] },
            {
                id:    'donor',
                type:  'donor',
                items: [ {
                    t:        'field',
                    kind:     'consent',
                    label:    'How can we stay in touch?',
                    purposes: [ {
                        id:       CONSENT_ID,
                        label:    'Email me about this campaign',
                        required: true,
                        checked,
                    } ],
                } ],
            },
            { id: 'submit', type: 'submit' },
        ],
        i18n: {
            error:     'Sorry, something went wrong. Please try again.',
            donateNow: 'Donate now',
            required:  'Required',
        },
    };
}

function addForm( cfg ) {
    const form = document.createElement( 'form' );
    form.className = 'dono-donation-form';
    form.id = 'dono-form-1';

    const json = document.createElement( 'script' );
    json.type = 'application/json';
    json.setAttribute( 'data-dono-form-config', '' );
    json.textContent = JSON.stringify( cfg );
    form.appendChild( json );

    document.body.appendChild( form );

    return form;
}

async function boot() {
    jest.isolateModules( () => {
        require( '../../assets/donation-form/runtime.jsx' );
    } );

    await settle();
}

async function settle() {
    await new Promise( ( r ) => setTimeout( r, 20 ) );
    await new Promise( ( r ) => setTimeout( r, 20 ) );
}

async function donate( form ) {
    form.querySelector( '.dono-form__button--primary' ).click();
    await settle();
}

function consentBox( form ) {
    return form.querySelector( '.dono-form__consent-purpose input[type="checkbox"]' );
}

let sent = [];

beforeEach( () => {
    // jsdom has no layout, so the runtime's scroll-to-first-error throws.
    Element.prototype.scrollIntoView = jest.fn();

    document.body.innerHTML = '';
    window.sessionStorage.clear();
    sent = [];

    global.fetch = jest.fn( ( url, init ) => {
        sent.push( JSON.parse( init.body ) );

        return Promise.resolve( {
            ok:   true,
            json: () => Promise.resolve( {
                reference:    'DONO-2026-00001',
                status_token: 'tok',
                status:       'pending',
                gateway:      'offline',
                amount_cents: 2500,
                currency:     'USD',
            } ),
        } );
    } );
} );

describe( 'a required consent purpose', () => {
    test( 'it starts unanswered', async () => {
        const form = addForm( config( false ) );
        await boot();

        const box = consentBox( form );
        expect( box ).not.toBeNull();
        expect( box.checked ).toBe( false );
    } );

    test( 'the donor can operate the control', async () => {
        const form = addForm( config( false ) );
        await boot();

        expect( consentBox( form ).disabled ).toBe( false );
    } );

    test( 'pressing Donate without ticking it posts nothing and says why', async () => {
        const form = addForm( config( false ) );
        await boot();
        await donate( form );

        expect( sent ).toHaveLength( 0 );

        const error = form.querySelector( '.dono-form__consent-purpose .dono-form__field-error' );
        expect( error ).not.toBeNull();
        expect( consentBox( form ).getAttribute( 'aria-invalid' ) ).toBe( 'true' );
    } );

    test( 'ticking it is what sends the consent', async () => {
        const form = addForm( config( false ) );
        await boot();

        const box = consentBox( form );
        box.checked = true;
        box.dispatchEvent( new Event( 'change', { bubbles: true } ) );
        await settle();

        await donate( form );

        expect( sent ).toHaveLength( 1 );
        expect( sent[ 0 ].consents[ CONSENT_ID ] ).toBe( true );
    } );
} );
