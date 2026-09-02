/**
 * A saved list view has to survive the screen changing under it. The hard case
 * is telling a column the user HID apart from one that did not exist when they
 * saved: both are simply absent from the saved field list, and treating them
 * the same either un-hides columns people hid or hides columns they have never
 * seen.
 */

import { render } from 'preact';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

const mockApiFetch = jest.fn();
jest.mock( '@wordpress/api-fetch', () => ( { __esModule: true, default: ( ...a ) => mockApiFetch( ...a ) } ) );

import { useTableView } from '../../assets/admin/_shared/useTableView';

const apiFetch = mockApiFetch;

// No hook-testing library in this repo, so the hook is driven through a
// component the way it is used for real.
let current = null;

function Probe( { scope, defaults, known } ) {
	current = useTableView( scope, defaults, known );
	return null;
}

function renderHook( scope, defaults, known ) {
	current = null;
	document.body.innerHTML = '<div id="root"></div>';
	render( <Probe scope={ scope } defaults={ defaults } known={ known } />, document.getElementById( 'root' ) );
	return { result: { get current() { return current; } } };
}

// preact defers useEffect behind a frame, which under jsdom is a ~100ms
// timeout, so the fetch has not even been made until well after a microtask.
const settle = async () => {
	await new Promise( ( r ) => setTimeout( r, 150 ) );
	await Promise.resolve();
};

const KNOWN = [ 'reference', 'status', 'amount', 'gateway' ];

const DEFAULTS = {
	type: 'table',
	perPage: 25,
	page: 1,
	sort: { field: 'created_at', direction: 'desc' },
	filters: [],
	search: '',
	fields: KNOWN,
};

const flush = settle;

function load( saved, defaults = DEFAULTS, known = KNOWN ) {
	apiFetch.mockReset();
	apiFetch.mockResolvedValue( saved );

	return renderHook( 'donations', defaults, known );
}

describe( 'a saved list view', () => {
	it( 'uses the screen defaults when nothing is saved', async () => {
		const { result } = load( {} );
		await flush();

		expect( result.current[ 0 ].fields ).toEqual( KNOWN );
	} );

	it( 'restores the columns the user left', async () => {
		const { result } = load( { fields: [ 'reference', 'amount' ], known: KNOWN } );
		await flush();

		expect( result.current[ 0 ].fields ).toEqual( [ 'reference', 'amount' ] );
	} );

	it( 'keeps a hidden column hidden', async () => {
		const { result } = load( { fields: [ 'reference', 'amount' ], known: KNOWN } );
		await flush();

		expect( result.current[ 0 ].fields ).not.toContain( 'gateway' );
	} );

	/**
	 * The forward-compatibility rule: a column shipped after the view was saved
	 * is one the user has never had an opinion about, so it shows up.
	 */
	it( 'shows a column added since the view was saved', async () => {
		const { result } = load(
			{ fields: [ 'reference', 'amount' ], known: [ 'reference', 'status', 'amount' ] },
			DEFAULTS,
			[ ...KNOWN, 'campaign' ]
		);
		await flush();

		expect( result.current[ 0 ].fields ).toEqual( [ 'reference', 'amount', 'gateway', 'campaign' ] );
	} );

	it( 'drops a column the screen no longer has', async () => {
		const { result } = load( { fields: [ 'reference', 'retired_column' ], known: [ ...KNOWN, 'retired_column' ] } );
		await flush();

		expect( result.current[ 0 ].fields ).toEqual( [ 'reference' ] );
	} );

	it( 'restores sort, page size and filters', async () => {
		const { result } = load( {
			sort: { field: 'amount', direction: 'asc' },
			perPage: 50,
			filters: [ { field: 'status', operator: 'is', value: 'failed' } ],
		} );
		await flush();

		const view = result.current[ 0 ];
		expect( view.sort ).toEqual( { field: 'amount', direction: 'asc' } );
		expect( view.perPage ).toBe( 50 );
		expect( view.filters ).toEqual( [ { field: 'status', operator: 'is', value: 'failed' } ] );
	} );

	/**
	 * Arriving from a "3 failed donations" link has to show failed donations,
	 * whatever the reader was last filtering by.
	 */
	it( 'lets a filter from the link that opened the screen win', async () => {
		const fromUrl = { ...DEFAULTS, filters: [ { field: 'status', operator: 'is', value: 'failed' } ] };
		const { result } = load(
			{ filters: [ { field: 'status', operator: 'is', value: 'paid' } ] },
			fromUrl
		);
		await flush();

		expect( result.current[ 0 ].filters[ 0 ].value ).toBe( 'failed' );
	} );

	it( 'always opens on the first page', async () => {
		const { result } = load( { perPage: 50 }, { ...DEFAULTS, page: 4 } );
		await flush();

		expect( result.current[ 0 ].page ).toBe( 1 );
	} );

	/**
	 * The screens key their data fetch on the view object, so a no-op merge that
	 * hands back a new one costs a second request on every page load.
	 */
	it( 'keeps the same view object when nothing was saved', async () => {
		const { result } = load( {} );
		const before = result.current[ 0 ];
		await flush();

		expect( result.current[ 0 ] ).toBe( before );
	} );

	it( 'ignores a saved view that is not an object', async () => {
		const { result } = load( null );
		await flush();

		expect( result.current[ 0 ] ).toEqual( DEFAULTS );
	} );

	it( 'still shows the list when the saved view cannot be read', async () => {
		apiFetch.mockReset();
		apiFetch.mockRejectedValue( new Error( 'offline' ) );

		const { result } = renderHook( 'donations', DEFAULTS, KNOWN );
		await flush();

		expect( result.current[ 0 ] ).toEqual( DEFAULTS );
	} );
} );

describe( 'saving a list view', () => {
	it( 'does not write the screen defaults back before the load lands', async () => {
		apiFetch.mockReset();
		apiFetch.mockReturnValue( new Promise( () => {} ) );

		renderHook( 'donations', DEFAULTS, KNOWN );
		await flush();

		expect( apiFetch.mock.calls.filter( ( [ o ] ) => o.method === 'PUT' ) ).toHaveLength( 0 );
	} );

	it( 'records the columns the screen offered, so a later release can tell what is new', async () => {
		const { result } = load( {} );
		await flush();

		// Fake timers only once the load has settled, or the load never lands.
		jest.useFakeTimers();
		result.current[ 1 ]( { ...DEFAULTS, fields: [ 'reference' ] } );
		jest.runAllTimers();
		jest.useRealTimers();

		const put = apiFetch.mock.calls.map( ( [ o ] ) => o ).find( ( o ) => o.method === 'PUT' );
		expect( put.data.fields ).toEqual( [ 'reference' ] );
		expect( put.data.known ).toEqual( KNOWN );
	} );
} );
