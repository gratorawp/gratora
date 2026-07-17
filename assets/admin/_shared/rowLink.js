/**
 * DataViews rows toggle their bulk selection when clicked. A title/name link
 * lives inside the row, so without this a plain click both navigates and
 * selects the row. Stop the link's mouse/click events from bubbling to the row
 * so the link only navigates; the checkbox and the rest of the row still select.
 */
export const stopRowSelect = ( e ) => e.stopPropagation();

/** Spread onto a row's navigation link: `<a href={...} { ...rowLinkProps } />`. */
export const rowLinkProps = { onMouseDown: stopRowSelect, onClick: stopRowSelect };
