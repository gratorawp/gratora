/**
 * How often a donation repeats, in words, wherever it is asked.
 *
 * The detail rail spaced out the database key and let CSS put a capital on
 * it, so a one-off read "One Time" beside a list column saying "One time",
 * and stayed English on a site translated everywhere else.
 *
 * frequencyLabel itself answered "Recurring" for a donation that happened
 * once, because one_time fell through its default, and had no arm at all for
 * the fortnightly cadence the server offers.
 */

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

import { render } from 'preact';

import { frequencyLabel, FREQUENCY_OPTIONS } from '../../assets/admin/donations/fields';
import MetadataCard from '../../assets/admin/donations/detail/rail/MetadataCard';

// What the server admits as a recurring cadence, plus the one-off.
const CADENCES = [ 'one_time', 'weekly', 'biweekly', 'monthly', 'quarterly', 'yearly' ];

function rail( frequency ) {
    document.body.innerHTML = '<div id="root"></div>';
    render(
        <MetadataCard donation={ { frequency, created_at: '2026-08-15 10:44:00', country: 'GB' } } />,
        document.getElementById( 'root' )
    );

    return document.body.textContent;
}

it( 'does not call a donation that happened once a repeating one', () => {
    expect( frequencyLabel( 'one_time' ) ).toBe( 'One time' );
} );

it( 'names the fortnightly cadence the server offers', () => {
    expect( frequencyLabel( 'biweekly' ) ).toBe( 'Every 2 weeks' );
} );

it( 'lets an operator filter for it too', () => {
    expect( FREQUENCY_OPTIONS.map( ( o ) => o.value ) ).toContain( 'biweekly' );
} );

it( 'has a word for every cadence, none of them the key', () => {
    const keys = CADENCES.filter( ( c ) => frequencyLabel( c ) === c );

    expect( keys ).toEqual( [] );
} );

it( 'says nothing hopeful about a cadence it has never met', () => {
    expect( frequencyLabel( 'fortnightly_ish' ) ).toBe( 'Recurring' );
} );

describe( 'the detail rail', () => {
    it( 'says the same word the list does', () => {
        expect( rail( 'one_time' ) ).toContain( 'One time' );
    } );

    it( 'prints no underscore and no stray capital', () => {
        expect( rail( 'one_time' ) ).not.toContain( 'one_time' );
        expect( rail( 'one_time' ) ).not.toContain( 'One Time' );
    } );

    it.each( CADENCES )( 'reads %s as the word for it', ( cadence ) => {
        expect( rail( cadence ) ).toContain( frequencyLabel( cadence ) );
    } );
} );
