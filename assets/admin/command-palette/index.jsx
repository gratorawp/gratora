/**
 * Registers Dono commands in the WP global command palette (Cmd/Ctrl+K); enqueued
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
    `${ window.donoCommandPalette?.adminUrl ?? '/wp-admin/' }admin.php?page=${ page }`;

const goTo = ( page ) => ( { close } ) => {
    window.location.href = adminUrl( page );
    close();
};

const commands = [
    {
        name:     'dono/dashboard',
        label:    __( 'Dono: Open dashboard', 'dono-fundraising-platform' ),
        icon:     chartBar,
        callback: goTo( 'dono-fundraising-platform' ),
    },
    {
        name:     'dono/donations',
        label:    __( 'Dono: View donations', 'dono-fundraising-platform' ),
        icon:     currencyDollar,
        callback: goTo( 'dono-donations' ),
    },
    {
        name:     'dono/donors',
        label:    __( 'Dono: View donors', 'dono-fundraising-platform' ),
        icon:     people,
        callback: goTo( 'dono-donors' ),
    },
    {
        name:     'dono/campaigns',
        label:    __( 'Dono: View campaigns', 'dono-fundraising-platform' ),
        icon:     megaphone,
        callback: goTo( 'dono-campaigns' ),
    },
    {
        name:     'dono/funds',
        label:    __( 'Dono: View funds', 'dono-fundraising-platform' ),
        icon:     archive,
        callback: goTo( 'dono-funds' ),
    },
    {
        name:     'dono/settings',
        label:    __( 'Dono: Open settings', 'dono-fundraising-platform' ),
        icon:     cog,
        callback: goTo( 'dono-settings' ),
    },
    {
        name:     'dono/onboarding',
        label:    __( 'Dono: Open onboarding wizard', 'dono-fundraising-platform' ),
        icon:     plus,
        callback: goTo( 'dono-onboarding' ),
    },
    {
        name:     'dono/new-campaign',
        label:    __( 'Dono: New campaign', 'dono-fundraising-platform' ),
        icon:     plus,
        callback: ( { close } ) => {
            window.location.href = adminUrl( 'dono-campaigns' ) + '&action=new';
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
