/**
 * The donate-button block keeps its form inside a modal that only a click on
 * the button opens. A donor who paid by bank redirect never presses that
 * button: their browser comes back on its own, the form inside resolves the
 * payment, and the thank-you renders where nobody can see it.
 *
 * The reveal has to agree with the runtime about which form claimed the
 * return, or the outcome lands in one form while the modal around another
 * stays shut. There is one rule for that now and the runtime owns it, so what
 * these cases check is that the reveal follows it in both directions: the modal
 * opens whenever the claimant is inside it, and never when no form claimed.
 * Both halves run here, real, for that reason.
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

function formConfig( overrides = {} ) {
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
        ...overrides,
    };
}

// `hydrate` stages the config the runtime needs; leaving it off is a page whose
// runtime never boots.
function formMarkup( id, hydrate, overrides = {} ) {
    const json = hydrate
        ? `<script type="application/json" data-dono-form-config>${ JSON.stringify( formConfig( overrides ) ) }</script>`
        : '';

    return `<form class="dono-donation-form"${ id ? ` id="${ id }"` : '' }>${ json }</form>`;
}

function page( {
    modalFormId = 'dono-form-1',
    extraFormId = null,
    hydrate = false,
    modalOverrides = {},
    extraFirst = false,
} = {} ) {
    const block = `
        <div class="dono-block dono-block--donate-button">
            <button type="button" class="dono-donate-button" data-form-slug="probe"></button>
            <div class="dono-donate-modal" data-form-slug="probe" hidden>
                <div class="dono-donate-modal__panel">
                    <button type="button" class="dono-donate-modal__close" data-dono-modal-close></button>
                    <div class="dono-donate-modal__body">
                        ${ formMarkup( modalFormId, hydrate, modalOverrides ) }
                    </div>
                </div>
            </div>
        </div>
    `;
    const extra = extraFormId ? formMarkup( extraFormId, hydrate ) : '';

    document.body.innerHTML = extraFirst ? extra + block : block + extra;

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

const text = ( id ) => document.getElementById( id ).textContent;

// A page loads each of these scripts once. Here every case requires its own
// copy, and each copy registers listeners on the same window that outlive it,
// so a modal script from an earlier case can answer the announcement made in
// this one. That hid a real gap: a case meant to prove the mark on the page is
// read still passed with the reading deleted, because a stale listener opened
// the modal. Recording is scoped to the test body, or this would strip
// listeners the environment itself installed.
const listeners = [];
let recording = false;

[ window, document ].forEach( ( target ) => {
    const add = target.addEventListener.bind( target );
    target.addEventListener = ( type, fn, opts ) => {
        if ( recording ) listeners.push( [ target, type, fn, opts ] );
        add( type, fn, opts );
    };
} );

beforeEach( () => {
    document.body.innerHTML = '';
    window.sessionStorage.clear();
    window.history.replaceState( {}, '', '/campaign/' );
    recording = true;
} );

afterEach( () => {
    recording = false;
    listeners.splice( 0 ).forEach( ( [ target, type, fn, opts ] ) => {
        target.removeEventListener( type, fn, opts );
    } );
} );

test( 'a donor returning from their bank is shown the modal holding the outcome', async () => {
    const modal = page( { hydrate: true } );
    returning( 'dono-form-1' );

    await loadModalScript();
    await bootRuntime();

    expect( text( 'dono-form-1' ) ).toContain( THANKS );
    expect( modal.hidden ).toBe( false );
    expect( modal.classList.contains( 'is-open' ) ).toBe( true );
} );

test( 'an ordinary page load leaves the modal shut', async () => {
    const modal = page( { hydrate: true } );

    await loadModalScript();
    await bootRuntime();

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
    expect( text( 'dono-form-2' ) ).toContain( THANKS );
    expect( text( 'dono-form-1' ) ).not.toContain( THANKS );
    expect( modal.hidden ).toBe( true );
} );

test( 'a browser that refused storage still gets the modal when it holds the only form', async () => {
    const modal = page( { hydrate: true } );
    returning( null );

    await loadModalScript();
    await bootRuntime();

    expect( text( 'dono-form-1' ) ).toContain( THANKS );
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
    expect( text( 'dono-form-1' ) ).toContain( THANKS );
    expect( text( 'dono-form-2' ) ).not.toContain( THANKS );
    expect( modal.hidden ).toBe( false );
} );

test( 'the same page with the inline form first leaves the modal shut', async () => {
    const modal = page( { extraFormId: 'dono-form-2', hydrate: true, extraFirst: true } );
    returning( null );

    await loadModalScript();
    await bootRuntime();

    // Same page, same empty stash, opposite document order: the claim moves and
    // the reveal has to move with it. Nothing here reads the order itself, which
    // is the point of following the claim rather than re-deriving it.
    expect( text( 'dono-form-2' ) ).toContain( THANKS );
    expect( text( 'dono-form-1' ) ).not.toContain( THANKS );
    expect( modal.hidden ).toBe( true );
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

        expect( text( 'dono-form-1' ) ).toContain( THANKS );
        expect( text( 'dono-form-2' ) ).not.toContain( THANKS );
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

        expect( text( 'dono-form-1' ) ).not.toContain( THANKS );
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

    test( 'the form the stash names cannot resolve the payment, so nothing opens', async () => {
        // An org that cleared its keys, or switched test/live mode, while this
        // donor was at their bank. The named form abstains for want of a
        // publishable key and the other is blocked from claiming a return that
        // names a form still on the page, so no outcome renders anywhere. The
        // modal has no key of its own to check, which is why it must not be
        // deciding this.
        const modal = page( {
            extraFormId: 'dono-form-2',
            hydrate: true,
            modalOverrides: { stripe: {} },
        } );
        returning( 'dono-form-1' );

        await loadModalScript();
        await bootRuntime();

        expect( text( 'dono-form-1' ) ).not.toContain( THANKS );
        expect( text( 'dono-form-2' ) ).not.toContain( THANKS );
        expect( modal.hidden ).toBe( true );
    } );

    test( 'a stashed reference that came back as a number still matches the URL', async () => {
        // JSON.parse gives back whatever was stored, and a reference compared
        // with !== against a string param abstains on a value that spells the
        // same thing. One side coercing and the other not is two rules again.
        const modal = page( { hydrate: true } );
        returning( 'dono-form-1', 20260050, '20260050' );

        await loadModalScript();
        await bootRuntime();

        expect( text( 'dono-form-1' ) ).toContain( THANKS );
        expect( modal.hidden ).toBe( false );
    } );

    test( 'a form the shortcode gave no id still opens the modal around it', async () => {
        const modal = page( { modalFormId: null, hydrate: true } );
        returning( null );

        await loadModalScript();
        await bootRuntime();

        expect( document.querySelector( '.dono-donation-form' ).textContent ).toContain( THANKS );
        expect( modal.hidden ).toBe( false );
    } );

    test( 'a runtime that never boots leaves the modal shut', async () => {
        // A bundle that 404s or throws on load. Nothing claims, so nothing is
        // going to say what happened to the money, and a modal opened over a
        // form that never mounted shows the donor an empty form and invites a
        // second donation.
        const modal = page();
        returning( 'dono-form-1' );

        await loadModalScript();

        expect( modal.hidden ).toBe( true );
    } );
} );
