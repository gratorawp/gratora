/**
 * A saved view outlives the column it names.
 *
 * The donors table stored sort.field "name" while nothing could sort by it.
 * The screen sent it anyway, the server did not recognise it and ordered by
 * last donation instead, and the header went on drawing an arrow over Name.
 * The reader had no way to tell the claimed order from the served one.
 *
 * Anything the table cannot ask for goes back to the default instead of
 * travelling as a request that will be quietly swapped.
 */

import { donorSortField } from '../../assets/admin/donors/index';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );
jest.mock( '@wordpress/api-fetch', () => jest.fn( () => new Promise( () => {} ) ) );
jest.mock( '@wordpress/dataviews', () => ( { DataViews: () => null } ) );
jest.mock( '../../assets/admin/donors/DonorProfile', () => ( { __esModule: true, default: () => null } ) );
jest.mock( '../../assets/admin/donors/Insights', () => ( { __esModule: true, default: () => null } ) );

test( 'a field the table cannot sort by is not sent', () => {
    expect( donorSortField( 'name_of_a_column_that_went' ) ).toBe( 'last_donation_at' );
} );

test( 'and neither is an empty one', () => {
    expect( donorSortField( undefined ) ).toBe( 'last_donation_at' );
    expect( donorSortField( '' ) ).toBe( 'last_donation_at' );
} );

test( 'the name column asks for the name order', () => {
    expect( donorSortField( 'name' ) ).toBe( 'name' );
} );

/** The column the table shows and the column the server holds differ here. */
test( 'total donated asks for the column it is stored in', () => {
    expect( donorSortField( 'total_donated' ) ).toBe( 'total_donated_cents' );
} );

test( 'the rest go through as themselves', () => {
    expect( donorSortField( 'donations_count' ) ).toBe( 'donations_count' );
    expect( donorSortField( 'last_donation_at' ) ).toBe( 'last_donation_at' );
    expect( donorSortField( 'created_at' ) ).toBe( 'created_at' );
} );
