
import { render } from 'preact';
import apiFetch from '@wordpress/api-fetch';

import Detail from '../../assets/admin/campaigns/Detail';

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

const captured = { grids: 0, order: null, reorder: null, hide: null };

jest.mock( '../../assets/admin/_shared/widgets/WidgetGrid', () => ( {
    __esModule: true,
    default: ( props ) => {
        captured.grids += 1;
        captured.order   = props.visibleOrder;
        captured.reorder = props.onReorder;
        captured.hide    = props.onHide;
        return <div data-grid="1" />;
    },
} ) );

// The record arrives through @wordpress/core-data's entity store, which is not
// what any of this is about.
jest.mock( '../../assets/admin/_shared/useGratoraRecord', () => ( {
    __esModule: true,
    useGratoraRecord: () => ( {
        record:      global.__campaign,
        savedRecord: global.__campaign,
        edits:       {},
        isLoading:   false,
        notFound:    false,
        isEdited:    () => false,
        edit:        () => {},
        value:       ( k, f = '' ) => global.__campaign[ k ] ?? f,
        bind:        () => ( {} ),
        bindNumber:  () => ( {} ),
        setValue:    () => {},
        save:        async () => {},
        discard:     () => {},
        hasEdits:    false,
        isSaving:    false,
    } ),
} ) );

jest.mock( '../../assets/admin/_shared/extensionTabs', () => ( {
    __esModule: true,
    useExtensionTabs: () => [],
    ExtensionTabPanel: () => null,
} ) );

const CAMPAIGN = {
    id: 7,
    title: 'Clean water',
    status: 'published',
    raised_cents: 5000000,
    goal_cents: 5000000,
    currency: 'USD',
    page_id: null,
};

let metricsCalls = [];
let metricsFails = false;
let metricsHeld  = false;
let releaseHeld  = [];
let savedLayout = { order: [], hidden: [] };

function serve() {
    metricsCalls = [];
    apiFetch.mockImplementation( ( { path } ) => {
        if ( path.includes( '/metrics' ) ) {
            metricsCalls.push( path );
            if ( metricsFails ) {
                return Promise.reject( new Error( 'gateway timeout' ) );
            }
            const answer = { amount_raised_cents: 4200, donations_count: 3 };
            if ( ! metricsHeld ) {
                return Promise.resolve( answer );
            }
            return new Promise( ( resolve ) => releaseHeld.push( () => resolve( answer ) ) );
        }
        if ( path.includes( '/admin/me/layout' ) ) {
            return Promise.resolve( savedLayout );
        }
        if ( path.includes( '/admin/campaigns/funds' ) || path.includes( '/admin/forms' ) ) {
            return Promise.resolve( [] );
        }
        if ( path.includes( '/admin/campaigns/7' ) ) {
            return Promise.resolve( CAMPAIGN );
        }
        return Promise.resolve( {} );
    } );
}

function mount() {
    serve();
    const root = document.createElement( 'div' );
    document.body.appendChild( root );
    render( <Detail id={ 7 } tab="overview" />, root );
    return root;
}

const settle = () => new Promise( ( resolve ) => setTimeout( resolve, 60 ) );

beforeEach( () => {
    captured.grids   = 0;
    captured.order   = null;
    captured.reorder = null;
    captured.hide    = null;
    global.__campaign = CAMPAIGN;
    metricsFails = false;
    metricsHeld  = false;
    releaseHeld  = [];
    savedLayout = { order: [], hidden: [] };
    apiFetch.mockReset();
    document.body.innerHTML = '';
} );

it( 'says nothing was measured rather than showing a measured zero', async () => {
    metricsFails = true;

    mount();
    await waitFor( () => metricsCalls.length > 0 );
    await settle();

    expect( document.querySelector( '[data-grid]' ) ).toBeNull();
    expect( document.body.textContent ).toContain( 'Could not load these metrics' );
    expect( document.body.textContent ).not.toContain( 'No donations yet' );
} );

