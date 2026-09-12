// Trash, restore and delete, as one caller.
//
// Shared because both the live list and the bin send them: a copy in each would
// let one screen start reporting an outcome differently from the other, which
// is the same drift the shared columns exist to prevent.

import apiFetch from '@wordpress/api-fetch';

// The routes take a visible page selection at a time. A larger one is split and
// the answers merged here, rather than sent as one request that runs until
// something times out half-done.
const CHUNK = 50;

export function chunked( references ) {
    const out = [];
    for ( let i = 0; i < references.length; i += CHUNK ) {
        out.push( references.slice( i, i + CHUNK ) );
    }
    return out;
}

/**
 * Merge the per-row answers from however many calls it took.
 *
 * Every row gets its own outcome, so the caller can say what happened to each
 * rather than reducing a mixed batch to one verdict.
 */
export async function postBatch( route, references, extra = {} ) {
    const merged = { done: [], already: [], refused: [] };

    for ( const batch of chunked( references ) ) {
        const res = await apiFetch( {
            path:   `/gratora/v1/admin/donations/${ route }`,
            method: 'POST',
            data:   { references: batch, ...extra },
        } );

        merged.done.push( ...( res?.done || [] ) );
        merged.already.push( ...( res?.already || [] ) );
        merged.refused.push( ...( res?.refused || [] ) );
    }

    return merged;
}
