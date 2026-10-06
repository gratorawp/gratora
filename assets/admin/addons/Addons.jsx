import { createInterpolateElement } from '@wordpress/element';
import { __, _n, _x, sprintf } from '@wordpress/i18n';
import {
    ChartLine,
    CreditCard,
    ExternalLink,
    Import,
    Landmark,
    Mail,
    Puzzle,
    Rose,
    Sparkles,
    Tag,
    Ticket,
    UsersRound,
    Webhook,
} from 'lucide-react';

import Btn from '../_shared/components/Btn';
import { dashboardHref } from '../_shared/adminPages';

// The same on every site, as on the links gratora.net sends: it names the plugin as the source of a visit, not the site.
const MARK       = '?utm_source=plugin&utm_medium=add-ons';
const PLANS_URL  = 'https://gratora.net/pricing/' + MARK;
const SOURCE_URL = 'https://gratora.net/add-ons/' + MARK;

const ICONS = {
    'users-round': UsersRound,
    ticket:        Ticket,
    sparkles:      Sparkles,
    'credit-card': CreditCard,
    'chart-line':  ChartLine,
    webhook:       Webhook,
    rose:          Rose,
    landmark:      Landmark,
    mail:          Mail,
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
    if ( addon.plan ) {
        return <span className="gratora-pill gratora-pill--type">{ addon.plan }</span>;
    }
    return null;
}

function AddonCard( { addon, heading: Heading } ) {
    // The name is sent by gratora.net, and a plain lookup would also answer to names like "constructor".
    const Icon = Object.hasOwn( ICONS, addon.icon ) ? ICONS[ addon.icon ] : Puzzle;

    return (
        <li className="gratora-addon">
            <div className="gratora-addon__head">
                <span className="gratora-addon__tile" aria-hidden="true">
                    <Icon size={ 18 } strokeWidth={ 2.25 } />
                </span>
                <Heading className="gratora-addon__name">{ addon.name }</Heading>
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

function SourceLink( { children } ) {
    return (
        <a href={ SOURCE_URL } target="_blank" rel="noreferrer">
            { children }
            <ExternalLink size={ 12 } strokeWidth={ 2 } aria-hidden="true" />
            <span className="screen-reader-text">{ __( '(opens in a new tab)', 'gratora-donation-platform' ) }</span>
        </a>
    );
}

function Offer( { offer } ) {
    return (
        <div className="gratora-offer">
            <span className="gratora-offer__icon" aria-hidden="true">
                <Tag size={ 18 } strokeWidth={ 2 } />
            </span>
            <p className="gratora-offer__text" id="gratora-addons-offer">{ offer.text }</p>
            <a className="gratora-addon__more" href={ offer.url } target="_blank" rel="noreferrer" aria-describedby="gratora-addons-offer">
                { __( 'Learn more', 'gratora-donation-platform' ) }
                <ExternalLink size={ 14 } strokeWidth={ 2 } aria-hidden="true" />
                <span className="screen-reader-text">{ __( '(opens in a new tab)', 'gratora-donation-platform' ) }</span>
            </a>
        </div>
    );
}

function PlanColumn( { plan } ) {
    return (
        <li className="gratora-plan">
            <h3 className="gratora-plan__name">{ plan.name }</h3>
            { plan.summary && <p className="gratora-plan__summary">{ plan.summary }</p> }
            <p className="gratora-plan__facts">
                <span className="gratora-plan__fact">
                    { sprintf(
                        /* translators: %d: number of add-ons a plan includes. */
                        _n( '%d add-on', '%d add-ons', plan.count, 'gratora-donation-platform' ),
                        plan.count
                    ) }
                </span>
                <span className="gratora-plan__fact">
                    { sprintf(
                        /* translators: %d: number of sites a plan covers. */
                        _n( '%d site', '%d sites', plan.sites, 'gratora-donation-platform' ),
                        plan.sites
                    ) }
                </span>
            </p>
            <div className="gratora-plan__foot">
                <a className="gratora-addon__more" href={ plan.url } target="_blank" rel="noreferrer">
                    { _x( 'See plan', 'link to one paid plan', 'gratora-donation-platform' ) }
                    <span className="screen-reader-text">
                        { ` ${ plan.name } ${ __( '(opens in a new tab)', 'gratora-donation-platform' ) }` }
                    </span>
                    <ExternalLink size={ 14 } strokeWidth={ 2 } aria-hidden="true" />
                </a>
            </div>
        </li>
    );
}

export default function Addons( { addons, plans = [], offer = null, source } ) {
    const hasPlans = plans.length > 0;

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
                <div className="gratora-addons__head-right">
                    { source === 'remote' && (
                        <p className="gratora-addons__source">
                            { createInterpolateElement(
                                __( 'The lists on this page are loaded from <a>gratora.net</a>', 'gratora-donation-platform' ),
                                { a: <SourceLink /> }
                            ) }
                        </p>
                    ) }
                    { ! hasPlans && (
                        <div className="gratora-page-head__actions">
                            <Btn href={ PLANS_URL } target="_blank" rel="noreferrer">
                                { __( 'Compare plans', 'gratora-donation-platform' ) }
                                <ExternalLink size={ 14 } strokeWidth={ 2 } aria-hidden="true" />
                                <span className="screen-reader-text">{ __( '(opens in a new tab)', 'gratora-donation-platform' ) }</span>
                            </Btn>
                        </div>
                    ) }
                </div>
            </div>

            { offer && <Offer offer={ offer } /> }

            { hasPlans && (
                <>
                    <h2 className="gratora-addons__section">{ _x( 'Plans', 'paid plans that hold the add-ons', 'gratora-donation-platform' ) }</h2>
                    <ul className="gratora-plans">
                        { plans.map( ( plan ) => <PlanColumn key={ plan.slug } plan={ plan } /> ) }
                    </ul>
                    <h2 className="gratora-addons__section">{ __( 'Add-ons', 'gratora-donation-platform' ) }</h2>
                </>
            ) }

            <ul className="gratora-addons__grid">
                { addons.map( ( addon ) => <AddonCard key={ addon.slug } addon={ addon } heading={ hasPlans ? 'h3' : 'h2' } /> ) }
            </ul>
        </div>
    );
}
