/**
 * The server tells each donation row why it cannot be trashed or deleted, and
 * two of the three call sites that read those fields threw the reason away by
 * hiding the control.
 *
 * The trash one matters most: its refusal says a subscription is still billing
 * and the card keeps being charged. On a row carrying it, the menu offered
 * Resend receipt and Delete permanently, the second of which reads its own
 * refusal out, and no Move to trash at all.
 *
 * The bin's own Delete permanently did the same with the receipt rule, on the
 * one screen that exists to empty it.
 */

import { render } from 'preact';
import apiFetch from '@wordpress/api-fetch';

const { waitFor, settle } = require( './support/waitFor' );

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

jest.mock( '../../assets/admin/_shared/notify', () => ( {
    __esModule: true,
    default: { success: jest.fn(), error: jest.fn(), info: jest.fn() },
} ) );

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

import List from '../../assets/admin/donations/List';
import Trash from '../../assets/admin/donations/Trash';

const STILL_BILLING =
    'This payment belongs to subscription #38, which is still billing. Cancel it first, or the card keeps being charged for something with no record here.';
const RECEIPT_STANDS =
    'A receipt was issued for this donation. Refund it first, which voids the receipt, or the numbering has a hole in it.';

const HELD = {
    id: 783, reference: 'DON-2026-00783', status: 'paid',
    donor: { id: 1, name: 'Rafael' }, amount_cents: 2000, currency: 'USD',
    trashable: false, untrashable_reason: STILL_BILLING,
    deletable: false, delete_blocked: STILL_BILLING,
};

const FREE = {
    id: 900, reference: 'DON-2026-00900', status: 'pending',
    donor: { id: 2, name: 'Sam' }, amount_cents: 1000, currency: 'USD',
    trashable: true, untrashable_reason: null,
    deletable: false, delete_blocked: null,
};

const BINNED_HELD = {
    id: 337, reference: 'DON-2026-00337', status: 'paid', trashed: true,
    trashed_at: '2026-09-01 10:00:00', donor: { id: 3, name: 'Ada' },
    deletable: false, delete_blocked: RECEIPT_STANDS,
};

const BINNED_FREE = {
    id: 338, reference: 'DON-2026-00338', status: 'failed', trashed: true,
    trashed_at: '2026-09-01 10:00:00', donor: { id: 4, name: 'Bo' },
    deletable: true, delete_blocked: null,
};

function seed( items, batch ) {
    apiFetch.mockReset();
    apiFetch.mockImplementation( ( { path, parse, method } ) => {
        if ( method && method !== 'GET' ) return Promise.resolve( batch || { done: [], already: [], refused: [] } );
        if ( parse === false ) {
            return Promise.resolve( { json: async () => items, headers: { get: () => String( items.length ) } } );
        }
        if ( typeof path === 'string' && path.includes( '/stats' ) ) return Promise.resolve( {} );
        return Promise.resolve( items );
    } );
}

async function mount( Component, items, batch ) {
    captured.actions = null;
    captured.confirm = null;
    document.body.innerHTML = '<div id="root"></div>';
    seed( items, batch );

    window.gratora = {
        can: {
            refund_donations: true, delete_donations: true, resend_receipt: true,
            view_donations: true, edit_donations: true, manage_options: true,
        },
    };

    render( <Component />, document.getElementById( 'root' ) );
    await waitFor( () => !! captured.actions, { what: 'the table to register its actions' } );
}

const action = ( id ) => captured.actions.find( ( a ) => a.id === id );
const noticeText = () => document.body.textContent || '';

test( 'move to trash stays on a row that cannot be trashed', async () => {
    await mount( List, [ HELD ] );

    expect( action( 'trash' ).isEligible( HELD ) ).toBe( true );
} );

test( 'and choosing it reads the reason out', async () => {
    await mount( List, [ HELD ] );

    action( 'trash' ).callback( [ HELD ] );
    await settle();

    expect( noticeText() ).toContain( 'still billing' );
    expect( noticeText() ).toContain( 'DON-2026-00783' );
} );

/** Nothing to trash means nothing is asked, so no confirmation opens. */
test( 'a selection of nothing but blocked rows opens no confirmation', async () => {
    await mount( List, [ HELD ] );

    action( 'trash' ).callback( [ HELD ] );
    await settle();

    expect( captured.confirm ).toBeNull();
} );

/**
 * The half that the success path used to erase: a mixed selection trashes its
 * eligible row, the batch comes back clean, and the blocked row's reason has to
 * survive that.
 */
test( 'a reason named before the request survives a clean batch', async () => {
    await mount( List, [ HELD, FREE ], { done: [ FREE.id ], already: [], refused: [] } );

    action( 'trash' ).callback( [ HELD, FREE ] );
    await settle();

    expect( captured.confirm ).not.toBeNull();
    await captured.confirm.onConfirm();
    await settle();

    expect( noticeText() ).toContain( 'still billing' );
} );

test( 'a row that can be trashed still goes to the confirmation', async () => {
    await mount( List, [ FREE ] );

    action( 'trash' ).callback( [ FREE ] );
    await settle();

    expect( captured.confirm ).not.toBeNull();
    expect( noticeText() ).not.toContain( 'still billing' );
} );

// -- the bin ---------------------------------------------------------------

test( 'delete permanently stays in the bin on a row the bin cannot empty', async () => {
    await mount( Trash, [ BINNED_HELD ] );

    expect( action( 'delete-permanently' ).isEligible( BINNED_HELD ) ).toBe( true );
} );

test( 'and it says the receipt is what holds the row', async () => {
    await mount( Trash, [ BINNED_HELD ] );

    action( 'delete-permanently' ).callback( [ BINNED_HELD ] );
    await settle();

    expect( noticeText() ).toContain( 'receipt' );
    expect( noticeText() ).toContain( 'DON-2026-00337' );
    expect( captured.confirm ).toBeNull();
} );

test( 'a binned row nothing holds is still deleted', async () => {
    await mount( Trash, [ BINNED_FREE ] );

    action( 'delete-permanently' ).callback( [ BINNED_FREE ] );
    await settle();

    expect( captured.confirm ).not.toBeNull();
} );
