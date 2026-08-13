/**
 * wp-admin page slugs, in one place.
 *
 * These are `page=` query values registered by AdminMenu, not text domains,
 * and they must never be swept by a rename of the domain. Keeping them here
 * means a slug change is one edit rather than a hunt through every screen, and
 * a stale one is caught by AdminPageSlugTest rather than by a 404.
 */
export const ADMIN_SLUG = 'dono';

/** The Dono dashboard, the target of every screen's "Dono" breadcrumb. */
export const dashboardHref = ( pathname ) =>
    `${ pathname }?page=${ encodeURIComponent( ADMIN_SLUG ) }`;
