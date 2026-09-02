/**
 * Registers FundKit commands in the WP global command palette (Cmd/Ctrl+K); enqueued
 * on every admin screen so the palette can always reach them.
 */
import { dispatch } from '@wordpress/data';
import { store as commandsStore } from '@wordpress/commands';
import { __ } from '@wordpress/i18n';
import {
    chartBar,
    currencyDollar,
    people,
    megaphone,
    archive,
    cog,
    plus,
} from '@wordpress/icons';

const adminUrl = ( page ) =>
    `${ window.fundkitCommandPalette?.adminUrl ?? '/wp-admin/' }admin.php?page=${ page }`;

const goTo = ( page ) => ( { close } ) => {
    window.location.href = adminUrl( page );
    close();
};

const commands = [
    {
        name:     'fundkit/dashboard',
        label:    __( 'Fundraising Toolkit: Open dashboard', 'fundraising-toolkit' ),
        icon:     chartBar,
        callback: goTo( 'fundkit' ),
    },
    {
        name:     'fundkit/donations',
        label:    __( 'Fundraising Toolkit: View donations', 'fundraising-toolkit' ),
        icon:     currencyDollar,
        callback: goTo( 'fundkit-donations' ),
    },
    {
        name:     'fundkit/donors',
        label:    __( 'Fundraising Toolkit: View donors', 'fundraising-toolkit' ),
        icon:     people,
        callback: goTo( 'fundkit-donors' ),
    },
    {
        name:     'fundkit/campaigns',
        label:    __( 'Fundraising Toolkit: View campaigns', 'fundraising-toolkit' ),
        icon:     megaphone,
        callback: goTo( 'fundkit-campaigns' ),
    },
    {
        name:     'fundkit/funds',
        label:    __( 'Fundraising Toolkit: View funds', 'fundraising-toolkit' ),
        icon:     archive,
        callback: goTo( 'fundkit-funds' ),
    },
    {
        name:     'fundkit/settings',
        label:    __( 'Fundraising Toolkit: Open settings', 'fundraising-toolkit' ),
        icon:     cog,
        callback: goTo( 'fundkit-settings' ),
    },
    {
        name:     'fundkit/onboarding',
        label:    __( 'Fundraising Toolkit: Open onboarding wizard', 'fundraising-toolkit' ),
        icon:     plus,
        callback: goTo( 'fundkit-onboarding' ),
    },
    {
        name:     'fundkit/new-campaign',
        label:    __( 'Fundraising Toolkit: New campaign', 'fundraising-toolkit' ),
        icon:     plus,
        callback: ( { close } ) => {
            window.location.href = adminUrl( 'fundkit-campaigns' ) + '&action=new';
            close();
        },
    },
];

const register = () => {
    const { registerCommand } = dispatch( commandsStore );
    commands.forEach( ( cmd ) => registerCommand( cmd ) );
};

// Defer until the commands store is ready. wp.data is loaded synchronously
// when this script's dependencies resolve, so the store exists already.
register();
