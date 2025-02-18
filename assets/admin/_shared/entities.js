/**
 * Dono entity registration for @wordpress/core-data. Call once on boot.
 */

import { dispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

let registered = false;

export function registerDonoEntities() {
    if ( registered ) return;
    registered = true;

    dispatch( 'core' ).addEntities( [
        {
            kind:    'dono/v1',
            name:    'campaign',
            baseURL: '/dono/v1/admin/campaigns',
            label:   __( 'Campaign', 'dono' ),
        },
        {
            kind:    'dono/v1',
            name:    'form',
            baseURL: '/dono/v1/admin/forms',
            label:   __( 'Donation form', 'dono' ),
        },
    ] );
}
