/**
 * What kind of donor a record is, in the words the server sent.
 *
 * The column printed the database key with a capital put on it by CSS while
 * its own filter beside it went through translation, so a French site read a
 * chip saying one thing and a cell saying "organization".
 */

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

import { donorTypeLabel, donorTypeOptions } from '../../assets/admin/_shared/statuses';

// Words the browser could not have guessed.
const SHIPPED = [
    { value: 'individual', label: 'Particulier' },
    { value: 'organization', label: 'Organisme' },
    { value: 'household', label: 'Foyer' },
];

beforeEach( () => {
    window.gratora = { donor_types: SHIPPED };
} );

it( 'says the word it was handed', () => {
    expect( donorTypeLabel( 'organization' ) ).toBe( 'Organisme' );
} );

it( 'offers the filter exactly what it was handed', () => {
    expect( donorTypeOptions() ).toEqual( SHIPPED );
} );

it( 'says the same word the filter offers, for every type', () => {
    const disagreed = donorTypeOptions().filter( ( t ) => t.label !== donorTypeLabel( t.value ) );

    expect( disagreed ).toEqual( [] );
} );

/** A page served without the vocabulary still must not print an underscore. */
it( 'spaces out a type nobody sent a word for', () => {
    window.gratora = {};

    expect( donorTypeLabel( 'major_gift' ) ).toBe( 'major gift' );
    expect( donorTypeLabel( undefined ) ).toBe( '' );
} );
