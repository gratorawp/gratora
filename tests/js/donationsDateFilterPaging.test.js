/**
 * A date bound narrows the list from outside the view, so page 3 of the old
 * result set is past the end of the new one and the screen reads "Nothing
 * matches these filters" over rows that do match.
 */

import { render } from 'preact';

import apiFetch from '@wordpress/api-fetch';

import List from '../../assets/admin/donations/List';
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

const captured = { view: null, setView: null, dates: [] };

jest.mock( '@wordpress/dataviews', () => ( {
    DataViews: ( props ) => {
        captured.view = props.view;
        captured.setView = props.onChangeView;
        return null;
    },
} ) );

// The real one wants @wordpress/components, which does not load under the
// preact alias. Its onChange is the whole subject here.
jest.mock( '../../assets/admin/_shared/components/DateField', () => ( {
    __esModule: true,
    default: ( props ) => {
        captured.dates.push( props );
        return null;
    },
} ) );

jest.mock( '../../assets/admin/_shared/components/ConfirmDialog', () => ( {
    __esModule: true,
    default: () => null,
} ) );

jest.mock( '../../assets/admin/donations/RecordDonationDrawer', () => ( {
    __esModule: true,
    default: () => null,
} ) );

const rows = [ { id: 1, reference: 'DON-1', status: 'paid', donor: { name: 'A' } } ];

beforeEach( () => {
    captured.view = null;
    captured.setView = null;
    captured.dates = [];
    apiFetch.mockReset();
    apiFetch.mockImplementation( ( { path, parse } ) => {
        if ( parse === false ) {
            return Promise.resolve( { json: async () => rows, headers: { get: () => '400' } } );
        }
        if ( path.startsWith( '/gratora/v1/admin/donations/stats' ) ) return Promise.resolve( null );
        return Promise.resolve( [] );
    } );
} );

async function mountList() {
    document.body.innerHTML = '<div id="root"></div>';
    render( <List />, document.getElementById( 'root' ) );
    await waitFor( () => !! captured.view, { what: 'the table to render' } );
}

const field = ( label ) => captured.dates.find( ( d ) => ( d.ariaLabel || '' ).includes( label ) );

test( 'both date bounds are on screen', async () => {
    await mountList();

    expect( field( 'from' ) ).toBeTruthy();
    expect( field( 'to' ) ).toBeTruthy();
} );

/** Page three of the old result set, which is where the reader was. */
async function goToPageThree() {
    captured.setView( { ...captured.view, page: 3 } );
    await waitFor( () => captured.view.page === 3, { what: 'the reader to reach page three' } );
}

test( 'setting a From bound puts the reader back on page one', async () => {
    await mountList();
    await goToPageThree();

    field( 'from' ).onChange( '2026-01-01' );
    await waitFor( () => captured.view.page === 1, { what: 'the page to reset' } );

    expect( captured.view.page ).toBe( 1 );
} );

test( 'so does a To bound', async () => {
    await mountList();
    await goToPageThree();

    field( 'to' ).onChange( '2026-12-31' );
    await waitFor( () => captured.view.page === 1, { what: 'the page to reset' } );

    expect( captured.view.page ).toBe( 1 );
} );

test( 'and a filter that does not narrow the list leaves the page alone', async () => {
    await mountList();
    await goToPageThree();

    // Sanity on the harness: without a reset the reader stays where they were,
    // which is what the two above would otherwise be asserting by default.
    expect( captured.view.page ).toBe( 3 );
} );
