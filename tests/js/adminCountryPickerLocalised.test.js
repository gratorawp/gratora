/**
 * The donor's identity card already reads the country in the admin's own
 * language; the edit form beside it read English. One country, two names, one
 * screen, and typing the name the card showed matched nothing.
 */

import { render } from 'preact';

import DonorProfile from '../../assets/admin/donors/DonorProfile';

const { waitFor } = require( './support/waitFor' );

const ORIGINAL = Intl.DisplayNames;

class GermanNames {
    of( code ) {
        return { DE: 'Deutschland', FR: 'Frankreich' }[ code ] || code;
    }
}

jest.mock( '@wordpress/api-fetch', () => jest.fn( () => Promise.resolve( global.__profile ) ) );
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

jest.mock( '../../assets/admin/_shared/notify', () => ( {
    __esModule: true,
    notify:  { success: jest.fn(), error: jest.fn(), info: jest.fn() },
    default: { success: jest.fn(), error: jest.fn(), info: jest.fn() },
} ) );

const PROFILE = {
    donor: {
        id: 4, name: 'Alice Okafor', first_name: 'Alice', last_name: 'Okafor',
        email: 'alice@example.test', country: 'DE', address: {},
        total_donated_cents: 0, donations_count: 0, donors_count: 0,
    },
    lifetime:  { total_cents: 0, count: 0, avg_cents: 0, largest_cents: 0 },
    donations: [],
    recurring: { plans: [] },
    consents:  [],
    notes:     [],
    receipts:  [],
    events:    [],
    campaigns: [],
    banners:   [],
    events_total: 0, donations_total: 0, receipts_total: 0, notes_total: 0,
};

let root = null;

const editButton = () => [ ...root.querySelectorAll( 'button' ) ]
    .find( ( b ) => /edit/i.test( b.textContent.trim() ) );

const countryInput = () => root.querySelector( '.dp-edit-form__country input, .dp-edit-form input[type="text"][role="combobox"]' );

async function openEditor() {
    if ( root ) render( null, root );
    document.body.innerHTML = '<div id="root"></div>';
    root = document.getElementById( 'root' );

    global.__profile = PROFILE;
    render( <DonorProfile id={ 4 } onBack={ () => {} } />, root );
    await waitFor( editButton, { what: 'the loaded profile' } );

    editButton().click();
    await waitFor( countryInput, { what: 'the edit form' } );

    return countryInput();
}

const options = () => [ ...root.querySelectorAll( '.dp-edit-form__country-list button' ) ]
    .map( ( b ) => b.textContent );

async function search( input, query ) {
    input.focus();
    input.value = query;
    input.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );

    // Each option carries its code beside the name. Frankreich matches neither
    // query, so its absence is what says the list has been filtered rather than
    // merely opened.
    await waitFor(
        () => options().length > 0 && ! options().some( ( o ) => o.includes( 'Frankreich' ) ),
        { what: 'filtered countries' }
    );
}

beforeEach( () => {
    Intl.DisplayNames = GermanNames;
    window.gratora = { can: { view_donors: true, edit_donors: true, manage_options: true } };
    document.body.innerHTML = '';
} );

afterEach( () => {
    if ( root ) render( null, root );
    root = null;
    Intl.DisplayNames = ORIGINAL;
} );

it( 'seeds the field with the name the identity card shows', async () => {
    const input = await openEditor();

    expect( input.value ).toBe( 'Deutschland' );
} );

it( 'matches a search in that language', async () => {
    const input = await openEditor();
    await search( input, 'Deutsch' );

    expect( options().join( ' ' ) ).toContain( 'Deutschland' );
} );

it( 'still matches the English name', async () => {
    const input = await openEditor();
    await search( input, 'Germany' );

    expect( options().join( ' ' ) ).toContain( 'Deutschland' );
} );
