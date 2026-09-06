/**
 * "Allowed gateways" is an allow-list where EMPTY means "offer every gateway",
 * which is how the donor-facing resolver and FormService::syncGatewayAllowed
 * both read it.
 *
 * Rendered as a plain includes() an empty list looks like nothing is enabled,
 * so an author on a form built from any shipped template sees every gateway
 * unchecked and ticks one, which quietly turns the rest off. The two screens
 * that edit this value have to agree, so they share these.
 */
export const gatewayIsOn = ( allowed, id ) =>
    ( allowed || [] ).length === 0 || ( allowed || [] ).includes( id );

/** A full set collapses back to [], which is the same "offer all". */
export function toggleGatewayAllowed( allowed, id, allIds ) {
    const on = new Set( ( allowed || [] ).length === 0 ? allIds : allowed );

    if ( on.has( id ) ) on.delete( id ); else on.add( id );

    const next = allIds.filter( ( x ) => on.has( x ) );

    // [] already means "offer all", so emptying the set would turn every
    // gateway back on.
    if ( next.length === 0 ) {
        return ( allowed || [] ).slice();
    }

    return next.length === allIds.length ? [] : next;
}

/** How many gateways this form actually offers. */
export const gatewayOnCount = ( allowed, allIds ) =>
    ( allowed || [] ).length === 0
        ? allIds.length
        : allIds.filter( ( id ) => allowed.includes( id ) ).length;
