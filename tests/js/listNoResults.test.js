/**
 * A list with nothing in it and a list filtered down to nothing are different
 * empties. Subscriptions showed the zero state for both, so a filter that
 * matched nothing said "No subscriptions yet" on an account with thirty one of
 * them, and took the filter controls off the screen with it: the only way back
 * was to reload the page.
 */
import { isViewFiltered, clearedView } from '../../assets/admin/_shared/viewFilters';

const view = ( over = {} ) => ( { type: 'table', page: 2, search: '', filters: [], ...over } );

test( 'a list showing everything is not filtered', () => {
	expect( isViewFiltered( view() ) ).toBe( false );
} );

test( 'a search narrows it', () => {
	expect( isViewFiltered( view( { search: 'ada' } ) ) ).toBe( true );
} );

test( 'whitespace is not a search', () => {
	expect( isViewFiltered( view( { search: '   ' } ) ) ).toBe( false );
} );

/**
 * The reason this asks the view rather than each screen's own variables: every
 * status, gateway and campaign filter lives in view.filters, so a screen that
 * adds one is covered without anybody remembering to come back here.
 */
test( 'any filter narrows it, whichever field it is on', () => {
	expect( isViewFiltered( view( { filters: [ { field: 'gateway', value: 'stripe' } ] } ) ) ).toBe( true );
	expect( isViewFiltered( view( { filters: [ { field: 'something_new', value: 'x' } ] } ) ) ).toBe( true );
} );

/** Screens with a filter of their own outside the view pass it in. */
test( 'a filter held outside the view still counts', () => {
	expect( isViewFiltered( view(), [ '', '2026-08-01' ] ) ).toBe( true );
	expect( isViewFiltered( view(), [ '', '' ] ) ).toBe( false );
} );

test( 'a missing view is not filtered rather than a crash', () => {
	expect( isViewFiltered( undefined ) ).toBe( false );
} );

test( 'clearing takes off the narrowing and goes back to the first page', () => {
	const cleared = clearedView( view( { search: 'ada', filters: [ { field: 'status', value: 'paid' } ] } ) );

	expect( cleared.search ).toBe( '' );
	expect( cleared.filters ).toEqual( [] );
	expect( cleared.page ).toBe( 1 );
} );

test( 'clearing keeps everything else about the view', () => {
	const cleared = clearedView( view( { perPage: 50, sort: { field: 'created_at', direction: 'desc' } } ) );

	expect( cleared.perPage ).toBe( 50 );
	expect( cleared.sort ).toEqual( { field: 'created_at', direction: 'desc' } );
	expect( cleared.type ).toBe( 'table' );
} );
