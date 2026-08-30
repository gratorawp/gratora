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
        label:    __( 'FundKit: Open dashboard', 'fundkit-fundraising-campaigns' ),
        icon:     chartBar,
        callback: goTo( 'fundkit' ),
    },
    {
        name:     'fundkit/donations',
        label:    __( 'FundKit: View donations', 'fundkit-fundraising-campaigns' ),
        icon:     currencyDollar,
        callback: goTo( 'fundkit-donations' ),
    },
    {
        name:     'fundkit/donors',
        label:    __( 'FundKit: View donors', 'fundkit-fundraising-campaigns' ),
        icon:     people,
        callback: goTo( 'fundkit-donors' ),
    },
    {
        name:     'fundkit/campaigns',
        label:    __( 'FundKit: View campaigns', 'fundkit-fundraising-campaigns' ),
        icon:     megaphone,
        callback: goTo( 'fundkit-campaigns' ),
    },
    {
        name:     'fundkit/funds',
        label:    __( 'FundKit: View funds', 'fundkit-fundraising-campaigns' ),
        icon:     archive,
        callback: goTo( 'fundkit-funds' ),
    },
    {
        name:     'fundkit/settings',
        label:    __( 'FundKit: Open settings', 'fundkit-fundraising-campaigns' ),
        icon:     cog,
        callback: goTo( 'fundkit-settings' ),
    },
    {
        name:     'fundkit/onboarding',
        label:    __( 'FundKit: Open onboarding wizard', 'fundkit-fundraising-campaigns' ),
        icon:     plus,
        callback: goTo( 'fundkit-onboarding' ),
    },
    {
        name:     'fundkit/new-campaign',
        label:    __( 'FundKit: New campaign', 'fundkit-fundraising-campaigns' ),
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
