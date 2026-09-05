/**
 * A request that never landed is not an answer. Each of these screens read a
 * failure back as a fact about the site: no exchange rates, no currencies
 * enabled, nothing outstanding, no such form, no such donor.
 */

import { render } from 'preact';
import apiFetch from '@wordpress/api-fetch';

import MaintenanceTab from '../../assets/admin/tools/tabs/MaintenanceTab';
import DonorProfile from '../../assets/admin/donors/DonorProfile';

const { waitFor } = require( './support/waitFor' );

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

jest.mock( '../../assets/admin/_shared/notify', () => ( {
    __esModule: true,
    notify:  { success: jest.fn(), error: jest.fn(), info: jest.fn() },
    default: { success: jest.fn(), error: jest.fn(), info: jest.fn() },
} ) );

jest.mock( '../../assets/admin/_shared/useFundKitRecord', () => ( {
    __esModule: true,
    useFundKitRecord: () => global.__record,
} ) );

jest.mock( '../../assets/admin/_shared/extensionTabs', () => ( {
    __esModule: true,
    useExtensionTabs: () => [],
    useExtensionPanels: () => [],
    ExtensionTabPanel: () => null,
    ExtensionSection: () => null,
} ) );

// The profile hands its refetch to the tabs that change a donor. Standing in
// for one is how the refresh-after-an-edit path is reachable from here.
const captured = { refetch: null };

jest.mock( '../../assets/admin/donors/profile/tabs/NotesTab', () => ( {
    __esModule: true,
    default: ( props ) => {
        captured.refetch = props.onChanged;
        return null;
    },
} ) );

const settle = () => new Promise( ( resolve ) => setTimeout( resolve, 40 ) );

let root = null;

function mount( node ) {
    if ( root ) render( null, root );
    document.body.innerHTML = '<div id="root"></div>';
    root = document.getElementById( 'root' );
    render( node, root );
    return root;
}

beforeEach( () => {
    apiFetch.mockReset();
    document.body.innerHTML = '';
} );

describe( 'the maintenance tab', () => {
    it( 'says the checks did not run instead of looking healthy', async () => {
        mount(
            <MaintenanceTab
                info={ null }
                infoError
                active={ false }
                loadInfo={ () => {} }
                setNotice={ () => {} }
            />
        );

        expect( document.body.textContent ).toContain( 'Could not check this site' );
        expect( document.body.textContent ).toContain( 'Check again' );
    } );

    it( 'says nothing of the sort when the checks did run', async () => {
        mount(
            <MaintenanceTab
                info={ { pending_upgrades: [], unconverted_donations: [], test_data: null } }
                infoError={ false }
                active={ false }
                loadInfo={ () => {} }
                setNotice={ () => {} }
            />
        );

        expect( document.body.textContent ).not.toContain( 'Could not check this site' );
    } );
} );

describe( 'the donor profile', () => {
    const PROFILE = {
        donor:     { id: 3, name: 'Nadia', email: 'n@example.test' },
        lifetime:  {},
        donations: [],
        recurring: { plans: [] },
        receipts:  [],
        notes:     [],
        consents:  [],
        events:    [],
        campaigns: [],
        banners:   [],
    };

    it( 'offers a way onward when the first load fails', async () => {
        apiFetch.mockImplementation( () => Promise.reject( new Error( 'gateway timeout' ) ) );

        mount( <DonorProfile id={ 3 } onBack={ () => {} } /> );
        await waitFor( () => document.body.textContent.includes( 'gateway timeout' ) );

        const labels = [ ...document.querySelectorAll( 'button, a' ) ].map( ( b ) => b.textContent.trim() );
        expect( labels ).toContain( 'Try again' );
        expect( labels ).toContain( 'Back to donors' );
    } );

    it( 'keeps the profile on screen when a refresh fails, and says why', async () => {
        let fail = false;
        captured.refetch = null;
        apiFetch.mockImplementation( () => (
            fail ? Promise.reject( new Error( 'network down' ) ) : Promise.resolve( PROFILE )
        ) );

        mount( <DonorProfile id={ 3 } onBack={ () => {} } /> );
        await waitFor( () => document.body.textContent.includes( 'Nadia' ) );

        // Open the tab that owns the refetch, then let a note change fail.
        [ ...document.querySelectorAll( '[role=tab]' ) ]
            .find( ( t ) => t.textContent.trim().startsWith( 'Notes' ) )
            .click();
        await settle();

        expect( captured.refetch ).toBeInstanceOf( Function );
        fail = true;
        await captured.refetch();
        await settle();

        expect( document.body.textContent ).toContain( 'Nadia' );
        expect( document.body.textContent ).toContain( 'network down' );
    } );
} );

/**
 * The record hook resolves a 500 and a missing row the same way, so the screens
 * that read it have to be told apart what the server said from what it failed
 * to say. Both live under a removed admin bar or a full-page shell, so a bare
 * sentence there is a dead end.
 */
describe( 'a record screen', () => {
    const record = { record: {}, savedRecord: null, edits: {}, isEdited: () => false,
        edit: () => {}, value: ( k, f = '' ) => f, bind: () => ( {} ), bindNumber: () => ( {} ),
        setValue: () => {}, save: async () => {}, discard: () => {}, hasEdits: false, isSaving: false,
        isLoading: false, notFound: false, loadError: null, reload: jest.fn() };

    beforeEach( () => {
        global.__record = null;
    } );

    it( 'offers a retry and a way back when the request failed', async () => {
        global.__record = { ...record, loadError: { message: 'gateway timeout' } };

        // eslint-disable-next-line global-require
        const Detail = require( '../../assets/admin/campaigns/Detail' ).default;
        mount( <Detail id={ 7 } tab="overview" /> );
        await settle();

        const labels = [ ...document.querySelectorAll( 'button, a' ) ].map( ( b ) => b.textContent.trim() );
        expect( document.body.textContent ).toContain( 'gateway timeout' );
        expect( document.body.textContent ).not.toContain( 'not found' );
        expect( labels ).toContain( 'Try again' );
        expect( labels ).toContain( 'Back to campaigns' );
    } );

    it( 'still says not found when the server says it is gone', async () => {
        global.__record = { ...record, notFound: true };

        // eslint-disable-next-line global-require
        const Detail = require( '../../assets/admin/campaigns/Detail' ).default;
        mount( <Detail id={ 7 } tab="overview" /> );
        await settle();

        expect( document.body.textContent ).toContain( 'Campaign not found.' );
    } );
} );
