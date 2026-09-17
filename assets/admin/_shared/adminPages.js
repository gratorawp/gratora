/**
 * wp-admin page slugs, in one place.
 *
 * These are `page=` query values registered by AdminMenu, not text domains,
 * and they must never be swept by a rename of the domain. Keeping them here
 * means a slug change is one edit rather than a hunt through every screen, and
 * a stale one is caught by AdminPageSlugTest rather than by a 404.
 */
import { addQueryArgs } from '@wordpress/url';

export const ADMIN_SLUG = 'gratora';

const DONATIONS_SLUG = 'gratora-donations';
const DONORS_SLUG    = 'gratora-donors';

/** The Gratora dashboard, the target of every screen's "Gratora" breadcrumb. */
export const dashboardHref = ( pathname ) =>
    `${ pathname }?page=${ encodeURIComponent( ADMIN_SLUG ) }`;

/** A donation, addressed by its reference: the number every screen prints. */
export const donationHref = ( reference ) =>
    addQueryArgs( window.location.pathname, {
        page: DONATIONS_SLUG,
        view: 'detail',
        reference,
    } );

/** A donor's profile, which the donors screen opens from the hash. */
export const donorHref = ( donorId ) =>
    `${ addQueryArgs( window.location.pathname, { page: DONORS_SLUG } ) }#donor/${ donorId }`;
