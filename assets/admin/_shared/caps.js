/**
 * Use prefix-free capability names. Missing globals default to permit; server routes remain
 * authoritative.
 */
export const userCan = ( cap ) => {
    const can = window.fundkit?.can;
    return can ? !! can[ cap ] : true;
};
