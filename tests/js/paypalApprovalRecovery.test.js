/**
 * What a donor can still do after PayPal accepts their approval and the server
 * round trip that follows it fails.
 *
 * The capture PayPal refuses most often, INSTRUMENT_DECLINED, moves no money:
 * the donor has to be able to approve again on another funding source. The
 * recurring path is the opposite, because PayPal takes the first payment on
 * approval, so a second approval is a second subscription and a second charge:
 * there the way out is back to the form, not another approval. Neither case may
 * end with a screen that reads both "refused" and "processing" at once.
 *
 * No PHP suite can see any of this: it is entirely browser state.
 */

import { render } from 'preact';

import PayPalPayment from '../../assets/donation-form/components/PayPalPayment';

const REFUSAL = 'CAPTURE_REFUSED';
const PROCESSING = 'PROCESSING_LINE';
const CANCEL = 'CANCEL_BUTTON';

let sdkButtons = null;

jest.mock( '../../assets/donation-form/util/paypal', () => ( {
    loadPayPalSdk: () => Promise.resolve( {
        Buttons: ( opts ) => {
            sdkButtons = opts;
            return {
                isEligible: () => true,
                render:     () => Promise.resolve(),
                close:      () => {},
            };
        },
    } ),
} ) );

function config() {
    return {
        rest:   'https://example.test/wp-json/dono/v1/donations',
        nonce:  'n0nce',
        paypal: { clientId: 'CLIENT-1', currency: 'USD' },
        i18n:   {
            error:      'Sorry, something went wrong. Please try again.',
            processing: PROCESSING,
            cancel:     CANCEL,
        },
    };
}

function oneTimePayment() {
    return {
        reference:   'DONO-1',
        statusToken: 'tok',
        amountCents: 5000,
        currency:    'USD',
        paypal:      { order_id: 'ORDER-1' },
    };
}

function subscriptionPayment() {
    return {
        reference:   'DONO-2',
        statusToken: 'tok',
        amountCents: 2500,
        currency:    'USD',
        paypal:      { kind: 'subscription', plan_id: 'P-PLAN-1' },
    };
}

// Preact defers effects past a frame, and the SDK load plus the button render
// are each a promise. Waiting on the condition rather than on a delay, because a
// fixed sleep long enough on an idle machine is not long enough while the other
// files in this suite are running in parallel workers.
const tick = () => new Promise( ( r ) => setTimeout( r, 5 ) );

async function waitFor( predicate, what ) {
    for ( let i = 0; i < 400; i++ ) {
        if ( predicate() ) return;
        await tick();
    }
    throw new Error( `timed out waiting for ${ what }` );
}

const settle = () => tick();

async function mount( payment ) {
    const dispatch = jest.fn();
    document.body.innerHTML = '<div id="root"></div>';
    render(
        <PayPalPayment config={ config() } payment={ payment } dispatch={ dispatch } />,
        document.getElementById( 'root' )
    );
    // The SDK mock assigns sdkButtons synchronously inside Buttons(), so this is
    // the point at which the component is actually driveable.
    await waitFor( () => sdkButtons !== null, 'the PayPal buttons to be built' );
    await waitFor(
        () => !! document.querySelector( '.dono-form__paypal-buttons' ),
        'the button mount to render'
    );

    return dispatch;
}

function screen() {
    const root = document.getElementById( 'root' );

    return {
        text:         root.textContent,
        buttonsShown: ! root.querySelector( '.dono-form__paypal-buttons' ).hidden,
        buttons:      [ ...root.querySelectorAll( 'button' ) ]
            .map( ( b ) => `${ b.textContent.trim() }/disabled=${ b.disabled }` ),
    };
}

function cancelButton() {
    return [ ...document.querySelectorAll( 'button' ) ]
        .find( ( b ) => b.textContent.trim() === CANCEL );
}

function serve( response ) {
    global.fetch = jest.fn( () => Promise.resolve( {
        ok:   response.ok,
        json: () => Promise.resolve( response.body ),
    } ) );
}

beforeEach( () => {
    sdkButtons = null;
    // Each mount gets its own component instance, and the module-level render
    // guard lives on a ref, so nothing leaks between cases.
} );

