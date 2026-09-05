/**
 * Two donation forms on one page.
 *
 * A failed submit scrolls the donor to the field that stopped them, and the
 * lookup was `document.querySelector( '.fundkit-donation-form [aria-invalid] )`:
 * page-global, so the second form's failed submit moved the reader into the
 * first form, or nowhere at all if the first form had no error.
 */

const { settle } = require( './support/waitFor' );

function config( id, overrides = {} ) {
    return {
        slug:     `appeal-${ id }`,
        form_id:  id,
        currency: 'USD',
        gateway:  'offline',
        layout:   'inline',
        rest:     'https://example.test/wp-json/fundkit/v1/donations',
        gateways: { options: [ { id: 'offline', label: 'Offline' } ] },
        steps: [
            { id: 'amount', type: 'amount', presets: [ 2500 ] },
            { id: 'donor', type: 'donor', items: [ { t: 'field', kind: 'email', required: true } ] },
            { id: 'submit', type: 'submit' },
        ],
        i18n: { donateNow: 'Donate now', processing: 'Processing', validation: { required: 'Required.', invalidEmail: 'Enter a valid email.' } },
        ...overrides,
    };
}

function addForm( cfg, domId ) {
    const form = document.createElement( 'form' );
    form.className = 'fundkit-donation-form';
    form.id = domId;

    const json = document.createElement( 'script' );
    json.type = 'application/json';
    json.setAttribute( 'data-fundkit-form-config', '' );
    json.textContent = JSON.stringify( cfg );
    form.appendChild( json );

    document.body.appendChild( form );

    return form;
}

async function bootTwo() {
    addForm( config( 7 ), 'fundkit-form-7' );
    addForm( config( 9 ), 'fundkit-form-9' );
    jest.isolateModules( () => {
        require( '../../assets/donation-form/runtime.jsx' );
    } );
    await settle();
}

const submitOf = ( form ) => form.querySelector( '.fundkit-form__button--primary' );

/** focusFirstInvalid runs inside requestAnimationFrame, after the error render commits. */
async function frame() {
    await settle();
    await new Promise( ( r ) => requestAnimationFrame( () => r() ) );
    await settle();
}

beforeEach( () => {
    document.body.innerHTML = '';
    // jsdom has no layout, so the scroll the focus helper does after focusing
    // it is not implemented there.
    window.Element.prototype.scrollIntoView = () => {};
    window.fundkit = {
        default_currency: 'USD',
        number_format: { decimalPlaces: 2, decimalSep: '.', thousandSep: ',', symbolPosition: 'before', symbol: '$' },
    };
    global.fetch = jest.fn( () => Promise.resolve( {
        ok: true, status: 200, headers: { get: () => 'application/json' }, json: () => Promise.resolve( {} ),
    } ) );
} );

test( 'a failed submit moves focus inside the form that was submitted', async () => {
    await bootTwo();

    const first  = document.getElementById( 'fundkit-form-7' );
    const second = document.getElementById( 'fundkit-form-9' );

    // The first form is left holding an invalid field, which is what the
    // page-global lookup used to find whichever form the donor was using.
    submitOf( first ).click();
    await frame();
    expect( first.querySelectorAll( '[aria-invalid="true"]' ).length ).toBe( 1 );

    submitOf( second ).click();
    await frame();

    expect( second.contains( document.activeElement ) ).toBe( true );
} );

test( 'it does not reach into the other form on the page', async () => {
    await bootTwo();

    const first  = document.getElementById( 'fundkit-form-7' );
    const second = document.getElementById( 'fundkit-form-9' );

    submitOf( first ).click();
    await frame();
    submitOf( second ).click();
    await frame();

    expect( first.contains( document.activeElement ) ).toBe( false );
} );

test( 'a single form on the page still focuses its own invalid field', async () => {
    addForm( config( 7 ), 'fundkit-form-7' );
    jest.isolateModules( () => {
        require( '../../assets/donation-form/runtime.jsx' );
    } );
    await settle();

    const form = document.getElementById( 'fundkit-form-7' );
    submitOf( form ).click();
    await frame();

    expect( form.contains( document.activeElement ) ).toBe( true );
    expect( document.activeElement.getAttribute( 'aria-invalid' ) ).toBe( 'true' );
} );
