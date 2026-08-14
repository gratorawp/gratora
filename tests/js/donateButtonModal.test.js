/**
 * The donate-button block keeps its form inside a modal that only a click on
 * the button opens. A donor who paid by bank redirect never presses that
 * button: their browser comes back on its own, the form inside resolves the
 * payment, and the thank-you renders where nobody can see it.
 *
 * The reveal has to agree with the runtime about which form claimed the
 * return, or the outcome lands in one form while the modal around another
 * stays shut. Both halves run here, real, for that reason.
 */

import { PENDING_KEY } from '../../assets/donation-form/util/pending';

const THANKS  = 'Thank you for your donation!';
const GENERIC = 'Sorry, something went wrong. Please try again.';

jest.mock( '../../assets/donation-form/util/stripe', () => {
    const actual = jest.requireActual( '../../assets/donation-form/util/stripe' );

    return {
        ...actual,
        // The only part of the return that talks to Stripe. The ownership test,
        // the param strip and the status mapping all stay real.
        resolveStripeReturn: () => Promise.resolve( 'succeeded' ),
        loadStripeJs:        () => Promise.resolve( () => ( {} ) ),
    };
} );

function formConfig() {
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
            error:        GENERIC,
            notCompleted: 'Your payment was not completed.',
            thanks:       THANKS,
            confirming:   'Confirming your payment…',
            donateAgain:  'Donate again',
        },
    };
}

// `hydrate` stages the config the runtime needs; the modal-only tests leave it
// off so nothing mounts.
function formMarkup( id, hydrate ) {
    const json = hydrate
        ? `<script type="application/json" data-dono-form-config>${ JSON.stringify( formConfig() ) }</script>`
        : '';

    return `<form class="dono-donation-form" id="${ id }">${ json }</form>`;
}

function page( { modalFormId = 'dono-form-1', extraFormId = null, hydrate = false } = {} ) {
    document.body.innerHTML = `
        <div class="dono-block dono-block--donate-button">
            <button type="button" class="dono-donate-button" data-form-slug="probe"></button>
            <div class="dono-donate-modal" data-form-slug="probe" hidden>
                <div class="dono-donate-modal__panel">
                    <button type="button" class="dono-donate-modal__close" data-dono-modal-close></button>
                    <div class="dono-donate-modal__body">
                        ${ formMarkup( modalFormId, hydrate ) }
                    </div>
                </div>
            </div>
        </div>
        ${ extraFormId ? formMarkup( extraFormId, hydrate ) : '' }
    `;

    return document.querySelector( '.dono-donate-modal' );
}

// urlReference diverges from reference when the return on the URL is not the
// submission this tab stashed.
function returning( formKey, reference = 'DONO-2026-00050', urlReference = reference ) {
    window.history.replaceState( {}, '', '/campaign/?dono_return=1&dono_ref=' + urlReference
        + '&payment_intent_client_secret=pi_probe_secret' );

    if ( formKey !== null ) {
        window.sessionStorage.setItem( PENDING_KEY, JSON.stringify( {
            reference,
            statusToken: 'tok',
            formKey,
            amountCents: 1000,
            currency:    'EUR',
        } ) );
    }
}

// The script reads the URL as it evaluates, before the form runtime strips the
// markers, so it has to be loaded after the page is staged.
async function loadModalScript() {
    jest.isolateModules( () => {
        require( '../../assets/donate-button/modal.js' );
    } );

    await new Promise( ( r ) => setTimeout( r, 0 ) );
}

// The runtime boots itself on import, so each test needs its own module copy.
async function bootRuntime() {
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
    window.history.replaceState( {}, '', '/campaign/' );
} );

test( 'a donor returning from their bank is shown the modal holding the outcome', async () => {
    const modal = page();
    returning( 'dono-form-1' );

    await loadModalScript();

    expect( modal.hidden ).toBe( false );
    expect( modal.classList.contains( 'is-open' ) ).toBe( true );
} );

