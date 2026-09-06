/**
 * The list the Brand panel shows.
 *
 * The stored option holds only the presets the admin touched: the server drops
 * any built-in still identical to what ships, so the option is never the whole
 * list. Every other screen reads StylePresets::all(), which merges the two, so
 * a panel that renders the option alone is the one place in the product where
 * Bold and Quiet stop existing the moment Classic is edited.
 *
 * Merged over the built-ins, not over the full published list: that list also
 * carries customs, and seeding from it would put a just-deleted custom back
 * from a stale page-load snapshot.
 *
 * @param {Array} stored   the presets on the record
 * @param {Array} builtins window.fundkit.styling.builtins
 */
export function mergePresets( stored, builtins ) {
    const saved = Array.isArray( stored ) ? stored : [];
    const ships = Array.isArray( builtins ) ? builtins : [];
    const byId  = new Map( saved.map( ( p ) => [ String( p?.id || '' ), p ] ) );

    // Built-ins first, in the order they ship, each carrying the admin's edit
    // where there is one. Laid over the shipped record rather than replacing
    // it: a built-in's name and description are __() calls the server drops
    // from the option rather than pin every later reader to the locale of
    // whoever saved, so an edited built-in arrives here without them. The
    // server restores them on read and this is the same merge.
    const out = ships.map( ( b ) => {
        const edit = byId.get( String( b.id ) );
        if ( ! edit ) return b;

        return {
            ...b,
            ...edit,
            name:        edit.name || b.name,
            description: edit.description || b.description,
            tokens:      { ...( b.tokens || {} ), ...( edit.tokens || {} ) },
        };
    } );
    const seen = new Set( ships.map( ( b ) => String( b.id ) ) );

    for ( const p of saved ) {
        if ( ! seen.has( String( p?.id || '' ) ) ) out.push( p );
    }

    return out;
}

/**
 * The same list, reading the shipped set the panel is entitled to seed from.
 *
 * Which global that is, is the decision: styling.presets is StylePresets::all()
 * and carries customs too, so seeding from it puts a just-deleted custom back
 * from the page-load snapshot, in the same tab, before any save.
 */
export function presetsForPanel( stored ) {
    return mergePresets( stored, window.fundkit?.styling?.builtins );
}
