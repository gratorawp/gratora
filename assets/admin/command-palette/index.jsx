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
    formatListBullets,
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
        label:    __( 'Dono: Open dashboard', 'dono' ),
        icon:     chartBar,
        callback: goTo( 'dono' ),
    },
    {
        name:     'dono/donations',
        label:    __( 'Dono: View donations', 'dono' ),
        icon:     currencyDollar,
        callback: goTo( 'dono-donations' ),
    },
    {
        name:     'dono/donors',
        label:    __( 'Dono: View donors', 'dono' ),
        icon:     people,
        callback: goTo( 'dono-donors' ),
    },
    {
        name:     'dono/campaigns',
        label:    __( 'Dono: View campaigns', 'dono' ),
        icon:     megaphone,
        callback: goTo( 'dono-campaigns' ),
    },
    {
        name:     'dono/forms',
        label:    __( 'Dono: View donation forms', 'dono' ),
        icon:     formatListBullets,
        callback: goTo( 'dono-forms' ),
    },
    {
        name:     'dono/funds',
        label:    __( 'Dono: View funds', 'dono' ),
        icon:     archive,
        callback: goTo( 'dono-funds' ),
    },
    {
        name:     'dono/settings',
        label:    __( 'Dono: Open settings', 'dono' ),
        icon:     cog,
        callback: goTo( 'dono-settings' ),
    },
    {
        name:     'dono/onboarding',
        label:    __( 'Dono: Open onboarding wizard', 'dono' ),
        icon:     plus,
        callback: goTo( 'dono-onboarding' ),
    },
    {
        name:     'dono/new-campaign',
        label:    __( 'Dono: New campaign', 'dono' ),
        icon:     plus,
        callback: ( { close } ) => {
            window.location.href = adminUrl( 'dono-campaigns' ) + '&action=new';
            close();
        },
    },
    {
        name:     'dono/new-form',
        label:    __( 'Dono: New donation form', 'dono' ),
        icon:     plus,
        callback: ( { close } ) => {
            window.location.href = adminUrl( 'dono-forms' ) + '&action=new';
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
