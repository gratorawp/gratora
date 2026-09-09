/**
 * Registers Gratora commands in the WP global command palette (Cmd/Ctrl+K); enqueued
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
    `${ window.gratoraCommandPalette?.adminUrl ?? '/wp-admin/' }admin.php?page=${ page }`;

// Absent means the localization did not land, not that the reader holds
// nothing; the server is the real gate. Same stance as _shared/caps.js.
const mayOpen = ( page ) => {
    const can = window.gratoraCommandPalette?.can;
    return can ? !! can[ page ] : true;
};

const goTo = ( page ) => ( { close } ) => {
    window.location.href = adminUrl( page );
    close();
};

const commands = [
    {
        name:     'gratora/dashboard',
        page:     'gratora',
        label:    __( 'Gratora: Open dashboard', 'gratora' ),
        icon:     chartBar,
        callback: goTo( 'gratora' ),
    },
    {
        name:     'gratora/donations',
        page:     'gratora-donations',
        label:    __( 'Gratora: View donations', 'gratora' ),
        icon:     currencyDollar,
        callback: goTo( 'gratora-donations' ),
    },
    {
        name:     'gratora/donors',
        page:     'gratora-donors',
        label:    __( 'Gratora: View donors', 'gratora' ),
        icon:     people,
        callback: goTo( 'gratora-donors' ),
    },
    {
        name:     'gratora/campaigns',
        page:     'gratora-campaigns',
        label:    __( 'Gratora: View campaigns', 'gratora' ),
        icon:     megaphone,
        callback: goTo( 'gratora-campaigns' ),
    },
    {
        name:     'gratora/funds',
        page:     'gratora-funds',
        label:    __( 'Gratora: View funds', 'gratora' ),
        icon:     archive,
        callback: goTo( 'gratora-funds' ),
    },
    {
        name:     'gratora/settings',
        page:     'gratora-settings',
        label:    __( 'Gratora: Open settings', 'gratora' ),
        icon:     cog,
        callback: goTo( 'gratora-settings' ),
    },
    {
        name:     'gratora/onboarding',
        page:     'gratora-onboarding',
        label:    __( 'Gratora: Open onboarding wizard', 'gratora' ),
        icon:     plus,
        callback: goTo( 'gratora-onboarding' ),
    },
    {
        name:     'gratora/new-campaign',
        page:     'gratora-campaigns',
        label:    __( 'Gratora: New campaign', 'gratora' ),
        icon:     plus,
        callback: ( { close } ) => {
            window.location.href = adminUrl( 'gratora-campaigns' ) + '&action=new';
            close();
        },
    },
];

const register = () => {
    const { registerCommand } = dispatch( commandsStore );
    commands
        .filter( ( cmd ) => mayOpen( cmd.page ) )
        .forEach( ( { page, ...cmd } ) => registerCommand( cmd ) );
};

// Defer until the commands store is ready. wp.data is loaded synchronously
// when this script's dependencies resolve, so the store exists already.
register();
