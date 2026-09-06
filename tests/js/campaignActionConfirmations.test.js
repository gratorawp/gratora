/**
 * Publish, archive and restore each raise a toast and then reloaded the
 * document. The toast is a message in an in-memory store, so the reload threw
 * it away before it painted: the admin saw the page blink and nothing else.
 *
 * The archive case is the one that costs something. Its confirmation is the
 * only place the number of subscriptions being cancelled in the background is
 * ever said, and it went with the reload.
 */

import { render } from 'preact';
import apiFetch from '@wordpress/api-fetch';

const { waitFor } = require( './support/waitFor' );

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

const notified = { success: [], error: [] };

jest.mock( '../../assets/admin/_shared/notify', () => ( {
    __esModule: true,
    notify:  { success: ( m ) => notified.success.push( m ), error: ( m ) => notified.error.push( m ), info: () => {} },
    default: { success: ( m ) => notified.success.push( m ), error: ( m ) => notified.error.push( m ), info: () => {} },
} ) );

jest.mock( '../../assets/admin/_shared/widgets/WidgetGrid', () => ( {
    __esModule: true,
    default: () => <div data-grid="1" />,
} ) );

jest.mock( '../../assets/admin/_shared/useFundKitRecord', () => ( {
    __esModule: true,
    useFundKitRecord: () => global.__record,
} ) );

jest.mock( '../../assets/admin/_shared/extensionTabs', () => ( {
    __esModule: true,
    useExtensionTabs: () => [],
    ExtensionTabPanel: () => null,
} ) );

import Detail from '../../assets/admin/campaigns/Detail';

const CAMPAIGN = {
    id: 7,
    title: 'Clean water',
    status: 'draft',
    raised_cents: 0,
    goal_cents: 500000,
    currency: 'USD',
    page_id: null,
};

let reloads;

function stubRecord( overrides = {} ) {
    const campaign = { ...CAMPAIGN, ...overrides };

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
        hasEdits: false,
        isSaving: false,
        reload: () => { reloads.record += 1; },
    };
}

const button = ( label ) => [ ...document.querySelectorAll( 'button' ) ]
    .find( ( b ) => b.textContent.trim() === label );

beforeEach( () => {
    notified.success = [];
    notified.error   = [];
    reloads = { record: 0, document: 0 };

    // The browser reload is what threw the toast away, so it has to be visible.
    delete window.location;
    window.location = { href: '', reload: () => { reloads.document += 1; } };

    apiFetch.mockImplementation( ( { path } ) => {
        if ( path.includes( '/metrics' ) )  return Promise.resolve( {} );
        if ( path.includes( '/me/layout' ) ) return Promise.resolve( {} );

        return Promise.resolve( { recurring_cancel: { queued: 42 } } );
    } );

    document.body.innerHTML = '<div id="root"></div>';
    window.fundkit = { can: { manage_campaigns: true, manage_options: true } };
} );

afterEach( () => { delete window.fundkit; delete global.__record; } );

async function mount( overrides ) {
    stubRecord( overrides );
    render( <Detail id={ 7 } />, document.getElementById( 'root' ) );
    await new Promise( ( r ) => setTimeout( r, 60 ) );

    // Publish and Archive live behind the overflow menu.
    const overflow = [ ...document.querySelectorAll( 'button' ) ].find( ( b ) => /⋯/.test( b.textContent ) );
    if ( overflow ) {
        overflow.click();
        await new Promise( ( r ) => setTimeout( r, 60 ) );
    }

}

it( 'keeps the publish confirmation on screen', async () => {
    await mount();

    button( 'Publish campaign' ).click();
    await waitFor( () => notified.success.length > 0, 'the confirmation' );

    expect( notified.success[ 0 ] ).toContain( 'published' );
    expect( reloads.document ).toBe( 0 );
    expect( reloads.record ).toBeGreaterThan( 0 );
} );

/**
 * The count of subscriptions being cancelled in the background is said once,
 * here, and nowhere else on the site.
 */
it( 'keeps the count of subscriptions it is cancelling', async () => {
    await mount( { status: 'published' } );

    button( 'Archive campaign' ).click();
    await new Promise( ( r ) => setTimeout( r, 60 ) );

    // The archive asks first.
    const confirm = [ ...document.querySelectorAll( 'button' ) ]
        .find( ( b ) => /^(Archive|Archive campaign)$/.test( b.textContent.trim() ) && b.className.includes( 'is-destructive' ) )
        || [ ...document.querySelectorAll( 'button' ) ].reverse().find( ( b ) => /Archive/.test( b.textContent ) );
    if ( confirm ) confirm.click();

    await waitFor( () => notified.success.length > 0, 'the confirmation' );

    expect( notified.success[ 0 ] ).toContain( '42' );
    expect( reloads.document ).toBe( 0 );
} );
