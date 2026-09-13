/**
 * A permanent delete of a page of donations takes seconds, and the dialog used
 * to close the moment it was confirmed. Nothing then moved until the toast
 * arrived: the screen an admin was left looking at was identical to the one
 * where nothing had been asked for, so the honest reading of it was that the
 * click had missed.
 *
 * The dialog stays for the work and says how far it has got, and cannot be
 * dismissed while rows are still going.
 */

import { render } from 'preact';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

import ConfirmDialog from '../../assets/admin/_shared/components/ConfirmDialog';

const { waitFor, settle } = require( './support/waitFor' );

const buttons  = () => [ ...document.querySelectorAll( '.gratora-dialog__foot button' ) ];
const byLabel  = ( text ) => buttons().find( ( b ) => b.textContent.trim() === text );
const bodyText = () => document.querySelector( '.gratora-dialog' )?.textContent || '';
const isOpen   = () => !! document.querySelector( '.gratora-dialog' );

/** A promise this test decides when to settle, so "in flight" is a real state. */
function deferred() {
    let resolve;
    let reject;
    const promise = new Promise( ( res, rej ) => {
        resolve = res;
        reject  = rej;
    } );

    return { promise, resolve, reject };
}

/**
 * Effects are deferred, and in jsdom nothing paints between the render and the
 * first line of the test. Settling here is what a browser gets for free: click
 * before the mount effect has run and it lands on a dialog that is about to
 * reset the state the click just set.
 */
async function mount( confirm, onClose = jest.fn() ) {
    document.body.innerHTML = '<div id="root"></div>';
    render( <ConfirmDialog confirm={ confirm } onClose={ onClose } />, document.getElementById( 'root' ) );
    await settle();

    return onClose;
}

/** Types the confirmation word so the destructive button is armed. */
async function type( word ) {
    const input  = document.querySelector( '.gratora-dialog input[type="text"]' );
    const setter = Object.getOwnPropertyDescriptor( window.HTMLInputElement.prototype, 'value' ).set;

    setter.call( input, word );
    input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
    await settle();
}

const deleting = ( onConfirm ) => ( {
    title:        'Delete permanently',
    message:      'This removes 440 donations.',
    requireText:  'DELETE',
    destructive:  true,
    confirmLabel: 'Delete permanently',
    busyLabel:    'Deleting…',
    onConfirm,
} );

test( 'the dialog stays up while the rows are going', async () => {
    const work    = deferred();
    const onClose = await mount( deleting( () => work.promise ) );

    await type( 'DELETE' );
    byLabel( 'Delete permanently' ).click();
    await settle();

    expect( isOpen() ).toBe( true );
    expect( bodyText() ).toContain( 'Deleting…' );
    expect( onClose ).not.toHaveBeenCalled();

    work.resolve();
    await waitFor( () => onClose.mock.calls.length > 0, { what: 'the dialog to close when the work is done' } );
} );

test( 'and counts the rows off as they go', async () => {
    const work = deferred();
    let report;

    await mount( deleting( ( r ) => {
        report = r;
        return work.promise;
    } ) );

    await type( 'DELETE' );
    byLabel( 'Delete permanently' ).click();
    await settle();

    report( 50, 440 );
    await settle();

    expect( bodyText() ).toContain( '50 of 440' );

    work.resolve();
    await settle();
} );

test( 'the dialog holds nothing but the spinner while rows are going', async () => {
    const work    = deferred();
    const onClose = await mount( deleting( () => work.promise ) );

    await type( 'DELETE' );
    byLabel( 'Delete permanently' ).click();
    await settle();

    // Not disabled but gone: a Cancel that cancels nothing is worse than no
    // Cancel, and the requests are already away.
    expect( buttons() ).toHaveLength( 0 );
    expect( bodyText() ).not.toContain( 'This removes 440 donations.' );

    document.querySelector( '.gratora-dialog__close' ).click();
    await settle();

    expect( onClose ).not.toHaveBeenCalled();
    expect( isOpen() ).toBe( true );

    work.resolve();
    await settle();
} );

/**
 * A refusal from the server is the case an admin most needs the screen back
 * for, so a throw has to close the dialog rather than leave it spinning.
 */
test( 'a failure still gives the screen back', async () => {
    const work    = deferred();
    const onClose = await mount( deleting( () => work.promise ) );

    await type( 'DELETE' );
    byLabel( 'Delete permanently' ).click();
    await settle();

    work.reject( new Error( 'Type DELETE to confirm.' ) );

    await waitFor( () => onClose.mock.calls.length > 0, { what: 'the dialog to close after a failure' } );

    expect( onClose ).toHaveBeenCalled();
} );

/** The gate is still the gate: a destructive confirm arrives disarmed. */
test( 'a confirm that wants the word typed starts disarmed', async () => {
    await mount( deleting( () => Promise.resolve() ) );

    expect( byLabel( 'Delete permanently' ).disabled ).toBe( true );
    expect( bodyText() ).toContain( 'Type DELETE to confirm' );
} );

test( 'a confirm with nothing to run closes as it always did', async () => {
    const onClose = await mount( {
        title:        'Delete permanently',
        message:      'Nothing is wired to this.',
        confirmLabel: 'Delete permanently',
    } );

    byLabel( 'Delete permanently' ).click();
    await settle();

    expect( onClose ).toHaveBeenCalled();
} );
