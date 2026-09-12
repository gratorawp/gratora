/**
 * A row the server will not delete has to say so.
 *
 * The gate's answer is a sentence naming what to do about it: cancel the
 * subscription, refund the donation. The screen received that sentence and
 * dropped it, using only the boolean to decide whether to draw the action. So
 * a blocked row showed no Delete and no reason, which reads exactly like a
 * feature that does not exist, and the operator has no way to learn that
 * cancelling the subscription would let the row go.
 *
 * A row with no reason is different: it needs the bin first, and Trash is
 * sitting there offering itself. Nothing is owed there.
 */

import { render } from 'preact';

import apiFetch from '@wordpress/api-fetch';

import { DonorsApp } from '../../assets/admin/donors/index';
import List from '../../assets/admin/donations/List';

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
    default: ( { confirm } ) => {
        if ( confirm ) captured.confirm = confirm;
        return null;
    },
} ) );

jest.mock( '../../assets/admin/donors/DonorProfile', () => ( { __esModule: true, default: () => null } ) );
jest.mock( '../../assets/admin/donors/Insights', () => ( { __esModule: true, default: () => null } ) );

const BLOCKED = 'This payment belongs to subscription #45, which is still billing. Cancel it first.';

let root = null;

function seed( rows ) {
    apiFetch.mockReset();
    apiFetch.mockImplementation( ( { path, parse } ) => {
        if ( parse === false ) {
            return Promise.resolve( { json: async () => rows, headers: { get: () => String( rows.length ) } } );
        }
        if ( path.startsWith( '/gratora/v1/admin/donors/stats' ) ) return Promise.resolve( null );
        if ( path.startsWith( '/gratora/v1/admin/me/' ) ) return Promise.resolve( {} );
        return Promise.resolve( {} );
    } );
}

async function mount( Component ) {
    if ( root ) render( null, root );
    captured.actions = null;
    captured.confirm = null;

    document.body.innerHTML = '<div id="root"></div>';
    root = document.getElementById( 'root' );
    render( <Component />, root );
    await waitFor( () => !! captured.actions, { what: 'the list to register its actions' } );
}

const actionById = ( id ) => captured.actions.find( ( a ) => a.id === id );

describe( 'the donors list', () => {
    const blocked   = { id: 1, name: 'Held', email: 'h@example.test', donations_count: 0, is_test_only: false, deletable: false, delete_blocked: BLOCKED, redacted: false };
    const deletable = { id: 2, name: 'Free', email: 'f@example.test', donations_count: 0, is_test_only: false, deletable: true, delete_blocked: null, redacted: false };

    test( 'offers Delete on a row it cannot delete, so the reason can be read', async () => {
        seed( [ blocked, deletable ] );
        await mount( DonorsApp );

        expect( actionById( 'delete' ).isEligible( blocked ) ).toBe( true );
    } );

    test( 'and answers it with the reason instead of a confirmation', async () => {
        seed( [ blocked, deletable ] );
        await mount( DonorsApp );

        actionById( 'delete' ).callback( [ blocked ] );
        await settle();

        expect( captured.confirm ).toBeNull();
        expect( document.body.textContent ).toContain( 'Cancel it first' );
    } );

    test( 'a row with no reason still offers nothing, because the bin is offering itself', async () => {
        const needsBin = { ...blocked, delete_blocked: null };
        seed( [ needsBin ] );
        await mount( DonorsApp );

        expect( actionById( 'delete' ).isEligible( needsBin ) ).toBe( false );
    } );

    test( 'a refused request says what the server said, not just how many', async () => {
        seed( [ deletable ] );
        await mount( DonorsApp );

        apiFetch.mockImplementation( ( { path, parse, method } ) => {
            if ( parse === false ) {
                return Promise.resolve( { json: async () => [ deletable ], headers: { get: () => '1' } } );
            }
            if ( path.startsWith( '/gratora/v1/admin/donors/stats' ) ) return Promise.resolve( null );
            if ( method === 'DELETE' ) {
                return Promise.reject( new Error( 'Cannot cancel subscription walk-sub-1 (stripe, plan #46): the gateway is not available.' ) );
            }
            return Promise.resolve( {} );
        } );

        actionById( 'delete' ).callback( [ deletable ] );
        await settle();
        await captured.confirm.onConfirm();
        await settle();

        expect( document.body.textContent ).toContain( 'plan #46' );
    } );
} );

describe( 'the donations list', () => {
    const blocked = {
        id: 9, reference: 'DON-9', status: 'paid', donor: { name: 'Held' },
        deletable: false, delete_blocked: BLOCKED, trashed: false,
    };

    test( 'offers Delete permanently on a blocked row', async () => {
        seed( [ blocked ] );
        await mount( List );

        expect( actionById( 'delete-permanently' ).isEligible( blocked ) ).toBe( true );
    } );

    test( 'and names the reason rather than opening a confirmation', async () => {
        seed( [ blocked ] );
        await mount( List );

        actionById( 'delete-permanently' ).callback( [ blocked ] );
        await settle();

        expect( captured.confirm ).toBeNull();
        expect( document.body.textContent ).toContain( 'Cancel it first' );
    } );
} );
