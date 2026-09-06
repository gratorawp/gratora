/**
 * The donor's identity card already reads the country in the admin's own
 * language; the edit form beside it read English. One country, two names, one
 * screen, and typing the name the card showed matched nothing.
 */

import { render } from 'preact';

import DonorProfile from '../../assets/admin/donors/DonorProfile';

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

const settle = () => new Promise( ( resolve ) => setTimeout( resolve, 40 ) );

let root = null;

async function openEditor() {
    if ( root ) render( null, root );
    document.body.innerHTML = '<div id="root"></div>';
    root = document.getElementById( 'root' );

    global.__profile = PROFILE;
    render( <DonorProfile id={ 4 } onBack={ () => {} } />, root );
    await settle();

    const edit = [ ...root.querySelectorAll( 'button' ) ]
        .find( ( b ) => /edit/i.test( b.textContent.trim() ) );
    if ( edit ) {
        edit.click();
        await settle();
    }

    return root.querySelector( '.dp-edit-form__country input, .dp-edit-form input[type="text"][role="combobox"]' )
        || root.querySelector( '.dp-edit-form__country input' );
}

const options = () => [ ...root.querySelectorAll( '.dp-edit-form__country-list button' ) ]
    .map( ( b ) => b.textContent );

beforeEach( () => {
    Intl.DisplayNames = GermanNames;
    window.fundkit = { can: { view_donors: true, edit_donors: true, manage_options: true } };
    document.body.innerHTML = '';
} );

afterEach( () => {
    Intl.DisplayNames = ORIGINAL;
} );

it( 'seeds the field with the name the identity card shows', async () => {
    const input = await openEditor();

    expect( input ).not.toBeNull();
    expect( input.value ).toBe( 'Deutschland' );
} );

it( 'matches a search in that language', async () => {
    const input = await openEditor();

    input.focus();
    input.value = 'Deutsch';
    input.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );
    await settle();

    expect( options().join( ' ' ) ).toContain( 'Deutschland' );
} );

it( 'still matches the English name', async () => {
    const input = await openEditor();

    input.focus();
    input.value = 'Germany';
    input.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );
    await settle();

    expect( options().join( ' ' ) ).toContain( 'Deutschland' );
} );
