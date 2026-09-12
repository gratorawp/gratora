/**
 * A bulk action with no icon is not drawn at all.
 *
 * DataViews renders the bulk bar as icon-only buttons and filters the list with
 * `action.icon && ...`, so `supportsBulk: true` on its own puts the action in
 * the bar's model and nothing on the screen. Selecting rows then gives you
 * "3 items selected" and no way to act on them, which is indistinguishable
 * from the feature not existing: it is what was reported about the trash, and
 * adding supportsBulk did not fix it because the icon was what was missing.
 *
 * This is the whole contract, asked of every table at once so a new action
 * cannot quietly reintroduce it.
 */

import { render } from 'preact';
import apiFetch from '@wordpress/api-fetch';

const { waitFor } = require( './support/waitFor' );

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

const captured = { actions: null };

jest.mock( '@wordpress/dataviews', () => ( {
    DataViews: ( props ) => {
        captured.actions = props.actions;
        return null;
    },
} ) );

jest.mock( '../../assets/admin/donors/DonorProfile', () => ( { __esModule: true, default: () => null } ) );
jest.mock( '../../assets/admin/donors/Insights', () => ( { __esModule: true, default: () => null } ) );

const ROW = {
    id: 1,
    reference: 'DON-1',
    status: 'paid',
    trashed: false,
    deletable: true,
    delete_blocked: null,
    trashable: true,
    donor: { name: 'A' },
    name: 'A',
    email: 'a@example.test',
    is_test_only: false,
    redacted: false,
    is_default: false,
    is_active: true,
    reassign_pending: false,
    can_retry: true,
    failed_renewals_count: 1,
    amount_cents: 1000,
    currency: 'EUR',
    interval_unit: 'month',
    interval_count: 1,
};

function seed() {
    apiFetch.mockReset();
    apiFetch.mockImplementation( ( { path, parse } ) => {
        if ( parse === false ) {
            return Promise.resolve( { json: async () => [ ROW ], headers: { get: () => '1' } } );
        }
        if ( typeof path === 'string' && path.includes( '/me/' ) ) return Promise.resolve( {} );
        return Promise.resolve( [ ROW ] );
    } );
}

const TABLES = [
    [ 'donations', () => require( '../../assets/admin/donations/List' ).default ],
    [ 'donations trash', () => require( '../../assets/admin/donations/Trash' ).default ],
    [ 'donors', () => require( '../../assets/admin/donors/index' ).DonorsApp ],
    [ 'campaigns', () => require( '../../assets/admin/campaigns/List' ).default ],
    [ 'funds', () => require( '../../assets/admin/funds/List' ).default ],
    [ 'subscriptions', () => require( '../../assets/admin/subscriptions/List' ).default ],
];

beforeEach( () => {
    captured.actions = null;
    document.body.innerHTML = '';
    window.gratora = {
        can: {
            delete_donations: true, refund_donations: true, redact_donors: true,
            manage_campaigns: true, manage_funds: true, manage_options: true,
            view_donations: true, edit_donations: true,
        },
    };
} );

it.each( TABLES )( 'every bulk action on the %s table can be drawn', async ( _name, load ) => {
    seed();
    const Component = load();

    const root = document.createElement( 'div' );
    document.body.appendChild( root );
    render( <Component />, root );

    await waitFor( () => !! captured.actions, { what: 'the table to register its actions' } );

    const invisible = captured.actions
        .filter( ( a ) => a.supportsBulk )
        .filter( ( a ) => ! a.icon )
        .map( ( a ) => a.id );

    expect( invisible ).toEqual( [] );
} );
