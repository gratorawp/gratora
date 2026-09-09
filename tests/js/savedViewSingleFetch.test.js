/**
 * A saved table view arrives after the screen has mounted. Keying the data
 * fetch on the view alone means the screen asks for the list twice on every
 * load once anyone has sorted a column: once under its own defaults, then
 * again under the sort the reader actually left it in.
 */

import { render } from 'preact';
import apiFetch from '@wordpress/api-fetch';

const { waitFor } = require( './support/waitFor' );

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

jest.mock( '@wordpress/dataviews', () => ( { DataViews: () => null } ) );

jest.mock( '../../assets/admin/_shared/notify', () => ( {
    __esModule: true,
    default: { success: jest.fn(), error: jest.fn(), info: jest.fn() },
} ) );

jest.mock( '../../assets/admin/_shared/components/DateField', () => ( {
    __esModule: true,
    default: () => null,
} ) );

jest.mock( '../../assets/admin/_shared/components/ConfirmDialog', () => ( {
    __esModule: true,
    default: () => null,
} ) );

jest.mock( '../../assets/admin/donations/RecordDonationDrawer', () => ( {
    __esModule: true,
    default: () => null,
} ) );

const SCREENS = [
    {
        name:   'campaigns',
        module: '../../assets/admin/campaigns/List',
        route:  '/gratora/v1/admin/campaigns',
        saved:  { sort: { field: 'name', direction: 'asc' } },
    },
    {
        name:   'donations',
        module: '../../assets/admin/donations/List',
        route:  '/gratora/v1/admin/donations',
        saved:  { sort: { field: 'amount', direction: 'asc' } },
    },
    {
        name:   'donors',
        module: '../../assets/admin/donors/index',
        export: 'DonorsApp',
        route:  '/gratora/v1/admin/donors',
        saved:  { sort: { field: 'name', direction: 'asc' } },
    },
    {
        name:   'funds',
        module: '../../assets/admin/funds/List',
        route:  '/gratora/v1/admin/funds',
        saved:  { sort: { field: 'name', direction: 'desc' } },
    },
    {
        name:   'subscriptions',
        module: '../../assets/admin/subscriptions/List',
        route:  '/gratora/v1/admin/recurring',
        saved:  { sort: { field: 'amount', direction: 'desc' } },
    },
];

const calls = [];

// The pickers fetch the whole set a page at a time, so they are not the
// screen's own view-driven request and do not count.
const listCalls = ( route ) => calls.filter(
    ( path ) => path.startsWith( `${ route }?` ) && ! path.includes( 'per_page=100' )
);

function serve( saved ) {
    apiFetch.mockImplementation( ( { path, parse } ) => {
        calls.push( path );

        if ( path.startsWith( '/gratora/v1/admin/me/table-view' ) ) {
            return Promise.resolve( saved );
        }

        if ( parse === false ) {
            return Promise.resolve( { json: async () => [], headers: { get: () => '0' } } );
        }

        return Promise.resolve( [] );
    } );
}

beforeEach( () => {
    calls.length = 0;
    apiFetch.mockReset();
} );

describe.each( SCREENS )( '$name list', ( screen ) => {
    it( 'asks for its rows once when a saved sort is waiting', async () => {
        serve( screen.saved );

        // eslint-disable-next-line global-require
        const mod = require( screen.module );
        const List = screen.export ? mod[ screen.export ] : mod.default;

        const root = document.createElement( 'div' );
        document.body.appendChild( root );
        render( <List />, root );

        await waitFor( () => listCalls( screen.route ).length > 0 );
        await new Promise( ( resolve ) => setTimeout( resolve, 80 ) );

        expect( listCalls( screen.route ) ).toHaveLength( 1 );
        expect( listCalls( screen.route )[ 0 ] ).toContain(
            `order=${ screen.saved.sort.direction }`
        );

        render( null, root );
        root.remove();
    } );
} );
