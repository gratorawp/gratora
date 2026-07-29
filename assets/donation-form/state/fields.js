/**
 * Donor fields that ship outside core.
 *
 * PHP puts the field in the runtime config (dono.form.block_field); an add-on
 * bundle registers the browser half against window.dono.formFields, which
 * FormFieldAssets defines. An entry may supply any of:
 *
 *   component( { field, ctx } )   the rendered input
 *   values( fields )              seed values merged into state.values
 *   validate( field, values, msg )  { path: message } for invalid input
 *   payload( values, state )      partial request body; `extra` is merged
 *
 * Read lazily so an add-on bundle may load in either order relative to this one.
 */

function registry() {
    return ( typeof window !== 'undefined' && window.dono && window.dono.formFields ) || null;
}

export function fieldEntry( kind ) {
    const reg = registry();
    return reg && typeof reg.get === 'function' ? reg.get( kind ) : null;
}

/** @returns {Array<[string, object]>} */
export function registeredFields() {
    const reg = registry();
    return reg && typeof reg.all === 'function' ? reg.all() : [];
}

/** Seed values for every registered kind, whether or not the form uses it. */
export function registeredValues( fields ) {
    const out = {};
    for ( const [ , entry ] of registeredFields() ) {
        if ( typeof entry.values !== 'function' ) continue;
        Object.assign( out, entry.values( fields ) || {} );
    }
    return out;
}

/** Errors an add-on field reports for one visible field. */
export function registeredErrors( field, values, msg ) {
    const entry = fieldEntry( field.kind );
    if ( ! entry || typeof entry.validate !== 'function' ) return null;
    return entry.validate( field, values, msg ) || null;
}

/** Fold every add-on contribution into the request body. */
export function mergePayload( payload, values, state ) {
    const out = { ...payload };
    for ( const [ , entry ] of registeredFields() ) {
        if ( typeof entry.payload !== 'function' ) continue;
        const part = entry.payload( values, state );
        if ( ! part || typeof part !== 'object' ) continue;
        const { extra, ...rest } = part;
        Object.assign( out, rest );
        if ( extra && typeof extra === 'object' ) {
            out.extra = { ...( out.extra || {} ), ...extra };
        }
    }
    return out;
}
