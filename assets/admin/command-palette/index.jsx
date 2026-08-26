/**
 * Registers GiveFlow commands in the WP global command palette (Cmd/Ctrl+K); enqueued
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
    `${ window.giveflowCommandPalette?.adminUrl ?? '/wp-admin/' }admin.php?page=${ page }`;

const goTo = ( page ) => ( { close } ) => {
    window.location.href = adminUrl( page );
    close();
};

const commands = [
    {
        name:     'giveflow/dashboard',
        label:    __( 'GiveFlow: Open dashboard', 'giveflow-fundraising-campaigns' ),
        icon:     chartBar,
        callback: goTo( 'giveflow' ),
    },
    {
        name:     'giveflow/donations',
        label:    __( 'GiveFlow: View donations', 'giveflow-fundraising-campaigns' ),
        icon:     currencyDollar,
        callback: goTo( 'giveflow-donations' ),
    },
    {
        name:     'giveflow/donors',
        label:    __( 'GiveFlow: View donors', 'giveflow-fundraising-campaigns' ),
        icon:     people,
        callback: goTo( 'giveflow-donors' ),
    },
    {
        name:     'giveflow/campaigns',
        label:    __( 'GiveFlow: View campaigns', 'giveflow-fundraising-campaigns' ),
        icon:     megaphone,
        callback: goTo( 'giveflow-campaigns' ),
    },
    {
        name:     'giveflow/funds',
        label:    __( 'GiveFlow: View funds', 'giveflow-fundraising-campaigns' ),
        icon:     archive,
        callback: goTo( 'giveflow-funds' ),
    },
    {
        name:     'giveflow/settings',
        label:    __( 'GiveFlow: Open settings', 'giveflow-fundraising-campaigns' ),
        icon:     cog,
        callback: goTo( 'giveflow-settings' ),
    },
    {
        name:     'giveflow/onboarding',
        label:    __( 'GiveFlow: Open onboarding wizard', 'giveflow-fundraising-campaigns' ),
        icon:     plus,
        callback: goTo( 'giveflow-onboarding' ),
    },
    {
        name:     'giveflow/new-campaign',
        label:    __( 'GiveFlow: New campaign', 'giveflow-fundraising-campaigns' ),
        icon:     plus,
        callback: ( { close } ) => {
            window.location.href = adminUrl( 'giveflow-campaigns' ) + '&action=new';
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
