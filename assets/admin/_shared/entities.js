/**
 * Gratora entity registration for @wordpress/core-data. Call once on boot.
 */

import { dispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

let registered = false;

export function registerGratoraEntities() {
    if ( registered ) return;
    registered = true;

    dispatch( 'core' ).addEntities( [
        {
            kind:    'gratora/v1',
            name:    'campaign',
            baseURL: '/gratora/v1/admin/campaigns',
            label:   __( 'Campaign', 'gratora' ),
        },
        {
            kind:    'gratora/v1',
            name:    'form',
            baseURL: '/gratora/v1/admin/forms',
            label:   __( 'Donation form', 'gratora' ),
        },
    ] );
}
