/**
 * Merge stored edits over shipped built-ins, not the published list, which may contain deleted
 * custom presets.
 *
 * @param {Array} stored   the presets on the record
 * @param {Array} builtins window.fundkit.styling.builtins
 */
export function mergePresets( stored, builtins ) {
    const saved = Array.isArray( stored ) ? stored : [];
    const ships = Array.isArray( builtins ) ? builtins : [];
    const byId  = new Map( saved.map( ( p ) => [ String( p?.id || '' ), p ] ) );

    // Merge built-in edits over shipped records to retain translated names and descriptions.
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
