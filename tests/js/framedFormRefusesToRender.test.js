/**
 * The public donation form carries no frame protection: any site can iframe it,
 * crop it, overlay it, and preset the amount behind the crop. That is
 * UI-redress on a payment screen, and the address bar the donor could check
 * belongs to whoever framed it.
 *
 * Same-origin frames have to keep working: the block editor canvas, the styling
 * preview and the theme customiser all render the form inside one.
 */

import { render } from 'preact';

const CONFIG = {
    slug:     'probe',
    form_id:  7,
    currency: 'EUR',
    gateway:  'offline',
    layout:   'inline',
    steps: [ {
        id:    'amount',
        type:  'amount',
        items: [ { kind: 'amount', presets: [ 1000 ] } ],
    } ],
    i18n: {
        framedTitle:  'This donation form is being shown inside another website.',
        framedAction: 'Open the donation page',
    },
};

function addForm() {
    const form = document.createElement( 'form' );
    form.className = 'fundkit-donation-form';
    form.id = 'fundkit-form-1';

    const json = document.createElement( 'script' );
    json.type = 'application/json';
    json.setAttribute( 'data-fundkit-form-config', '' );
    json.textContent = JSON.stringify( CONFIG );
    form.appendChild( json );

    document.body.appendChild( form );

    return form;
}

/**
 * `top` is read-only on a real window, so the whole accessor is replaced.
 * `null` stands for a cross-origin parent: reading its origin throws, which is
 * exactly what the guard tests for.
 */
function frameAs( kind ) {
    let value;
    if ( kind === 'top-level' ) {
        value = window;
    } else if ( kind === 'same-origin' ) {
        value = { location: { origin: window.location.origin } };
    } else {
        value = { get location() { throw new DOMException( 'blocked', 'SecurityError' ); } };
    }

    Object.defineProperty( window, 'top', { configurable: true, get: () => value } );
}

const tick = () => new Promise( ( resolve ) => setTimeout( resolve, 30 ) );

async function boot() {
    jest.isolateModules( () => {
        require( '../../assets/donation-form/runtime.jsx' );
    } );
    document.dispatchEvent( new window.Event( 'DOMContentLoaded' ) );
    await tick();
}

beforeEach( () => {
    document.body.innerHTML = '';
} );

afterEach( () => {
    delete window.top;
} );

it( 'renders normally when the page is not framed', async () => {
    frameAs( 'top-level' );
    const form = addForm();
    await boot();

    expect( form.dataset.fundkitFramed ).toBeUndefined();
    expect( form.textContent ).not.toContain( 'inside another website' );
} );

it( 'renders normally inside a same-origin frame, which is the editor canvas', async () => {
    frameAs( 'same-origin' );
    const form = addForm();
    await boot();

    expect( form.dataset.fundkitFramed ).toBeUndefined();
    expect( form.textContent ).not.toContain( 'inside another website' );
} );

it( 'refuses to render inside another site, and offers the real page', async () => {
    frameAs( 'cross-origin' );
    const form = addForm();
    await boot();

    expect( form.dataset.fundkitFramed ).toBe( 'true' );
    expect( form.textContent ).toContain( 'inside another website' );

    const out = form.querySelector( 'a' );
    expect( out ).not.toBeNull();
    expect( out.getAttribute( 'target' ) ).toBe( '_top' );
    expect( out.getAttribute( 'href' ) ).toBe( window.location.href );

    // Nothing a donor could type into, and nothing to submit.
    expect( form.querySelectorAll( 'input' ) ).toHaveLength( 0 );
    expect( form.querySelector( 'button' ) ).toBeNull();
} );

it( 'still reveals the form, so the cloak never leaves a blank space', async () => {
    frameAs( 'cross-origin' );
    const form = addForm();
    await boot();

    expect( form.dataset.fundkitReady ).toBe( 'true' );
} );
