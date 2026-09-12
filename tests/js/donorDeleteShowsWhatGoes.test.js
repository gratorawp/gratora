/**
 * The bulk delete confirmation has to say how much it is about to destroy.
 *
 * It said "Delete 22 donors?" and stopped there. The rows carry each donor's
 * donation count and lifetime total, and the dialog totalled neither, so an
 * action that removed 440 donations and $82,250 asked about 22 of something
 * else. The row count is the one number that does not describe the damage.
 *
 * The counters are paid money only and net of refunds, so the sentence names
 * what they name and says separately that the uncounted attempts go too,
 * rather than implying the figure covers every row.
 */

import { render } from 'preact';
import apiFetch from '@wordpress/api-fetch';

import { DonorsApp } from '../../assets/admin/donors/index';

const { waitFor, settle } = require( './support/waitFor' );

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

jest.mock( '../../assets/admin/_shared/notify', () => ( {
    __esModule: true,
    notify:  { success: jest.fn(), error: jest.fn(), info: jest.fn() },
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

const donor = ( id, donations, cents ) => ( {
    id,
    name: `Donor ${ id }`,
    email: `d${ id }@example.test`,
    donations_count: donations,
    total_donated_cents: cents,
    is_test_only: false,
    deletable: true,
    delete_blocked: null,
    redacted: false,
} );

let root = null;

async function mount( rows ) {
    apiFetch.mockReset();
    apiFetch.mockImplementation( ( { path, parse } ) => {
        if ( parse === false ) {
            return Promise.resolve( { json: async () => rows, headers: { get: () => String( rows.length ) } } );
        }
        if ( typeof path === 'string' && path.includes( '/donors/stats' ) ) return Promise.resolve( null );
        return Promise.resolve( {} );
    } );

    if ( root ) render( null, root );
    captured.actions = null;
    captured.confirm = null;
    document.body.innerHTML = '<div id="root"></div>';
    root = document.getElementById( 'root' );
    render( <DonorsApp />, root );
    await waitFor( () => !! captured.actions, { what: 'the list to register its actions' } );
}

const del = () => captured.actions.find( ( a ) => a.id === 'delete' );

beforeEach( () => {
    window.gratora = { can: { redact_donors: true } };
} );

it( 'totals the donations and the money across the selection', async () => {
    const rows = [ donor( 1, 20, 246000 ), donor( 2, 15, 100000 ) ];
    await mount( rows );

    del().callback( rows );
    await settle();

    expect( captured.confirm.message ).toContain( '35 donations' );
    expect( captured.confirm.message ).toContain( '3,460' );
} );

it( 'says the uncounted attempts go too, because the figures are paid money only', async () => {
    const rows = [ donor( 1, 20, 246000 ) ];
    await mount( rows );

    del().callback( rows );
    await settle();

    expect( captured.confirm.message.toLowerCase() ).toContain( 'never completed' );
} );

it( 'claims no money for a donor who never gave', async () => {
    const rows = [ donor( 9, 0, 0 ) ];
    await mount( rows );

    del().callback( rows );
    await settle();

    expect( captured.confirm.message ).not.toMatch( /0 donations/ );
    expect( captured.confirm.message ).not.toContain( '$0' );
} );

/** Only the rows the action will actually act on are counted. */
it( 'leaves an undeletable donor out of the totals', async () => {
    const rows = [
        donor( 1, 20, 246000 ),
        { ...donor( 2, 99, 999900 ), deletable: false, delete_blocked: 'A receipt still stands.' },
    ];
    await mount( rows );

    del().callback( rows );
    await settle();

    expect( captured.confirm.message ).toContain( '20 donations' );
    expect( captured.confirm.message ).not.toContain( '119' );
} );
