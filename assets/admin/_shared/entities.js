/**
 * GiveFlow entity registration for @wordpress/core-data. Call once on boot.
 */

import { dispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

let registered = false;

export function registerGiveFlowEntities() {
    if ( registered ) return;
    registered = true;

    dispatch( 'core' ).addEntities( [
        {
            kind:    'giveflow/v1',
            name:    'campaign',
            baseURL: '/giveflow/v1/admin/campaigns',
            label:   __( 'Campaign', 'giveflow-fundraising-campaigns' ),
        },
        {
            kind:    'giveflow/v1',
            name:    'form',
            baseURL: '/giveflow/v1/admin/forms',
            label:   __( 'Donation form', 'giveflow-fundraising-campaigns' ),
        },
    ] );
}
