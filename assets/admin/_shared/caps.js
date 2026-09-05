/**
 * What this reader may do, as the REST gates would answer it.
 *
 * The map is injected on every FundKit admin screen with no capability gate of
 * its own, so its absence means the globals did not load rather than that the
 * reader holds nothing. Default-permit there: the server is the real gate, and
 * a stale window.fundkit must not strip an administrator's menu.
 *
 * Names drop the fundkit_ prefix: userCan( 'refund_donations' ).
 */
export const userCan = ( cap ) => {
    const can = window.fundkit?.can;
    return can ? !! can[ cap ] : true;
};
