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

// A state change re-renders on a microtask; nothing here waits on an effect.
const act = async ( fn ) => {
	await fn();
	await Promise.resolve();
	await Promise.resolve();
};

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
		const { result } = load( { fields: [ 'reference', 'amount' ], order: KNOWN } );
		await flush();

		expect( result.current[ 0 ].fields ).toEqual( [ 'reference', 'amount' ] );
	} );

	it( 'keeps a hidden column hidden', async () => {
		const { result } = load( { fields: [ 'reference', 'amount' ], order: KNOWN } );
		await flush();

		expect( result.current[ 0 ].fields ).not.toContain( 'gateway' );
	} );

	/**
	 * The forward-compatibility rule: a column shipped after the view was saved
	 * is one the user has never had an opinion about, so it shows up.
	 */
	it( 'shows a column added since the view was saved', async () => {
		const { result } = load(
			{ fields: [ 'reference', 'amount' ], order: [ 'reference', 'status', 'amount' ] },
			DEFAULTS,
			[ ...KNOWN, 'campaign' ]
		);
		await flush();

		expect( result.current[ 0 ].fields ).toEqual( [ 'reference', 'amount', 'gateway', 'campaign' ] );
	} );

	it( 'drops a column the screen no longer has', async () => {
		const { result } = load( { fields: [ 'reference', 'retired_column' ], order: [ ...KNOWN, 'retired_column' ] } );
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

	it( 'records the whole arrangement, so a later release can tell what is new', async () => {
		const { result } = load( {} );
		await flush();

		// Fake timers only once the load has settled, or the load never lands.
		jest.useFakeTimers();
		result.current[ 1 ]( { ...DEFAULTS, fields: [ 'reference' ] } );
		jest.runAllTimers();
		jest.useRealTimers();

		const put = apiFetch.mock.calls.map( ( [ o ] ) => o ).find( ( o ) => o.method === 'PUT' );
		expect( put.data.fields ).toEqual( [ 'reference' ] );
		expect( put.data.order ).toEqual( KNOWN );
	} );
} );