it( 'asks again when told to', async () => {
    metricsFails = true;

    mount();
    await waitFor( () => metricsCalls.length > 0 );
    await settle();

    metricsFails = false;
    const retry = [ ...document.querySelectorAll( 'button' ) ]
        .find( ( b ) => b.textContent.trim() === 'Try again' );
    expect( retry ).toBeDefined();
    retry.click();
    await settle();

    expect( metricsCalls.length ).toBe( 2 );
    expect( document.querySelector( '[data-grid]' ) ).not.toBeNull();
} );

it( 'asks once on load, and only for the widgets this reader kept', async () => {
    savedLayout = { order: [ 'revenue', 'kpis' ], hidden: [ 'gateway', 'top-donors' ] };

    mount();
    await waitFor( () => metricsCalls.length > 0 );
    await settle();

    expect( metricsCalls ).toHaveLength( 1 );

    const include = decodeURIComponent(
        metricsCalls[ 0 ].match( /include=([^&]*)/ )[ 1 ]
    ).split( ',' );
    expect( include ).toContain( 'revenue' );
    expect( include ).not.toContain( 'gateway' );
    expect( include ).not.toContain( 'top-donors' );
} );

it( 'does not re-run it because a widget moved or went away', async () => {
    mount();
    await waitFor( () => metricsCalls.length > 0 );
    await settle();

    const before = metricsCalls.length;
    expect( captured.order.length ).toBeGreaterThan( 2 );

    // A drag: same widgets, different sequence. The server tests membership.
    captured.reorder( 0, 2 );
    await settle();
    expect( metricsCalls.length ).toBe( before );

    // Hiding one asks for a subset of what is already in hand.
    captured.hide( captured.order[ 0 ] );
    await settle();
    expect( metricsCalls.length ).toBe( before );
} );

/**
 * Hiding a widget while the first request is still out abandons that request.
 * Booking its widgets as held before it landed left the re-run believing it
 * already had them, so the tab kept its loading state and never measured
 * anything at all.
 */
it( 'still measures the campaign when a widget is hidden mid-load', async () => {
    metricsHeld = true;

    mount();
    await waitFor( () => metricsCalls.length > 0 );
    await settle();

    captured.hide( captured.order[ 0 ] );
    await settle();

    metricsHeld = false;
    releaseHeld.forEach( ( release ) => release() );
    await settle();

    expect( metricsCalls.length ).toBeGreaterThan( 1 );
    expect( document.querySelector( '[data-loading="true"]' ) ).toBeNull();
} );

/**
 * The header menu already refused a delete the server refuses and offered
 * Archive instead. The Danger zone on Settings > Advanced called the same route
 * with no such check, so a campaign with any donation row read "This cannot be
 * undone", then 422'd.
 */
describe( 'the danger zone', () => {
    const openAdvanced = async ( campaign ) => {
        global.__campaign = campaign;
        serve();

        const root = document.createElement( 'div' );
        document.body.appendChild( root );
        render( <Detail id={ 7 } tab="settings" />, root );
        await settle();

        const advanced = [ ...document.querySelectorAll( 'button' ) ]
            .find( ( b ) => b.textContent.trim() === 'Advanced' );
        if ( advanced ) {
            advanced.click();
            await settle();
        }

        const del = [ ...document.querySelectorAll( 'button' ) ]
            .find( ( b ) => b.textContent.trim() === 'Delete campaign' );
        expect( del ).toBeDefined();
        del.click();
        await settle();
    };

    it( 'refuses a delete the server refuses, and offers the archive instead', async () => {
        await openAdvanced( {
            ...CAMPAIGN,
            delete_blocked: 'This campaign has donations, so it cannot be deleted.',
        } );

        expect( document.body.textContent ).toContain( 'This campaign cannot be deleted' );
        expect( document.body.textContent ).toContain( 'so it cannot be deleted' );
        expect( document.body.textContent ).toContain( 'Archive instead' );
        expect( document.body.textContent ).not.toContain( 'This cannot be undone' );
    } );

    it( 'still offers the delete on a campaign the server would accept', async () => {
        await openAdvanced( { ...CAMPAIGN, delete_blocked: null } );

        expect( document.body.textContent ).toContain( 'This cannot be undone' );
        expect( document.body.textContent ).not.toContain( 'Archive instead' );
    } );
} );
