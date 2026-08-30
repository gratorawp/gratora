/**
 * Is this list showing everything, or a slice of it?
 *
 * The difference decides which empty a screen shows. A list with nothing in it
 * gets the zero state: what this screen is for and how records get here. A list
 * that has been filtered down to nothing must not, because "no subscriptions
 * yet" is untrue and, worse, it replaces the table and takes the filter
 * controls with it, so the only way back is to reload the page.
 *
 * Asked of the view rather than of each screen's own filter variables. Every
 * status, gateway and campaign filter on these screens is stored in
 * `view.filters`, so a screen that adds one gets the right answer here without
 * remembering to come back and add it to a list of names.
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
