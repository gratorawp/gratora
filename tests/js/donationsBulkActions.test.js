/**
 * Bulk actions on the donations list act on many donations at once, and each
 * one of them emails a donor. A batch where some calls fail has to say how many
 * went through, or an admin reads the silence as nothing happening and presses
 * the button again, sending everyone who already got a receipt a second copy.
 */

import { render } from 'preact';

import apiFetch from '@wordpress/api-fetch';

import notify from '../../assets/admin/_shared/notify';
import List from '../../assets/admin/donations/List';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
// Webpack aliases react to preact/compat for the admin bundles, and this screen
// is only renderable under that alias: @wordpress/element's hooks and
// lucide-react's forwardRef icons both come through react. Scoped to this file
// so the suites that drive real React keep it.
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
// @dono/ui ships built, so its own JSX arrives already compiled against these.
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

jest.mock( '../../assets/admin/_shared/notify', () => ( {
    __esModule: true,
    default: { success: jest.fn(), error: jest.fn(), info: jest.fn() },
} ) );

// The real DataViews wants a browser to lay a table out in; the actions array
// it is handed is the whole subject here, so a stub that captures it is enough.
// The confirm dialog is stubbed for the same reason: the callback under test
// runs behind it.
const captured = { actions: null, confirm: null };

jest.mock( '@wordpress/dataviews', () => ( {
    DataViews: ( props ) => {
        captured.actions = props.actions;
        return null;
    },
} ) );

jest.mock( '../../assets/admin/_shared/components/ConfirmDialog', () => ( {
    __esModule: true,
    default: ( props ) => {
        captured.confirm = props.confirm;
        return null;
    },
} ) );

// Neither is reachable from a bulk action, and both pull @wordpress/components
// in, which does not load under the preact alias.
jest.mock( '../../assets/admin/_shared/components/DateField', () => ( {
    __esModule: true,
    default: () => null,
} ) );

jest.mock( '../../assets/admin/donations/RecordDonationDrawer', () => ( {
    __esModule: true,
    default: () => null,
} ) );

const paid = [
    { id: 1, reference: 'DON-1', status: 'paid', donor: { name: 'A', email: 'a@example.test' } },
    { id: 2, reference: 'DON-2', status: 'paid', donor: { name: 'B', email: 'b@example.test' } },
    { id: 3, reference: 'DON-3', status: 'paid', donor: { name: 'C', email: 'c@example.test' } },
];

const unpaid = [
    { id: 4, reference: 'DON-4', status: 'pending',    donor: { name: 'D' } },
    { id: 5, reference: 'DON-5', status: 'processing', donor: { name: 'E' } },
    { id: 6, reference: 'DON-6', status: 'pending',    donor: { name: 'F' } },
];

const settle = () => new Promise( ( r ) => setTimeout( r, 20 ) );

/**
 * Answers the list's own load requests, and hands the bulk endpoint under test
 * to `onAction` so a single call in the batch can be made to fail.
 */
function seedApi( onAction ) {
    apiFetch.mockImplementation( ( { path, parse } ) => {
        if ( parse === false ) {
            return Promise.resolve( {
                json:    async () => paid,
                headers: { get: () => '0' },
            } );
        }
        if ( path.startsWith( '/dono/v1/admin/donations/campaign-options' )
            || path.startsWith( '/dono/v1/admin/donations/gateway-options' ) ) {
            return Promise.resolve( [] );
        }
        if ( path.startsWith( '/dono/v1/admin/donations/stats' ) ) {
            return Promise.resolve( null );
        }
        return onAction( path );
    } );
}

async function mountList() {
    document.body.innerHTML = '<div id="root"></div>';
    render( <List />, document.getElementById( 'root' ) );
    await settle();
    expect( captured.actions ).toBeTruthy();
}

/** Runs a bulk action over `items` and resolves once its confirmed work is done. */
async function runBulk( id, items ) {
    const action = captured.actions.find( ( a ) => a.id === id );
    expect( action ).toBeTruthy();
    action.callback( items );
    await settle();
    await captured.confirm.onConfirm();
    await settle();
}

/** The error Notice the screen renders when a batch reports nothing else. */
function noticeText() {
    return document.getElementById( 'root' ).textContent;
}

beforeEach( () => {
    captured.actions = null;
    captured.confirm = null;
    notify.success.mockClear();
    notify.error.mockClear();
    apiFetch.mockReset();
} );

test( 'a resend batch with one failure still reports the receipts that were sent', async () => {
    seedApi( ( path ) => (
        path.includes( 'DON-2' )
            ? Promise.reject( new Error( 'SMTP refused the message.' ) )
            : Promise.resolve( { ok: true } )
    ) );

    await mountList();
    await runBulk( 'resend-receipt', paid );

    expect( notify.success ).toHaveBeenCalledWith( '2 receipts resent.' );
    expect( notify.error ).toHaveBeenCalledWith( '1 receipt could not be resent.' );
    expect( noticeText() ).not.toContain( 'Could not resend' );
} );

test( 'a resend batch where every call fails reports only the failures', async () => {
    seedApi( () => Promise.reject( new Error( 'SMTP refused the message.' ) ) );

    await mountList();
    await runBulk( 'resend-receipt', paid );

    expect( notify.success ).not.toHaveBeenCalled();
    expect( notify.error ).toHaveBeenCalledWith( '3 receipts could not be resent.' );
} );

test( 'a mark-paid batch with one failure still reports the donations that were paid', async () => {
    seedApi( ( path ) => (
        path.includes( 'DON-5' )
            ? Promise.reject( new Error( 'The gateway rejected it.' ) )
            : Promise.resolve( { ok: true } )
    ) );

    await mountList();
    await runBulk( 'mark-paid', unpaid );

    expect( notify.success ).toHaveBeenCalledWith( '2 donations marked paid.' );
    expect( notify.error ).toHaveBeenCalledWith( '1 donation could not be marked paid.' );
} );

test( 'a batch that all succeeds says so and raises no failure', async () => {
    seedApi( () => Promise.resolve( { ok: true } ) );

    await mountList();
    await runBulk( 'resend-receipt', paid );

    expect( notify.success ).toHaveBeenCalledWith( '3 receipts resent.' );
    expect( notify.error ).not.toHaveBeenCalled();
} );

test( 'a selection with nothing eligible in it sends no requests', async () => {
    const calls = [];
    seedApi( ( path ) => { calls.push( path ); return Promise.resolve( { ok: true } ); } );

    await mountList();
    const action = captured.actions.find( ( a ) => a.id === 'resend-receipt' );
    action.callback( unpaid );
    await settle();

    expect( captured.confirm ).toBeNull();
    expect( calls ).toEqual( [] );
    expect( notify.success ).not.toHaveBeenCalled();
    expect( notify.error ).not.toHaveBeenCalled();
} );
