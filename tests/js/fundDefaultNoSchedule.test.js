/**
 * The default fund is where a donation lands when nothing else claims it, so a
 * schedule on it is a date after which those donations stop arriving: the fund
 * reads closed, the resolver skips it, and untagged money is filed against
 * whichever other fund happens to sort first. The server refuses the pairing,
 * so the editor must not offer it.
 */

import { render } from 'preact';
import apiFetch from '@wordpress/api-fetch';

import List from '../../assets/admin/funds/List';

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

const captured = { actions: null };

jest.mock( '@wordpress/dataviews', () => ( {
    DataViews: ( props ) => {
        captured.actions = props.actions;
        return null;
    },
} ) );

const SCHEDULED = {
    id: 1,
    code: 'winter',
    name: 'Winter appeal',
    is_active: true,
    is_default: false,
    is_restricted: false,
    raised_cents: 0,
    goal_cents: null,
    starts_at: '2026-11-01',
    ends_at: '2026-12-31',
    schedule_state: null,
    deletable: true,
    has_children: false,
    reassign_pending: false,
};

const PLAIN = { ...SCHEDULED, id: 2, code: 'general', name: 'General', starts_at: null, ends_at: null };

let posted = [];

function mount() {
    posted = [];
    apiFetch.mockImplementation( ( { path, method, parse, data } ) => {
        if ( method === 'POST' ) {
            posted.push( { path, data } );
            return Promise.resolve( {} );
        }
        if ( path.startsWith( '/gratora/v1/admin/me/table-view' ) ) {
            return Promise.resolve( {} );
        }
        if ( path.startsWith( '/gratora/v1/admin/funds/stats' ) ) {
            return Promise.resolve( {} );
        }
        if ( parse === false ) {
            return Promise.resolve( {
                json: async () => [ SCHEDULED, PLAIN ],
                headers: { get: () => '2' },
            } );
        }
        return Promise.resolve( [ SCHEDULED, PLAIN ] );
    } );

    const root = document.createElement( 'div' );
    document.body.appendChild( root );
    render( <List />, root );
    return root;
}

const settle = () => new Promise( ( resolve ) => setTimeout( resolve, 40 ) );

beforeEach( () => {
    captured.actions = null;
    notified.success = [];
    notified.error = [];
    apiFetch.mockReset();
    document.body.innerHTML = '';
} );

async function openEditor( fund ) {
    mount();
    await waitFor( () => !! captured.actions );
    await settle();

    captured.actions.find( ( a ) => a.id === 'edit' ).callback( [ fund ] );
    await settle();

    const dialog = document.querySelector( '.gratora-dialog' );
    expect( dialog ).not.toBeNull();
    return dialog;
}

const scheduleSwitch = ( dialog ) =>
    dialog.querySelector( '.gratora-sched__toggle-row input[type="checkbox"]' );

const defaultSwitch = ( dialog ) => {
    const row = [ ...dialog.querySelectorAll( '.gratora-toggle-row' ) ]
        .find( ( r ) => r.textContent.includes( 'Default fund' ) );
    return row.querySelector( 'input[type="checkbox"]' );
};

// DateField renders a picker trigger rather than a bare input, so the date row
// itself is what says whether a schedule is on offer.
const dateFields = ( dialog ) => dialog.querySelectorAll( '.gratora-sched__dates .gratora-date-field' );

it( 'offers a schedule on an ordinary fund', async () => {
    const dialog = await openEditor( SCHEDULED );

    expect( scheduleSwitch( dialog ).disabled ).toBe( false );
    expect( dateFields( dialog ) ).toHaveLength( 2 );
} );

it( 'does not offer one on the default fund, and says why', async () => {
    const dialog = await openEditor( { ...PLAIN, is_default: true } );

    expect( scheduleSwitch( dialog ).disabled ).toBe( true );
    expect( dateFields( dialog ) ).toHaveLength( 0 );
    expect( dialog.textContent ).toContain( 'so it stays open' );
} );

it( 'drops the window when a scheduled fund is made the default in the editor', async () => {
    const dialog = await openEditor( SCHEDULED );

    expect( dateFields( dialog ) ).toHaveLength( 2 );

    defaultSwitch( dialog ).click();
    await settle();

    expect( scheduleSwitch( dialog ).disabled ).toBe( true );
    expect( dateFields( dialog ) ).toHaveLength( 0 );

    const save = [ ...dialog.querySelectorAll( 'button' ) ]
        .find( ( b ) => /save|create/i.test( b.textContent.trim() ) );
    save.click();
    await settle();

    expect( posted ).toHaveLength( 1 );
    expect( posted[ 0 ].data.is_default ).toBe( true );
    expect( posted[ 0 ].data.starts_at ).toBeNull();
    expect( posted[ 0 ].data.ends_at ).toBeNull();
} );

