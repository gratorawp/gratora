import { __, sprintf } from '@wordpress/i18n';
import {
    ChartLine,
    CreditCard,
    ExternalLink,
    Import,
    Landmark,
    Rose,
    Sparkles,
    Ticket,
    UsersRound,
    Webhook,
} from 'lucide-react';

import Btn from '../_shared/components/Btn';
import { dashboardHref } from '../_shared/adminPages';

const PLANS_URL = 'https://gratora.net/pricing/';

const ICONS = {
    'users-round': UsersRound,
    ticket:        Ticket,
    sparkles:      Sparkles,
    'credit-card': CreditCard,
    'chart-line':  ChartLine,
    webhook:       Webhook,
    rose:          Rose,
    landmark:      Landmark,
    import:        Import,
};

function Pill( { addon } ) {
    if ( addon.status === 'active' ) {
        return <span className="gratora-pill gratora-pill--green">{ __( 'Active', 'gratora-donation-platform' ) }</span>;
    }
    if ( addon.status === 'installed' ) {
        return <span className="gratora-pill gratora-pill--gray">{ __( 'Installed', 'gratora-donation-platform' ) }</span>;
    }
    if ( addon.free ) {
        return <span className="gratora-pill gratora-pill--type">{ __( 'Free', 'gratora-donation-platform' ) }</span>;
    }
    return null;
}

function AddonCard( { addon } ) {
    const Icon = ICONS[ addon.icon ];

    return (
        <li className="gratora-addon">
            <div className="gratora-addon__head">
                <span className="gratora-addon__tile" aria-hidden="true">
                    { Icon && <Icon size={ 18 } strokeWidth={ 2.25 } /> }
                </span>
                <h2 className="gratora-addon__name">{ addon.name }</h2>
                <Pill addon={ addon } />
            </div>
            <p className="gratora-addon__desc">{ addon.description }</p>
            <div className="gratora-addon__foot">
                <a
                    className="gratora-addon__more"
                    href={ addon.url }
                    target="_blank"
                    rel="noreferrer"
                    /* translators: %s: add-on name. */
                    aria-label={ sprintf( __( 'Learn more about %s (opens in a new tab)', 'gratora-donation-platform' ), addon.name ) }
                >
                    { __( 'Learn more', 'gratora-donation-platform' ) }
                    <ExternalLink size={ 14 } strokeWidth={ 2 } aria-hidden="true" />
                </a>
                { addon.activateUrl && (
                    <Btn
                        variant="primary"
                        size="sm"
                        href={ addon.activateUrl }
                        /* translators: %s: add-on name. */
                        aria-label={ sprintf( __( 'Activate %s', 'gratora-donation-platform' ), addon.name ) }
                    >
                        { __( 'Activate', 'gratora-donation-platform' ) }
                    </Btn>
                ) }
            </div>
        </li>
    );
}

export default function Addons( { addons } ) {
    return (
        <div className="gratora-addons">
            <div className="gratora-crumbs">
                <a href={ dashboardHref( window.location.pathname ) }>{ __( 'Fundraising', 'gratora-donation-platform' ) }</a>
                <span className="sep">›</span>
                <span>{ __( 'Add-ons', 'gratora-donation-platform' ) }</span>
            </div>

            <div className="gratora-page-head">
                <div className="gratora-page-head__left">
                    <h1>{ __( 'Add-ons', 'gratora-donation-platform' ) }</h1>
                    <p className="gratora-page-head__sub">
                        { __( 'Add-ons are separate plugins that work alongside Gratora.', 'gratora-donation-platform' ) }
                    </p>
                </div>
                <div className="gratora-page-head__actions">
                    <Btn href={ PLANS_URL } target="_blank" rel="noreferrer">
                        { __( 'Compare plans', 'gratora-donation-platform' ) }
                        <ExternalLink size={ 14 } strokeWidth={ 2 } aria-hidden="true" />
                        <span className="screen-reader-text">{ __( '(opens in a new tab)', 'gratora-donation-platform' ) }</span>
                    </Btn>
                </div>
            </div>

            <ul className="gratora-addons__grid">
                { addons.map( ( addon ) => <AddonCard key={ addon.slug } addon={ addon } /> ) }
            </ul>
        </div>
    );
}
