/**
 * Two things the campaigns list said that the rows beside them did not.
 *
 * A badge reads Active only while a campaign is accepting, because it renders
 * not_accepting || status, and the Active figure above the table counts the
 * same thing. The filter asked the stored column instead, so picking Active
 * returned five rows where the figure said four, one of them badged Ended
 * under a chip reading "Status is: Active".
 *
 * And Delete was withheld by re-deriving the server's rule from the donation
 * count. The server also refuses on a recurring plan, and it has a sentence
 * naming archiving as the way to keep the records. Withheld, the action
 * vanished from every row of a site whose campaigns all had donations, and
 * the sentence was unreachable.
 */

import { render } from 'preact';
import apiFetch from '@wordpress/api-fetch';

const { waitFor } = require( './support/waitFor' );

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

jest.mock( '../../assets/admin/_shared/notify', () => ( {
    __esModule: true,
    default: { success: jest.fn(), error: jest.fn(), info: jest.fn() },
} ) );

const captured = { fields: null, actions: null };

jest.mock( '@wordpress/dataviews', () => ( {
    DataViews: ( props ) => {
        captured.fields  = props.fields;
        captured.actions = props.actions;
        return null;
    },
} ) );

import List from '../../assets/admin/campaigns/List';

const HELD = {
    id: 3, title: 'Annual Fund', slug: 'annual-fund', status: 'published',
    not_accepting: null, goal_type: 'amount', goal_cents: 4500000,
    raised_cents: 316129500, donations_count: 17363, donors_count: 4898,
    forms_count: 3, deletable: false,
    delete_blocked: 'This campaign has donations and cannot be deleted. Archive it instead to keep its records.',
};

const FREE = {
    ...HELD, id: 9, title: 'Brand New', slug: 'brand-new',
    donations_count: 0, donors_count: 0, raised_cents: 0,
    deletable: true, delete_blocked: null,
};

async function mount( items ) {
    captured.fields = null;
    captured.actions = null;
    document.body.innerHTML = '<div id="root"></div>';

    apiFetch.mockReset();
    apiFetch.mockImplementation( ( { parse } ) => {
        if ( parse === false ) {
            return Promise.resolve( { json: async () => items, headers: { get: () => String( items.length ) } } );
        }
        return Promise.resolve( items );
    } );

    window.gratora = { can: { manage_campaigns: true, manage_options: true, view_donations: true } };

    render( <List />, document.getElementById( 'root' ) );
    await waitFor( () => !! captured.actions, { what: 'the list to register its actions' } );
}

test( 'the Active filter asks for the state the badge shows', async () => {
    await mount( [ HELD ] );

    const status  = captured.fields.find( ( f ) => f.id === 'status' );
    const byLabel = Object.fromEntries( status.elements.map( ( e ) => [ e.label, e.value ] ) );

    expect( byLabel.Active ).toBe( 'accepting' );
    // The stored column, which returns campaigns badged Ended.
    expect( Object.values( byLabel ) ).not.toContain( 'published' );
} );

/** The derived states stay filterable: a badge you cannot filter by is a dead end. */
test( 'every state a badge can show is still offered', async () => {
    await mount( [ HELD ] );

    const status = captured.fields.find( ( f ) => f.id === 'status' );
    const values = status.elements.map( ( e ) => e.value );

    for ( const state of [ 'draft', 'archived', 'scheduled', 'ended', 'goal_met' ] ) {
        expect( values ).toContain( state );
    }
} );

test( 'delete is still offered on a campaign that cannot be deleted', async () => {
    await mount( [ HELD ] );

    const del = captured.actions.find( ( a ) => a.id === 'delete' );

    expect( del.isEligible( HELD ) ).toBe( true );
} );

test( 'and choosing it says why, naming what to do instead', async () => {
    await mount( [ HELD ] );

    captured.actions.find( ( a ) => a.id === 'delete' ).callback( [ HELD ] );
    await waitFor(
        () => /This campaign cannot be deleted/i.test( document.body.textContent || '' ),
        { what: 'the refusal to be shown' }
    );

    const text = document.body.textContent || '';

    expect( text ).toContain( 'Archive it instead' );
    // A refusal is not a decision, so it offers one way out and not a Cancel.
    expect( text ).not.toContain( 'Cancel' );
    expect( text ).not.toContain( 'Permanently delete' );
} );

test( 'a campaign that can be deleted still goes to the confirmation', async () => {
    await mount( [ FREE ] );

    captured.actions.find( ( a ) => a.id === 'delete' ).callback( [ FREE ] );
    await waitFor(
        () => /Permanently delete/i.test( document.body.textContent || '' ),
        { what: 'the confirmation to be shown' }
    );

    const text = document.body.textContent || '';

    // The confirm, which has a decision in it, rather than the refusal.
    expect( text ).toContain( 'Cancel' );
    expect( text ).not.toContain( 'This campaign cannot be deleted' );
} );

/**
 * The rule is the server's, and the row carries its answer rather than a count
 * to redo it from. These two are the cases where the count and the verdict
 * disagree, so a client still deciding from donations_count fails them.
 */
test( 'a campaign held by a recurring plan alone is refused, though it has no donations', async () => {
    const planOnly = { ...FREE, donations_count: 0, deletable: false, delete_blocked: HELD.delete_blocked };
    await mount( [ planOnly ] );

    captured.actions.find( ( a ) => a.id === 'delete' ).callback( [ planOnly ] );
    await waitFor(
        () => /This campaign cannot be deleted/i.test( document.body.textContent || '' ),
        { what: 'the refusal to be shown' }
    );

    expect( document.body.textContent ).toContain( 'Archive it instead' );
} );

test( 'and one the server will delete is offered, whatever its donation count says', async () => {
    const stale = { ...HELD, donations_count: 17363, deletable: true, delete_blocked: null };
    await mount( [ stale ] );

    const del = captured.actions.find( ( a ) => a.id === 'delete' );

    expect( del.isEligible( stale ) ).toBe( true );
} );
