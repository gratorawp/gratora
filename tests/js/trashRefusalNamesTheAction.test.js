/**
 * A refusal has to name the action it refused, and a refused request has to say
 * something at all.
 *
 * Restore and Delete permanently report through one shared helper, so a message
 * that names neither verb hands an admin a count with nothing attached to it:
 * the same sentence could mean rows that would not come back or rows that would
 * not go away. The failure path carries the same weight as the wording. The
 * confirm dialog has already closed by the time the request is sent, so a call
 * refused outright and swallowed leaves an irreversible action answered by a
 * screen that simply does not change.
 */

import { render } from 'preact';

import apiFetch from '@wordpress/api-fetch';

import notify from '../../assets/admin/_shared/notify';
import Trash from '../../assets/admin/donations/Trash';

const { settle, waitFor } = require( './support/waitFor' );

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
// Webpack aliases react to preact/compat for the admin bundles, and this screen
// only renders under that alias: @wordpress/element's hooks and lucide-react's
// forwardRef icons both arrive through react.
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

jest.mock( '../../assets/admin/_shared/notify', () => ( {
    __esModule: true,
    default: { success: jest.fn(), error: jest.fn(), info: jest.fn() },
} ) );

// The real DataViews wants a browser to lay a table out in, and the actions it
// is handed are the whole subject here. The confirm dialog is stubbed for the
// same reason: the callback under test runs behind it.
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

const rows = [ { id: 1, reference: 'DON-1', status: 'failed', donor: { name: 'A' } } ];

/**
 * Answers the screen's own loads; `onBatch` answers the trash routes.
 *
 * The row count matters: an empty bin renders the empty state instead of the
 * table, and the actions under test would never be handed over.
 */
function seedApi( onBatch ) {
    apiFetch.mockImplementation( ( { path, parse } ) => {
        if ( parse === false ) {
            return Promise.resolve( {
                json:    async () => rows,
                headers: { get: () => '1' },
            } );
        }
        if ( path.startsWith( '/gratora/v1/admin/me/' ) ) {
            return Promise.resolve( {} );
        }
        return onBatch( path );
    } );
}

async function mount() {
    document.body.innerHTML = '<div id="root"></div>';
    render( <Trash />, document.getElementById( 'root' ) );
    await waitFor( () => !! captured.actions, { what: 'the trash to register its actions' } );
}

const actionById = ( id ) => captured.actions.find( ( a ) => a.id === id );

const refusing = ( reason ) => () => Promise.resolve( {
    done:    [],
    already: [],
    refused: [ { reference: 'DON-1', reason } ],
} );

beforeEach( () => {
    captured.actions = null;
    captured.confirm = null;
    notify.success.mockClear();
    notify.error.mockClear();
    apiFetch.mockReset();
} );

test( 'a refused restore says the rows could not be restored', async () => {
    seedApi( refusing( 'It settled after it was trashed.' ) );

    await mount();
    await actionById( 'restore' ).callback( rows );
    await settle();

    expect( notify.error ).toHaveBeenCalledWith( '1 donation could not be restored.' );
} );

test( 'a refused delete says the rows could not be deleted', async () => {
    seedApi( refusing( 'It is still attached to a plan.' ) );

    await mount();
    actionById( 'delete-permanently' ).callback( rows );
    await settle();
    await captured.confirm.onConfirm();
    await settle();

    expect( notify.error ).toHaveBeenCalledWith( '1 donation could not be deleted.' );
} );

test( 'a restore the server refuses outright is not answered with silence', async () => {
    seedApi( () => Promise.reject( new Error( 'The trash route is not available.' ) ) );

    await mount();
    await actionById( 'restore' ).callback( rows );
    await settle();

    expect( notify.error ).toHaveBeenCalledWith( 'The trash route is not available.' );
} );

test( 'a delete the server refuses outright is not answered with silence', async () => {
    // The server refuses a delete whose typed confirmation does not match, and
    // that refusal is the one an admin most needs to read.
    seedApi( () => Promise.reject( new Error( 'Type DELETE to confirm.' ) ) );

    await mount();
    actionById( 'delete-permanently' ).callback( rows );
    await settle();
    await captured.confirm.onConfirm();
    await settle();

    expect( notify.error ).toHaveBeenCalledWith( 'Type DELETE to confirm.' );
} );