it( 'names the dates it is about to clear', async () => {
    mount();
    await waitFor( () => !! captured.actions );
    await settle();

    await captured.actions.find( ( a ) => a.id === 'set-default' ).callback( [ SCHEDULED ] );
    await settle();

    const dialog = document.querySelector( '.gratora-dialog' );
    expect( dialog.textContent ).toContain( '2026-11-01' );
    expect( dialog.textContent ).toContain( '2026-12-31' );
} );

/**
 * A site always has a default and the server refuses to clear the flag, so the
 * control that asks is a round trip the reader cannot win.
 */
it( 'does not offer to un-default the fund that is already the default', async () => {
    const dialog = await openEditor( { ...PLAIN, is_default: true } );

    expect( defaultSwitch( dialog ).disabled ).toBe( true );
    expect( dialog.textContent ).toContain( 'Promote another fund to move it' );
} );

it( 'still offers the toggle on a fund that is not the default', async () => {
    const dialog = await openEditor( PLAIN );

    expect( defaultSwitch( dialog ).disabled ).toBe( false );
} );

it( 'gives the schedule back when the default is turned off mid-edit', async () => {
    // A fund being promoted in this session, not one that arrived as the
    // default: that one's toggle is locked.
    const dialog = await openEditor( PLAIN );

    defaultSwitch( dialog ).click();
    await settle();
    expect( scheduleSwitch( dialog ).disabled ).toBe( true );

    defaultSwitch( dialog ).click();
    await settle();
    expect( scheduleSwitch( dialog ).disabled ).toBe( false );
} );

it( 'asks before the row action clears a schedule, and sends the clear itself', async () => {
    mount();
    await waitFor( () => !! captured.actions );
    await settle();

    await captured.actions.find( ( a ) => a.id === 'set-default' ).callback( [ SCHEDULED ] );
    await settle();

    // The server refuses the pairing, so nothing goes out until the reader
    // agrees to lose the dates.
    expect( posted ).toHaveLength( 0 );

    const dialog = document.querySelector( '.gratora-dialog' );
    expect( dialog ).not.toBeNull();
    expect( dialog.textContent ).toContain( 'clears those dates' );

    [ ...dialog.querySelectorAll( '.gratora-dialog__foot button' ) ].pop().click();
    await settle();

    expect( posted[ 0 ].data ).toEqual( { is_default: true, starts_at: null, ends_at: null } );
    expect( notified.success.join( ' ' ) ).toContain( 'schedule was cleared' );
} );

it( 'leaves the dates alone when the reader cancels', async () => {
    mount();
    await waitFor( () => !! captured.actions );
    await settle();

    await captured.actions.find( ( a ) => a.id === 'set-default' ).callback( [ SCHEDULED ] );
    await settle();

    const dialog = document.querySelector( '.gratora-dialog' );
    [ ...dialog.querySelectorAll( '.gratora-dialog__foot button' ) ].shift().click();
    await settle();

    expect( posted ).toHaveLength( 0 );
} );

it( 'asks nothing when the promoted fund has no schedule', async () => {
    mount();
    await waitFor( () => !! captured.actions );
    await settle();

    await captured.actions.find( ( a ) => a.id === 'set-default' ).callback( [ PLAIN ] );
    await settle();

    expect( document.querySelector( '.gratora-dialog' ) ).toBeNull();
    expect( posted[ 0 ].data ).toEqual( { is_default: true } );
} );

/**
 * A fund promoted before the rule existed still carries its window on the row
 * the form is seeded from, so the save has to drop it or every later edit of
 * the default fund is refused.
 */
it( 'never saves a window for the default fund', async () => {
    const dialog = await openEditor( { ...SCHEDULED, is_default: true } );

    const save = [ ...dialog.querySelectorAll( 'button' ) ]
        .find( ( b ) => /save|create/i.test( b.textContent.trim() ) );
    save.click();
    await settle();

    expect( posted[ 0 ].data.starts_at ).toBeNull();
    expect( posted[ 0 ].data.ends_at ).toBeNull();
} );
