/**
 * A fund queued for reassignment is hard-deleted the moment the job finishes,
 * and the job never carries parent_fund_id along, so a sub-fund parented onto
 * one is left pointing at a row that no longer exists. The server refuses it;
 * the editor must not offer it either.
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

const mockSelects = [];

jest.mock( '../../assets/admin/_shared/components/SearchableSelect', () => ( {
    __esModule: true,
    default: ( props ) => {
        mockSelects.push( props );
        return null;
    },
} ) );

const captured = { actions: null };

jest.mock( '@wordpress/dataviews', () => ( {
    DataViews: ( props ) => {
        captured.actions = props.actions;
        return null;
    },
} ) );

const BASE = {
    is_active: true,
    is_default: false,
    is_restricted: false,
    raised_cents: 0,
    goal_cents: null,
    starts_at: null,
    ends_at: null,
    schedule_state: null,
    deletable: true,
    has_children: false,
    parent_fund_id: null,
    reassign_pending: false,
};

const GOING   = { ...BASE, id: 1, code: 'building', name: 'Building', is_active: false, reassign_pending: true };
const STAYING = { ...BASE, id: 2, code: 'general',  name: 'General' };
const EDITED  = { ...BASE, id: 3, code: 'water',    name: 'Water' };

const FUNDS = [ GOING, STAYING, EDITED ];

const settle = () => new Promise( ( resolve ) => setTimeout( resolve, 40 ) );

function mount() {
    apiFetch.mockImplementation( ( { path, parse } ) => {
        if ( path.startsWith( '/fundkit/v1/admin/me/table-view' ) ) return Promise.resolve( {} );
        if ( path.startsWith( '/fundkit/v1/admin/funds/stats' ) )    return Promise.resolve( {} );
        if ( parse === false ) {
            return Promise.resolve( { json: async () => FUNDS, headers: { get: () => '3' } } );
        }
        return Promise.resolve( FUNDS );
    } );

    const root = document.createElement( 'div' );
    document.body.appendChild( root );
    render( <List />, root );
}

beforeEach( () => {
    captured.actions = null;
    mockSelects.length = 0;
    apiFetch.mockReset();
    document.body.innerHTML = '';
} );

async function parentOptions() {
    mount();
    await waitFor( () => !! captured.actions );
    await settle();

    captured.actions.find( ( a ) => a.id === 'edit' ).callback( [ EDITED ] );
    await settle();

    const picker = mockSelects.find( ( p ) =>
        ( p.options || [] ).some( ( o ) => o.label === 'General' || o.label === 'Building' )
    );
    expect( picker ).toBeDefined();

    return picker.options.map( ( o ) => o.label );
}

it( 'offers a fund that is staying as a parent', async () => {
    expect( await parentOptions() ).toContain( 'General' );
} );

it( 'does not offer a fund that is being reassigned away', async () => {
    expect( await parentOptions() ).not.toContain( 'Building' );
} );
