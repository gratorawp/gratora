/**
 * A column that draws a sort arrow has to be a column the server will sort by.
 *
 * DataViews treats a field as sortable unless it is told otherwise
 * (normalize-fields: `enableSorting ?? true`), so a column added without a
 * thought about ordering is offered for sorting anyway. The repositories take
 * a fixed list of columns and fall back to their own default for anything
 * else, silently, which leaves the header claiming one order while the rows
 * are in another. It was live on all three screens: the donors list served
 * last donation under a NAME arrow, and the subscriptions list served next
 * charge under a DONOR arrow.
 *
 * Each table is rendered and asked what it offered, so a column added later
 * without a decision about sorting fails here rather than on the screen.
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
    default: { success: jest.fn(), error: jest.fn(), info: jest.fn() },
} ) );

const captured = { fields: null };

jest.mock( '@wordpress/dataviews', () => ( {
    DataViews: ( props ) => {
        captured.fields = props.fields;
        return null;
    },
} ) );

jest.mock( '../../assets/admin/donors/DonorProfile', () => ( { __esModule: true, default: () => null } ) );
jest.mock( '../../assets/admin/donors/Insights', () => ( { __esModule: true, default: () => null } ) );

const ROW = {
    id: 1,
    reference: 'DON-1',
    status: 'paid',
    donor: { id: 2, name: 'A' },
    name: 'A',
    email: 'a@example.test',
    amount_cents: 1000,
    currency: 'EUR',
    interval_unit: 'month',
    interval_count: 1,
    gateway: 'stripe',
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

/**
 * Each screen: the component to render, and the ids it can ask the server to
 * order by. The second is the module's own list, so the two cannot drift.
 */
const TABLES = [
    [
        'donations',
        () => require( '../../assets/admin/donations/List' ).default,
        () => require( '../../assets/admin/donations/fields' ).SORTABLE_FIELD_IDS,
    ],
    [
        'donations trash',
        () => require( '../../assets/admin/donations/Trash' ).default,
        () => require( '../../assets/admin/donations/fields' ).SORTABLE_FIELD_IDS,
    ],
    [
        'donors',
        () => require( '../../assets/admin/donors/index' ).DonorsApp,
        () => require( '../../assets/admin/donors/index' ).SORTABLE_FIELD_IDS,
    ],
    [
        'subscriptions',
        () => require( '../../assets/admin/subscriptions/List' ).default,
        () => require( '../../assets/admin/subscriptions/List' ).SORTABLE_FIELD_IDS,
    ],
];

beforeEach( () => {
    captured.fields = null;
    document.body.innerHTML = '';
    window.gratora = {
        can: {
            delete_donations: true, refund_donations: true, redact_donors: true,
            manage_campaigns: true, manage_funds: true, manage_options: true,
            view_donations: true, edit_donations: true,
        },
    };
} );

it.each( TABLES )(
    'every %s column offering a sort is one the table can order by',
    async ( _name, load, sortableIds ) => {
        seed();
        const Component = load();

        const root = document.createElement( 'div' );
        document.body.appendChild( root );
        render( <Component />, root );

        await waitFor( () => !! captured.fields, { what: 'the table to register its fields' } );

        // What DataViews will offer: everything not explicitly turned off.
        const offered = captured.fields
            .filter( ( f ) => f.enableSorting !== false )
            .map( ( f ) => f.id );

        const cannotBeOrdered = offered.filter( ( id ) => ! sortableIds().includes( id ) );

        expect( cannotBeOrdered ).toEqual( [] );
    }
);
