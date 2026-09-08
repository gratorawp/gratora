/**
 * Resolve add-on fields lazily from window.fundkit.formFields. Entries may provide component,
 * values, validate, and payload; payload.extra is merged.
 */

function registry() {
    return ( typeof window !== 'undefined' && window.fundkit && window.fundkit.formFields ) || null;
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
