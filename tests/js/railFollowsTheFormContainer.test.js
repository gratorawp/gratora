/**
 * The published page paints the card (--gratora-bg) behind a form only when
 * that form is framed. The campaign rail previews the campaign's default form,
 * so it frames the preview exactly when that form is framed, and otherwise
 * shows it on the page the way a Plain form stands.
 */

import { render } from 'preact';
import apiFetch from '@wordpress/api-fetch';

const { waitFor } = require( './support/waitFor' );

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

jest.mock( '../../assets/admin/_shared/useGratoraRecord', () => ( {
    __esModule: true,
    useGratoraRecord: () => global.__record,
} ) );

jest.mock( '../../assets/admin/_shared/extensionTabs', () => ( {
    __esModule: true,
    useExtensionTabs: () => [],
    ExtensionTabPanel: () => null,
} ) );

import Detail from '../../assets/admin/campaigns/Detail';

const FORMS = [
    { id: 21, title: 'Framed', status: 'published', campaign_id: 7, settings: { container: { style: 'frame', width: 540 } } },
    { id: 22, title: 'Plain',  status: 'published', campaign_id: 7, settings: { container: { style: 'plain', width: 540 } } },
    { id: 23, title: 'Older',  status: 'published', campaign_id: 7 },
];

function stubRecord( defaultFormId ) {
    const campaign = {
        id: 7, title: 'Clean water', status: 'published', raised_cents: 0, goal_cents: 500000,
        currency: 'USD', page_id: null, style: null, default_form_id: defaultFormId,
    };

    global.__record = {
        record: campaign,
        savedRecord: campaign,
        edits: {},
        isLoading: false,
        notFound: false,
        isEdited: () => false,
        edit: () => {},
        value: ( k, f = '' ) => campaign[ k ] ?? f,
        bind: () => ( {} ),
        bindNumber: () => ( {} ),
        setValue: () => {},
        save: async () => {},
        discard: () => {},
        isDirty: false,
        isSaving: false,
        reload: () => {},
    };
}

beforeEach( () => {
    apiFetch.mockImplementation( ( { path } ) => {
        if ( path.startsWith( '/gratora/v1/admin/forms?' ) ) return Promise.resolve( FORMS );
        return Promise.resolve( [] );
    } );
    document.body.innerHTML = '<div id="root"></div>';
    window.gratora = { can: { manage_campaigns: true, manage_options: true }, styling: {} };
} );

afterEach( () => { delete window.gratora; delete global.__record; } );

async function railForm( defaultFormId ) {
    stubRecord( defaultFormId );
    render( <Detail id={ 7 } tab="settings" />, document.getElementById( 'root' ) );
    await waitFor( () => apiFetch.mock.calls.some( ( [ { path } ] ) => path.startsWith( '/gratora/v1/admin/forms?' ) ), { what: 'the forms request' } );
    await new Promise( ( r ) => setTimeout( r, 20 ) );

    return document.querySelector( '.gratora-settings-layout__rail .gratora-style-preview__form' );
}

it( 'frames the preview when the default form is framed', async () => {
    const form = await railForm( 21 );

    await waitFor( () => form.classList.contains( 'is-framed' ), { what: 'the framed preview' } );
    expect( form.classList.contains( 'is-framed' ) ).toBe( true );
} );

it( 'stands it on the page when the default form is Plain', async () => {
    expect( ( await railForm( 22 ) ).classList.contains( 'is-framed' ) ).toBe( false );
} );

it( 'stands it on the page when the form says nothing about its container', async () => {
    expect( ( await railForm( 23 ) ).classList.contains( 'is-framed' ) ).toBe( false );
} );
