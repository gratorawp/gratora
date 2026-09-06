export function slugify( s ) {
    return String( s || '' )
        .toLowerCase()
        .replace( /[^a-z0-9]+/g, '-' )
        .replace( /^-+|-+$/g, '' );
}

// Field keys are snake_case to match the server (DropdownBlock::slugifySnake)
// and the runtime custom-value keys, so conditions targeting a field resolve.
export function slugifyField( s ) {
    return String( s || '' )
        .toLowerCase()
        .replace( /[^a-z0-9]+/g, '_' )
        .replace( /^_+|_+$/g, '' );
}

// Mirrors DropdownBlock::deriveField, which is the key the runtime actually
// uses. The PHP falls back to the literal 'field' when both are empty; a block
// with neither cannot be named in a dropdown, so it is left out instead.
export const deriveFieldKey = ( field, label ) => slugifyField( field ) || slugifyField( label );