describe( 'column position', () => {
	const hide = async ( result, id ) => {
		const v = result.current[ 0 ];
		await act( () => result.current[ 1 ]( { ...v, fields: v.fields.filter( ( f ) => f !== id ) } ) );
	};

	// dataviews appends a re-shown column to the end of view.fields; it keeps no
	// record of where the column used to be.
	const show = async ( result, id ) => {
		const v = result.current[ 0 ];
		await act( () => result.current[ 1 ]( { ...v, fields: [ ...v.fields, id ] } ) );
	};

	const move = async ( result, from, to ) => {
		const v = result.current[ 0 ];
		const next = v.fields.slice();
		next.splice( to, 0, next.splice( from, 1 )[ 0 ] );
		await act( () => result.current[ 1 ]( { ...v, fields: next } ) );
	};

	it( 'puts a re-shown column back where it was', async () => {
		const { result } = load( {} );
		await flush();

		await hide( result, 'status' );
		expect( result.current[ 0 ].fields ).toEqual( [ 'reference', 'amount', 'gateway' ] );

		await show( result, 'status' );
		expect( result.current[ 0 ].fields ).toEqual( KNOWN );
	} );

	it( 'puts it back after a reload too', async () => {
		const { result } = load( { fields: [ 'reference', 'amount', 'gateway', 'status' ], order: KNOWN } );
		await flush();

		expect( result.current[ 0 ].fields ).toEqual( KNOWN );
	} );

	it( 'seats a column added since the view was saved in its own place, not at the end', async () => {
		const { result } = load(
			{ fields: [ 'reference', 'amount' ], order: [ 'reference', 'amount', 'gateway' ] },
			{ ...DEFAULTS, fields: KNOWN },
			KNOWN
		);
		await flush();

		// 'status' is new to this reader and belongs second, not last.
		expect( result.current[ 0 ].fields ).toEqual( [ 'reference', 'status', 'amount' ] );
	} );

	it( 'leaves an order the reader chose alone', async () => {
		const { result } = load( {} );
		await flush();

		await move( result, 3, 0 );
		expect( result.current[ 0 ].fields ).toEqual( [ 'gateway', 'reference', 'status', 'amount' ] );

		// A hide must not now re-seat everything back to the screen's order.
		await hide( result, 'status' );
		expect( result.current[ 0 ].fields ).toEqual( [ 'gateway', 'reference', 'amount' ] );
	} );

	it( 'records the move so it survives a reload', async () => {
		const { result } = load( {} );
		await flush();

		jest.useFakeTimers();
		await move( result, 3, 0 );
		jest.runAllTimers();
		jest.useRealTimers();

		const put = apiFetch.mock.calls.map( ( [ o ] ) => o ).filter( ( o ) => o.method === 'PUT' ).pop();
		expect( put.data.order ).toEqual( [ 'gateway', 'reference', 'status', 'amount' ] );
	} );

	/**
	 * The whole point of holding hidden columns in the arrangement: a column
	 * comes back where the reader had it, not where the screen would have put
	 * it and not at the end.
	 */
	it( 'returns a column to its place inside an arrangement the reader chose', async () => {
		const { result } = load( { fields: [ 'gateway', 'reference', 'status', 'amount' ], order: [ 'gateway', 'reference', 'status', 'amount' ] } );
		await flush();

		await hide( result, 'status' );
		expect( result.current[ 0 ].fields ).toEqual( [ 'gateway', 'reference', 'amount' ] );

		await show( result, 'status' );
		expect( result.current[ 0 ].fields ).toEqual( [ 'gateway', 'reference', 'status', 'amount' ] );
	} );

	it( 'keeps a hidden column\'s slot when visible columns are moved around it', async () => {
		const { result } = load( { fields: [ 'reference', 'amount', 'gateway' ], order: KNOWN } );
		await flush();

		// status is hidden and sits second in the arrangement.
		await move( result, 2, 0 );
		expect( result.current[ 0 ].fields ).toEqual( [ 'gateway', 'reference', 'amount' ] );

		await show( result, 'status' );
		expect( result.current[ 0 ].fields ).toEqual( [ 'gateway', 'status', 'reference', 'amount' ] );
	} );

	/**
	 * Seating a new column by scanning for the first higher-ranked one assumes
	 * the arrangement is in the screen's order, which is the one thing it is
	 * not. A reader who dragged a late column to the front would get every
	 * column shipped after that landing at position zero.
	 */
	it( 'seats a new column beside its neighbours even in a rearranged list', async () => {
		const LATER = [ 'reference', 'status', 'fund', 'amount', 'gateway' ];
		const { result } = load(
			// created the arrangement by dragging 'gateway' to the front
			{ fields: [ 'gateway', 'reference', 'status', 'amount' ], order: [ 'gateway', 'reference', 'status', 'amount' ] },
			{ ...DEFAULTS, fields: [ 'reference', 'status', 'fund', 'amount', 'gateway' ] },
			LATER
		);
		await flush();

		// 'fund' belongs after 'status', not at the front.
		expect( result.current[ 0 ].fields ).toEqual( [ 'gateway', 'reference', 'status', 'fund', 'amount' ] );
	} );

	it( 'honours a saved arrangement across a reload', async () => {
		const { result } = load( {
			fields: [ 'gateway', 'reference', 'amount' ],
			order: [ 'gateway', 'reference', 'status', 'amount' ],
		} );
		await flush();

		expect( result.current[ 0 ].fields ).toEqual( [ 'gateway', 'reference', 'amount' ] );
	} );
} );

describe( 'a filter on a column the screen no longer has', () => {
	/**
	 * Removing a column removes its filter chip with it, so a saved filter on
	 * that field can no longer be seen or cleared, while the list goes on
	 * counting itself as filtered and showing "nothing matches".
	 */
	it( 'is dropped rather than restored invisibly', async () => {
		const { result } = load( {
			filters: [
				{ field: 'is_test', operator: 'is', value: 'yes' },
				{ field: 'status', operator: 'is', value: 'failed' },
			],
		} );
		await flush();

		expect( result.current[ 0 ].filters ).toEqual( [ { field: 'status', operator: 'is', value: 'failed' } ] );
	} );
} );
