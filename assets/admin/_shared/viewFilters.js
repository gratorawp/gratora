/**
 * Read view.filters to distinguish an empty list from no filter matches; keep filters
 * accessible in the latter case.
 */
export function isViewFiltered( view, extras = [] ) {
	if ( String( view?.search || '' ).trim() !== '' ) {
		return true;
	}
	if ( ( view?.filters || [] ).length > 0 ) {
		return true;
	}

	return extras.some( Boolean );
}

/** The same view with the narrowing taken off, back on the first page. */
export function clearedView( view ) {
	return { ...view, search: '', filters: [], page: 1 };
}
