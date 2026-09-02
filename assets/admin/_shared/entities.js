/**
 * FundKit entity registration for @wordpress/core-data. Call once on boot.
 */

import { dispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

let registered = false;

export function registerFundKitEntities() {
    if ( registered ) return;
    registered = true;

    dispatch( 'core' ).addEntities( [
        {
            kind:    'fundkit/v1',
            name:    'campaign',
            baseURL: '/fundkit/v1/admin/campaigns',
            label:   __( 'Campaign', 'fundraising-toolkit' ),
        },
        {
            kind:    'fundkit/v1',
            name:    'form',
            baseURL: '/fundkit/v1/admin/forms',
            label:   __( 'Donation form', 'fundraising-toolkit' ),
        },
    ] );
}
