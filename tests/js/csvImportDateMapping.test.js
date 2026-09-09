/**
 * The importer refuses a donation row that cannot say when the money arrived,
 * so a file with amounts and no Date column imports nothing at all. The mapping
 * screen has to refuse that before the dry run does, and the dry run's own
 * reasons have to reach the admin in words.
 */

import { render } from 'preact';

import apiFetch from '@wordpress/api-fetch';

import CsvImportCard from '../../assets/admin/tools/tabs/CsvImportCard';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

const { settle } = require( './support/waitFor' );

// What csv-inspect answers for a file whose columns it recognised, minus the
// date: a real export writing "Donated On" is not guessed.
const inspected = {
    rows:    1204,
    headers: [ 'Email', 'Amount', 'Donated On' ],
    fields:  { email: 'Email', amount: 'Amount', date: 'Date' },
    mapping: { email: 'Email', amount: 'Amount' },
    sample:  [ { Email: 'a@example.test', Amount: '25.00', 'Donated On': '2024-03-02' } ],
};

let previewResponse = null;

function seedApi() {
    apiFetch.mockImplementation( ( { path } ) => {
        if ( path.endsWith( 'csv-inspect' ) ) return Promise.resolve( inspected );
        if ( path.endsWith( 'csv-import' ) ) return Promise.resolve( previewResponse );
        return Promise.resolve( {} );
    } );
}

async function mountWithFile() {
    document.body.innerHTML = '<div id="root"></div>';
    render( <CsvImportCard setNotice={ () => {} } />, document.getElementById( 'root' ) );

    const input = document.querySelector( 'input[type="file"]' );
    Object.defineProperty( input, 'files', {
        value:        [ { text: async () => 'Email,Amount,Donated On\na@example.test,25.00,2024-03-02\n' } ],
        configurable: true,
    } );
    input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
    await settle();

    return document.getElementById( 'root' );
}

const previewButton = ( root ) =>
    [ ...root.querySelectorAll( 'button' ) ].find( ( b ) => b.textContent.trim() === 'Preview' );

/** The Gratora field cell of a mapping row, by its label. */
const mappingRow = ( root, label ) =>
    [ ...root.querySelectorAll( 'tr' ) ].find( ( tr ) => tr.querySelector( 'th' )?.textContent.trim().startsWith( label ) );

beforeEach( () => {
    apiFetch.mockReset();
    previewResponse = null;
    seedApi();
} );

test( 'a file with amounts cannot be previewed until Date is mapped', async () => {
    const root = await mountWithFile();

    expect( previewButton( root ).disabled ).toBe( true );
    expect( root.textContent ).toContain( 'Date has to be mapped' );
    expect( mappingRow( root, 'Date' ).querySelector( '.gratora-csv-map__req' ) ).not.toBeNull();
} );

test( 'mapping the date column lets the dry run go ahead', async () => {
    const root = await mountWithFile();

    const select = mappingRow( root, 'Date' ).querySelector( 'select' );
    select.value = 'Donated On';
    select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
    await settle();

    expect( previewButton( root ).disabled ).toBe( false );
    expect( root.textContent ).toContain( 'Each row will be imported as a donation.' );
} );

test( 'a row skipped for its date says so in words', async () => {
    const root = await mountWithFile();

    const select = mappingRow( root, 'Date' ).querySelector( 'select' );
    select.value = 'Donated On';
    select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
    await settle();

    previewResponse = {
        mode: 'donations', dry_run: true, donations_imported: 0,
        donors_created: 0, donors_matched: 0, skipped: { invalid_date: 3 }, errors: [],
    };
    previewButton( root ).click();
    await settle();

    expect( root.textContent ).toContain( 'the date is missing or unreadable' );
    expect( root.textContent ).not.toContain( 'invalid_date' );
} );