test( 'an ordinary page load leaves the modal shut', async () => {
    const modal = page();

    await loadModalScript();

    expect( modal.hidden ).toBe( true );
} );

test( 'the button still opens the modal on a click', async () => {
    const modal = page();

    await loadModalScript();
    document.querySelector( '.dono-donate-button' ).click();

    expect( modal.hidden ).toBe( false );
} );

test( 'a return belonging to an inline form elsewhere on the page does not open the modal', async () => {
    const modal = page( { extraFormId: 'dono-form-2', hydrate: true } );
    returning( 'dono-form-2' );

    await loadModalScript();
    await bootRuntime();

    // Hydrated, because "the modal stayed shut" is only right if the outcome
    // reached the form that claimed it. The two halves disagreeing in this
    // direction is the modal claiming a return the runtime gave to somebody
    // else.
    expect( document.getElementById( 'dono-form-2' ).textContent ).toContain( THANKS );
    expect( document.getElementById( 'dono-form-1' ).textContent ).not.toContain( THANKS );
    expect( modal.hidden ).toBe( true );
} );

test( 'a browser that refused storage still gets the modal when it holds the only form', async () => {
    const modal = page();
    returning( null );

    await loadModalScript();

    expect( modal.hidden ).toBe( false );
} );

test( 'a stash naming no form opens the modal that holds the first form on the page', async () => {
    const modal = page( { extraFormId: 'dono-form-2', hydrate: true } );
    returning( null );

    await loadModalScript();
    await bootRuntime();

    // Private browsing refuses storage, so nothing names the form and two forms
    // leave nothing unambiguous. The runtime does not abstain: it falls through
    // to the first in document order. Both halves are asserted because refusing
    // to reveal here is refusing to reveal an outcome already rendered.
    expect( document.getElementById( 'dono-form-1' ).textContent ).toContain( THANKS );
    expect( document.getElementById( 'dono-form-2' ).textContent ).not.toContain( THANKS );
    expect( modal.hidden ).toBe( false );
} );

describe( 'the reveal agrees with the form that actually claimed the return', () => {
    test( 'a stash naming a form that is not on the page reveals the outcome it rendered', async () => {
        // formKey is wp_unique_id(), a per-request counter, and the return URL
        // carries query params, which is what makes page caches regenerate. The
        // id stashed at submit need not be the id rendered on the return.
        const modal = page( { extraFormId: 'dono-form-2', hydrate: true } );
        returning( 'dono-form-7' );

        await loadModalScript();
        await bootRuntime();

        expect( document.getElementById( 'dono-form-1' ).textContent ).toContain( THANKS );
        expect( document.getElementById( 'dono-form-2' ).textContent ).not.toContain( THANKS );
        expect( modal.hidden ).toBe( false );
    } );

    test( 'a return carrying a reference this tab never stashed is left alone', async () => {
        // A stale return link, or a bank app opening the return in a tab that
        // inherited this one's storage. The runtime abstains on the mismatch,
        // so nothing claims it and no outcome is rendered anywhere: a modal
        // opened here would be an empty form over the page, and the markers are
        // still on the URL for a reload to do it again.
        const modal = page( { hydrate: true } );
        returning( 'dono-form-1', 'DONO-2026-00050', 'DONO-2026-00099' );

        await loadModalScript();
        await bootRuntime();

        expect( document.getElementById( 'dono-form-1' ).textContent ).not.toContain( THANKS );
        expect( modal.hidden ).toBe( true );
    } );

    test( 'a modal script that evaluates after the runtime stripped the URL still reveals', async () => {
        // "Delay JavaScript execution" optimizers hold a script back to the
        // first interaction. By then there are no markers left to read, and the
        // claim on the form is the only thing that says a return happened.
        const modal = page( { hydrate: true } );
        returning( 'dono-form-1' );

        await bootRuntime();
        expect( window.location.search ).not.toContain( 'dono_return' );

        await loadModalScript();

        expect( modal.hidden ).toBe( false );
    } );
} );