describe( 'a PayPal approval whose server round trip fails', () => {
    test( 'a refused capture hands the buttons back so another funding source can be tried', async () => {
        serve( { ok: false, body: { message: REFUSAL } } );

        const dispatch = await mount( oneTimePayment() );

        sdkButtons.onClick();
        await sdkButtons.onApprove( {} );
        await settle();

        const after = screen();

        expect( after.text ).toContain( REFUSAL );
        expect( after.buttonsShown ).toBe( true );
        expect( after.buttons ).toEqual( [ `${ CANCEL }/disabled=false` ] );
        // A refusal and a claim of progress cannot both be true.
        expect( after.text ).not.toContain( PROCESSING );

        cancelButton().click();
        expect( dispatch ).toHaveBeenCalledWith( { type: 'CANCEL_PAYMENT' } );
    } );

    test( 'a subscription the server would not record leaves a way out without offering a second charge', async () => {
        serve( { ok: false, body: { message: 'PLAN_CONFLICT' } } );

        const dispatch = await mount( subscriptionPayment() );

        sdkButtons.onClick();
        await sdkButtons.onApprove( { subscriptionID: 'I-SUB-1' } );
        await settle();

        const after = screen();

        expect( after.text ).toContain( 'PLAN_CONFLICT' );
        // PayPal already took the first payment, so approving again would mint
        // a second subscription.
        expect( after.buttonsShown ).toBe( false );
        expect( after.text ).not.toContain( PROCESSING );
        expect( after.buttons ).toEqual( [ `${ CANCEL }/disabled=false` ] );

        cancelButton().click();
        expect( dispatch ).toHaveBeenCalledWith( { type: 'CANCEL_PAYMENT' } );
    } );

    test( 'an approval still in flight says so and offers nothing to press', async () => {
        let release;
        global.fetch = jest.fn( () => new Promise( ( r ) => {
            release = () => r( { ok: true, json: () => Promise.resolve( { status: 'paid' } ) } );
        } ) );

        const dispatch = await mount( oneTimePayment() );

        sdkButtons.onClick();
        const approving = sdkButtons.onApprove( {} );
        await waitFor( () => ! screen().buttonsShown, 'the approval to take the buttons down' );

        const during = screen();
        expect( during.text ).toContain( PROCESSING );
        expect( during.buttonsShown ).toBe( false );
        expect( during.buttons ).toEqual( [] );

        release();
        await approving;
        await settle();

        expect( dispatch ).toHaveBeenCalledWith( {
            type: 'SUBMIT_SUCCESS',
            data: { status: 'paid' },
        } );
    } );

    test( 'the approval that follows a refusal shows progress and offers nothing to press', async () => {
        serve( { ok: false, body: { message: REFUSAL } } );

        const dispatch = await mount( oneTimePayment() );

        sdkButtons.onClick();
        await sdkButtons.onApprove( {} );
        await waitFor(
            () => screen().text.includes( REFUSAL ) && screen().buttonsShown,
            'the refusal to reach the screen and the buttons to come back'
        );

        let release;
        global.fetch = jest.fn( () => new Promise( ( r ) => {
            release = () => r( { ok: true, json: () => Promise.resolve( { status: 'paid' } ) } );
        } ) );

        sdkButtons.onClick();
        const retrying = sdkButtons.onApprove( {} );
        // The second approval hides the buttons synchronously, so this waits on
        // the render rather than on a delay long enough for a loaded machine.
        await waitFor( () => ! screen().buttonsShown, 'the retry to take the buttons down' );

        const during = screen();
        // A capture in flight is progress, and the refusal it replaced is not
        // still true.
        expect( during.text ).toContain( PROCESSING );
        expect( during.text ).not.toContain( REFUSAL );
        // Cancel during a live capture strands the donor on an idle form while
        // the server takes the money.
        expect( during.buttons ).toEqual( [] );

        release();
        await retrying;
        await settle();

        expect( dispatch ).toHaveBeenCalledWith( {
            type: 'SUBMIT_SUCCESS',
            data: { status: 'paid' },
        } );
    } );

    test( 'a subscription approved after an SDK failure shows progress and offers nothing to press', async () => {
        await mount( subscriptionPayment() );

        // The SDK reports its own failures with the buttons still up, so the
        // donor's next press is an approval taken on top of that message.
        sdkButtons.onError();
        await waitFor(
            () => screen().text.includes( config().i18n.error ) && screen().buttonsShown,
            'the SDK failure to reach the screen'
        );

        let release;
        global.fetch = jest.fn( () => new Promise( ( r ) => {
            release = () => r( { ok: true, json: () => Promise.resolve( { status: 'paid' } ) } );
        } ) );

        sdkButtons.onClick();
        const approving = sdkButtons.onApprove( { subscriptionID: 'I-SUB-2' } );
        await waitFor( () => ! screen().buttonsShown, 'the approval to take the buttons down' );

        const during = screen();
        expect( during.text ).toContain( PROCESSING );
        expect( during.text ).not.toContain( config().i18n.error );
        expect( during.buttons ).toEqual( [] );

        release();
        await approving;
        await settle();
    } );
} );
