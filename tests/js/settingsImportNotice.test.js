/**
 * The import screen is the only account an admin gets of what a restore did.
 * The confirm has to describe the write the server actually performs, and a
 * refusal has to still report the groups that landed before it: settings the
 * route accepted are not rolled back when a later one is refused.
 */

import { render } from 'preact';

import apiFetch from '@wordpress/api-fetch';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
// Webpack aliases react to preact/compat for the admin bundles, and this screen
// only renders under that alias.
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

// The confirm object is the subject of one test and the trigger of the others,
// so it is captured rather than rendered.
const captured = { confirm: null };

jest.mock( '../../assets/admin/_shared/components/ConfirmDialog', () => ( {
    __esModule: true,
    default: ( props ) => {
        captured.confirm = props.confirm;
        return null;
    },
} ) );

// A second importer with its own requests, and nothing to do with this one.
jest.mock( '../../assets/admin/tools/tabs/CsvImportCard', () => ( {
    __esModule: true,
    default: () => null,
} ) );

import ImportTab from '../../assets/admin/tools/tabs/ImportTab';

const settle = () => new Promise( ( r ) => setTimeout( r, 20 ) );

const notices = [];

function mount() {
    document.body.innerHTML = '<div id="root"></div>';
    render(
        <ImportTab setNotice={ ( n ) => notices.push( n ) } />,
        document.getElementById( 'root' )
    );
}

/**
 * Hands the file input a chosen file. Only `name` and `text()` are read, so a
 * stand-in avoids depending on jsdom's Blob, and both event names are tried
 * because preact/compat picks one by input type.
 */
async function choose( payload ) {
    const input = document.querySelector( 'input[type="file"]' );
    Object.defineProperty( input, 'files', {
        configurable: true,
        value: [ { name: 'dono-export.json', text: async () => JSON.stringify( payload ) } ],
    } );

    input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
    await settle();
    if ( ! captured.confirm ) {
        input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
        await settle();
    }

    return captured.confirm;
}

/** The last notice the screen raised. */
const lastNotice = () => notices[ notices.length - 1 ];

beforeEach( () => {
    captured.confirm = null;
    notices.length = 0;
    apiFetch.mockReset();
} );

test( 'the settings confirm does not promise to clear settings the file omits', async () => {
    mount();

    const confirm = await choose( { settings: { dono_receipt_settings: { header_title: 'Imported' } } } );

    expect( confirm ).toBeTruthy();
    // SettingsService::update merges the file over what is stored, so a key the
    // file does not carry survives the restore.
    expect( confirm.message ).toContain( 'keeps the value it has here' );
    expect( confirm.message ).not.toContain( 'will overwrite' );
} );

test( 'a full export is told its settings half is written over the site', async () => {
    mount();

    const confirm = await choose( { tables: {}, settings: { dono_gateway_config: { stripe: {} } } } );

    expect( confirm.message ).toContain( 'written over yours' );
} );

test( 'a records-only file is not warned about settings it does not carry', async () => {
    mount();

    const confirm = await choose( { tables: {}, settings: {} } );

    expect( confirm.message ).not.toContain( 'written over yours' );
} );

test( 'a refused import still reports the settings groups that landed', async () => {
    mount();

    apiFetch.mockImplementation( () => Promise.reject( {
        code:    'dono_base_currency_locked',
        message: 'Part of that file was not restored. The base currency stays EUR.',
        data:    { status: 409, applied: 1, refused: { dono_currency: 'locked' }, imported: false, records: null },
    } ) );

    const confirm = await choose( { settings: { dono_currency: {}, dono_receipt_settings: {} } } );
    await confirm.onConfirm();
    await settle();

    const notice = lastNotice();
    expect( notice.type ).toBe( 'error' );
    expect( notice.text ).toContain( 'The base currency stays EUR.' );
    expect( notice.text ).toContain( '1 settings group restored' );
} );

test( 'a failure carrying nothing that landed reports only the reason', async () => {
    mount();

    apiFetch.mockImplementation( () => Promise.reject( {
        code:    'dono_invalid_import',
        message: 'No settings payload found.',
        data:    { status: 422, applied: 0, imported: false, records: null },
    } ) );

    const confirm = await choose( { settings: {} } );
    await confirm.onConfirm();
    await settle();

    expect( lastNotice().text ).toBe( 'No settings payload found.' );
} );

test( 'a full restore reports its records and its settings groups', async () => {
    mount();

    apiFetch.mockImplementation( () => Promise.resolve( {
        ok:       true,
        applied:  2,
        imported: true,
        records:  { created: { donors: 3, donations: 4 }, existing: { donors: 1 }, skipped: {} },
    } ) );

    const confirm = await choose( { tables: {}, settings: {} } );
    await confirm.onConfirm();
    await settle();

    const notice = lastNotice();
    expect( notice.type ).toBe( 'success' );
    expect( notice.text ).toContain( '7 records restored' );
    expect( notice.text ).toContain( '1 was already here' );
    expect( notice.text ).toContain( '2 settings groups restored' );
} );
