/**
 * Delete on a donor row is refused by the server for any donation row at all,
 * whatever its status. The stored counters on the row are paid money only, so
 * the screen cannot answer this itself: it has to carry the server's answer or
 * it offers an action that can only end in a refusal.
 */

import { render } from 'preact';

import apiFetch from '@wordpress/api-fetch';

import { DonorsApp } from '../../assets/admin/donors/index';
const { waitFor } = require( './support/waitFor' );

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

const captured = { actions: null };

jest.mock( '@wordpress/dataviews', () => ( {
    DataViews: ( props ) => {
        captured.actions = props.actions;
        return null;
    },
} ) );

// Neither is reachable from the list, and both pull in @wordpress/components,
// which does not load under the preact alias.
jest.mock( '../../assets/admin/donors/DonorProfile', () => ( { __esModule: true, default: () => null } ) );
// The confirmation carries both halves of a bulk action: the sentence the
// operator is shown, and the callback that fires the requests.
jest.mock( '../../assets/admin/_shared/components/ConfirmDialog', () => ( {
    __esModule: true,
    default: ( { confirm } ) => {
        if ( confirm ) global.__confirm = confirm;
        return null;
    },
} ) );
jest.mock( '../../assets/admin/donors/Insights', () => ( { __esModule: true, default: () => null } ) );


const rows = [
    // Left behind by an attempt that never completed: no paid donation, not a
    // test row, and the server keeps them anyway.
    { id: 1, name: 'Abandoned', email: 'a@example.test', donations_count: 0, is_test_only: false, deletable: false, redacted: false },
    { id: 2, name: 'Never gave', email: 'b@example.test', donations_count: 0, is_test_only: false, deletable: true, redacted: false },
];

beforeEach( () => {
    apiFetch.mockReset();
    apiFetch.mockImplementation( ( { path, parse } ) => {
        if ( parse === false ) {
            return Promise.resolve( { json: async () => rows, headers: { get: () => '2' } } );
        }
        if ( path.startsWith( '/fundkit/v1/admin/donors/stats' ) ) {
            return Promise.resolve( null );
        }
        return Promise.resolve( {} );
    } );
} );

let root = null;

async function mountList() {
    // Unmounted, not just detached: a tree left mounted goes on re-rendering
    // its own state, and the confirmation it still holds lands in __confirm
    // after the next test has cleared it.
    if ( root ) render( null, root );

    document.body.innerHTML = '<div id="root"></div>';
    root = document.getElementById( 'root' );
    render( <DonorsApp />, root );
    await waitFor( () => !! captured.actions, { what: 'the list to register its actions' } );
}

test( 'a donor the server keeps is not offered Delete', async () => {
    await mountList();

    const del = captured.actions.find( ( a ) => a.id === 'delete' );

    expect( del.isEligible( rows[ 0 ] ) ).toBe( false );
    expect( del.isEligible( rows[ 1 ] ) ).toBe( true );
} );

describe( 'a bulk action acts only on the rows it was offered for', () => {
	// DataViews hands a bulk callback the WHOLE selection and uses isEligible
	// only to decide whether the button is drawn, so the filter has to be
	// repeated inside the callback or the sentence miscounts and the requests
	// reach rows the gate exists to exclude.
	const REDACTED  = { ...rows[ 1 ], id: 3, name: 'Already redacted', redacted: true };
	const UNDELETABLE = rows[ 0 ];
	const DELETABLE   = rows[ 1 ];

	const fire = async ( id, selection ) => {
		await mountList();
		global.__confirm = null;

		const action = captured.actions.find( ( a ) => a.id === id );
		expect( action.supportsBulk ).toBe( true );
		action.callback( selection );

		// setConfirm re-renders on a microtask, so the dialog has not been
		// handed its props yet at this point.
		await new Promise( ( r ) => setTimeout( r, 0 ) );

		if ( ! global.__confirm ) return { asked: null, paths: [] };

		const asked = global.__confirm;
		apiFetch.mockClear();
		await asked.onConfirm();

		return {
			asked,
			paths: apiFetch.mock.calls
				.map( ( [ args ] ) => `${ args.method || 'GET' } ${ args.path }` )
				.filter( ( p ) => /donors\/\d/.test( p ) ),
		};
	};

	test( 'Delete requests only the donors it is allowed to delete', async () => {
		const { paths } = await fire( 'delete', [ UNDELETABLE, DELETABLE ] );

		expect( paths ).toEqual( [ `DELETE /fundkit/v1/admin/donors/${ DELETABLE.id }` ] );
	} );

	test( 'and says how many that actually is', async () => {
		const { asked } = await fire( 'delete', [ UNDELETABLE, DELETABLE ] );

		expect( asked ).toBeTruthy();
		expect( asked.message ).not.toMatch( /2 donors/ );
	} );

	test( 'a selection with nothing eligible raises no confirmation at all', async () => {
		const { asked, paths } = await fire( 'delete', [ UNDELETABLE ] );

		expect( asked ).toBeNull();
		expect( paths ).toEqual( [] );
	} );

	test( 'Redact skips a donor who is already redacted', async () => {
		const { paths } = await fire( 'redact', [ REDACTED, DELETABLE ] );

		expect( paths ).toHaveLength( 1 );
		expect( paths[ 0 ] ).toContain( `/donors/${ DELETABLE.id }` );
	} );
} );
