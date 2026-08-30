import { useState, useEffect, useMemo } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';

import Btn from '../_shared/components/Btn';
import Card from '../_shared/components/Card';
import Toaster from '../_shared/components/Toaster';
import { notify } from '../_shared/notify';
import { tablistKeyDown } from '../_shared/tablistKeys';
import { useExtensionTabs, ExtensionTabPanel } from '../_shared/extensionTabs';

import { useFundKitSettings } from '../_shared/useFundKitSettings';
import { useFxRates } from '../_shared/useFxRates';
import SetupPanel from './panels/SetupPanel';
import OrganizationPanel from './panels/OrganizationPanel';
import BrandPanel from './panels/BrandPanel';
import CurrencyPanel from './panels/CurrencyPanel';
import GatewaysPanel from './panels/GatewaysPanel';
import EmailPanel from './panels/EmailPanel';
import ReceiptsPanel from './panels/ReceiptsPanel';
import NumberingPanel from './panels/NumberingPanel';
import ConsentsPanel from './panels/ConsentsPanel';
import PrivacyPanel from './panels/PrivacyPanel';
import RolesPanel from './panels/RolesPanel';
import {
    IconSetup, IconOrganization, IconCurrency, IconGateways, IconBrand,
    IconEmail, IconReceipt, IconNumbering, IconPrivacy, IconRoles,
    IconExtension,
} from './icons';

// Ordered by how often an operator opens it, money first. Add-on tabs land
// after these.
const TABS = [
    { key: 'setup',        label: __( 'Setup', 'fundkit-fundraising-campaigns' ),                Icon: IconSetup },
    { key: 'gateways',     label: __( 'Payment gateways', 'fundkit-fundraising-campaigns' ),     Icon: IconGateways },
    { key: 'organization', label: __( 'Organization', 'fundkit-fundraising-campaigns' ),         Icon: IconOrganization },
    { key: 'brand',        label: __( 'Brand', 'fundkit-fundraising-campaigns' ),                Icon: IconBrand },
    { key: 'email',        label: __( 'Emails', 'fundkit-fundraising-campaigns' ),               Icon: IconEmail },
    { key: 'receipts',     label: __( 'Receipts', 'fundkit-fundraising-campaigns' ),             Icon: IconReceipt },
    { key: 'currency',     label: __( 'Currency', 'fundkit-fundraising-campaigns' ),             Icon: IconCurrency },
    { key: 'numbering',    label: __( 'Numbering', 'fundkit-fundraising-campaigns' ),            Icon: IconNumbering },
    { key: 'privacy',      label: __( 'Privacy', 'fundkit-fundraising-campaigns' ),              Icon: IconPrivacy },
    { key: 'roles',        label: __( 'Roles', 'fundkit-fundraising-campaigns' ),                Icon: IconRoles, adminOnly: true },
];

// Always last, whatever add-ons register in between.
//
// Roles assigns FundKit capabilities, so the REST route requires full admin. A
// settings manager was still offered the tab and only learned their save was
// refused after editing the grid.
const visibleTabs = () =>
    TABS.filter( ( t ) => ! t.adminOnly || !! window.fundkit?.can?.manage_options );

const TAIL_TABS = [];

// Save-job slug -> human label, for failure messages (job slugs are not tab keys).
const SECTION_LABELS = {
    'org-profile':     __( 'Organization', 'fundkit-fundraising-campaigns' ),
    'org-brand':       __( 'Brand', 'fundkit-fundraising-campaigns' ),
    'currency-locale': __( 'Currency & locale', 'fundkit-fundraising-campaigns' ),
    'exchange-rates':  __( 'Exchange rates', 'fundkit-fundraising-campaigns' ),
    'gateways':        __( 'Payment gateways', 'fundkit-fundraising-campaigns' ),
    'email':           __( 'Emails', 'fundkit-fundraising-campaigns' ),
    'receipts':        __( 'Receipts', 'fundkit-fundraising-campaigns' ),
    'numbering':       __( 'Numbering', 'fundkit-fundraising-campaigns' ),
    'consents':        __( 'Consents', 'fundkit-fundraising-campaigns' ),
    'privacy':         __( 'Data & privacy', 'fundkit-fundraising-campaigns' ),
    'roles':           __( 'Roles & permissions', 'fundkit-fundraising-campaigns' ),
};

function initialTab() {
    const hash = ( window.location.hash || '' ).replace( /^#/, '' ).split( '/' )[ 0 ];
    if ( [ ...visibleTabs(), ...TAIL_TABS ].some( ( t ) => t.key === hash ) ) return hash;
    const params = new URLSearchParams( window.location.search );
    const q = params.get( 'tab' );
    return [ ...visibleTabs(), ...TAIL_TABS ].some( ( t ) => t.key === q ) ? q : 'setup';
}

/**
 * A panel reads its group through fallbacks, so a group that failed to load
 * draws a complete, ordinary looking form of literal defaults: Anonymize IPs
 * on, prefix FUNDKIT, an empty legal name. None of it is this site's settings, and
 * nothing on the screen said so.
 *
 * @since 1.0.0
 */
export function SettingsGroup( { of, children } ) {
    const groups = Array.isArray( of ) ? of : [ of ];
    const failed = groups.find( ( g ) => g.loadError );

    if ( failed ) {
        return (
            <div className="fundkit-panel">
                <Card>
                    <p style={ { color: '#b42318', margin: '0 0 12px' } }>{ failed.loadError }</p>
                    <Btn variant="secondary" onClick={ () => groups.forEach( ( g ) => g.reload?.() ) }>
                        { __( 'Retry', 'fundkit-fundraising-campaigns' ) }
                    </Btn>
                </Card>
            </div>
        );
    }

    if ( groups.some( ( g ) => g.isLoading ) ) {
        return <p>{ __( 'Loading…', 'fundkit-fundraising-campaigns' ) }</p>;
    }

    return children;
}

export default function Settings() {
    const [ tab, setTab ]                 = useState( initialTab );
    const extTabs                         = useExtensionTabs( 'settings' );
    const allTabs                         = [
        ...visibleTabs(),
        // An add-on may ship its own icon component; otherwise it reads as a plug-in.
        ...extTabs.map( ( t ) => ( {
            key: t.id,
            label: t.label,
            Icon: typeof t.icon === 'function' ? t.icon : IconExtension,
        } ) ),
        ...TAIL_TABS,
    ];

    const org      = useFundKitSettings( 'org-profile' );
    const brand    = useFundKitSettings( 'org-brand' );
    const currency = useFundKitSettings( 'currency-locale' );
    const fx       = useFxRates();
    const gateways = useFundKitSettings( 'gateways' );
    const email    = useFundKitSettings( 'email' );
    const receipts = useFundKitSettings( 'receipts' );
    const numbering = useFundKitSettings( 'numbering' );
    const consents = useFundKitSettings( 'consents' );
    const privacy  = useFundKitSettings( 'privacy' );
    const roles    = useFundKitSettings( 'roles' );

    // Re-run when extTabs changes so this closure never holds a stale list: an
    // add-on tab registers after mount, and a hash-only navigation to it never
    // reloads the page.
    useEffect( () => {
        const known = ( h ) =>
            [ ...visibleTabs(), ...TAIL_TABS ].some( ( t ) => t.key === h ) || extTabs.some( ( t ) => t.id === h );
        const read  = () => ( window.location.hash || '' ).replace( /^#/, '' ).split( '/' )[ 0 ];

        const onHash = () => {
            const h = read();
            if ( known( h ) ) setTab( h );
        };
        // Also correct the tab chosen at mount, before add-on tabs existed.
        onHash();

        // And the same correction for ?tab=, which initialTab() accepts but can
        // only validate against the core tabs. Without this, ?tab=receipts
        // works and ?tab=gift-aid silently lands on Setup: the query form is
        // honoured for core tabs and ignored for every add-on one. Nothing in
        // the UI writes these URLs (jumpTo writes the hash), so the ones that
        // exist are hand-written, which is exactly where a silent
        // near-miss costs the most. Hash still wins when both are present.
        if ( ! read() ) {
            const q = new URLSearchParams( window.location.search ).get( 'tab' );
            if ( q && known( q ) ) setTab( q );
        }

        window.addEventListener( 'hashchange', onHash );
        return () => window.removeEventListener( 'hashchange', onHash );
    }, [ extTabs ] );

    const anyDirty = org.isDirty || brand.isDirty || currency.isDirty || fx.isDirty || gateways.isDirty || email.isDirty || receipts.isDirty || numbering.isDirty || consents.isDirty || privacy.isDirty || roles.isDirty;
    useEffect( () => {
        if ( ! anyDirty ) return undefined;
        const handler = ( e ) => { e.preventDefault(); e.returnValue = ''; return ''; };
        window.addEventListener( 'beforeunload', handler );
        return () => window.removeEventListener( 'beforeunload', handler );
    }, [ anyDirty ] );

    const jumpTo = ( next ) => {
        // A row can name a tab this install does not have: an add-on's own tab
        // before its plugin is active, or one nothing in core supplies. Every
        // panel is hidden by tab key, so selecting an unknown one empties the
        // page instead of going anywhere.
        if ( ! allTabs.some( ( t ) => t.key === next ) ) return;
        setTab( next );
        // A jump from a row halfway down Setup would otherwise land mid-panel.
        window.scrollTo( { top: 0 } );
        if ( window.history && window.history.replaceState ) {
            const url = new URL( window.location.href );
            url.hash = next;
            window.history.replaceState( {}, '', url.toString() );
        } else {
            window.location.hash = next;
        }
    };


    const dirtyByTab = useMemo( () => ( {
        organization: org.isDirty,
        brand:        brand.isDirty,
        currency:     currency.isDirty || fx.isDirty,
        gateways:     gateways.isDirty,
        email:        email.isDirty,
        receipts:     receipts.isDirty,
        numbering:    numbering.isDirty,
        privacy:      privacy.isDirty || consents.isDirty,
        roles:        roles.isDirty,
    } ), [ org.isDirty, brand.isDirty, currency.isDirty, fx.isDirty, gateways.isDirty, email.isDirty, receipts.isDirty, numbering.isDirty, consents.isDirty, privacy.isDirty, roles.isDirty ] );

    const dirtySections = Object.values( dirtyByTab ).filter( Boolean ).length;

    const saveAll = async () => {
        const jobs = [];
        if ( org.isDirty )      jobs.push( { name: 'org-profile',     run: org.save } );
        if ( brand.isDirty )    jobs.push( { name: 'org-brand',       run: brand.save } );
        if ( currency.isDirty ) jobs.push( { name: 'currency-locale', run: currency.save } );
        if ( fx.isDirty )       jobs.push( { name: 'exchange-rates',  run: fx.save } );
        if ( gateways.isDirty ) jobs.push( { name: 'gateways',        run: gateways.save } );
        if ( email.isDirty )    jobs.push( { name: 'email',           run: email.save } );
        if ( receipts.isDirty ) jobs.push( { name: 'receipts',        run: receipts.save } );
        if ( numbering.isDirty ) jobs.push( { name: 'numbering',       run: numbering.save } );
        if ( consents.isDirty ) jobs.push( { name: 'consents',        run: consents.save } );
        if ( privacy.isDirty )  jobs.push( { name: 'privacy',         run: privacy.save } );
        if ( roles.isDirty )    jobs.push( { name: 'roles',           run: roles.save } );

        // A base-currency change restates the whole exchange-rate snapshot into
        // the new base. The rates panel composed its numbers in the old one, so
        // the two writes cannot be in flight together: run the currency save
        // first and let the rates save re-read what it landed on.
        const lead = jobs.filter( ( j ) => j.name === 'currency-locale' );
        const rest = jobs.filter( ( j ) => j.name !== 'currency-locale' );
        const ordered = [ ...lead, ...rest ];

        const results = [
            ...( await Promise.allSettled( lead.map( ( j ) => j.run() ) ) ),
            ...( await Promise.allSettled( rest.map( ( j ) => j.run() ) ) ),
        ];
        const failed = results
            .map( ( r, i ) => ( r.status === 'rejected' ? { name: ordered[ i ].name, reason: r.reason } : null ) )
            .filter( Boolean );

        if ( failed.length === 0 ) {
            notify.success( __( 'All changes saved.', 'fundkit-fundraising-campaigns' ) );
            return;
        }

        // Report via toast (not a top-of-page notice) so feedback is visible
        // regardless of scroll position, with the tab labels and server reason.
        const labels = failed.map( ( f ) => SECTION_LABELS[ f.name ] || f.name );
        const reason = failed[ 0 ].reason?.message || '';
        const base = failed.length < jobs.length
            ? sprintf(
                /* translators: %s: comma-separated section names that failed */
                __( 'Could not save: %s.', 'fundkit-fundraising-campaigns' ),
                labels.join( ', ' ),
            )
            : __( 'Save failed.', 'fundkit-fundraising-campaigns' );
        notify.error( reason ? `${ base } ${ reason }` : base );
    };

    const discardAll = () => {
        org.discard();
        brand.discard();
        currency.discard();
        fx.discard();
        gateways.discard();
        email.discard();
        receipts.discard();
        numbering.discard();
        consents.discard();
        privacy.discard();
        roles.discard();
    };

    const anySaving = org.isSaving || brand.isSaving || currency.isSaving || fx.isSaving || gateways.isSaving || email.isSaving || receipts.isSaving || numbering.isSaving || consents.isSaving || privacy.isSaving || roles.isSaving;

    return (
        <div className="fundkit-settings-page">
            <div className="fundkit-crumbs">
                <a href="admin.php?page=fundkit">{ __( 'FundKit', 'fundkit-fundraising-campaigns' ) }</a>
                <span className="sep">›</span>
                <span>{ __( 'Settings', 'fundkit-fundraising-campaigns' ) }</span>
                <span className="sep">›</span>
                <span>{ allTabs.find( ( t ) => t.key === tab )?.label || '' }</span>
            </div>

            <div className="fundkit-page-head">
                <div className="fundkit-page-head__title-row">
                    <h1>{ __( 'Settings', 'fundkit-fundraising-campaigns' ) }</h1>
                </div>
                <div className="fundkit-page-head__right">
                    <span className="fundkit-page-head__meta">
                        { __( 'Changes save when you click Save changes', 'fundkit-fundraising-campaigns' ) }
                    </span>
                </div>
            </div>

            <div
                className="fundkit-tabs"
                role="tablist"
                tabIndex={ -1 }
                aria-label={ __( 'Settings sections', 'fundkit-fundraising-campaigns' ) }
                onKeyDown={ ( e ) => tablistKeyDown( e, allTabs.map( ( t ) => t.key ), tab, jumpTo ) }
            >
                <div className="fundkit-tabs__scroll">
                    { allTabs.map( ( t ) => {
                        const active   = tab === t.key;
                        const isDirty  = !! dirtyByTab[ t.key ];
                        const Icon     = t.Icon;
                        return (
                            <a
                                key={ t.key }
                                href={ `#${ t.key }` }
                                role="tab"
                                aria-selected={ active }
                                tabIndex={ active ? 0 : -1 }
                                className={ active ? 'is-active' : '' }
                                onClick={ ( e ) => { e.preventDefault(); jumpTo( t.key ); } }
                            >
                                <Icon className="fundkit-tab__icon" />
                                { t.label }
                                { isDirty && <span className="fundkit-tab__dot" title={ __( 'Unsaved changes', 'fundkit-fundraising-campaigns' ) } /> }
                            </a>
                        );
                    } ) }
                </div>
            </div>

            <Toaster />

            <div className="fundkit-settings-page__body">
                <div hidden={ tab !== 'setup' }>
                    <SetupPanel onJumpTo={ jumpTo } active={ tab === 'setup' } />
                </div>
                <div hidden={ tab !== 'organization' }>
                    <SettingsGroup of={ org }><OrganizationPanel s={ org } /></SettingsGroup>
                </div>
                <div hidden={ tab !== 'brand' }>
                    <SettingsGroup of={ brand }><BrandPanel s={ brand } /></SettingsGroup>
                </div>
                <div hidden={ tab !== 'currency' }>
                    <SettingsGroup of={ [ currency, fx ] }><CurrencyPanel s={ currency } fx={ fx } /></SettingsGroup>
                </div>
                <div hidden={ tab !== 'gateways' }>
                    <SettingsGroup of={ gateways }><GatewaysPanel s={ gateways } /></SettingsGroup>
                </div>
                <div hidden={ tab !== 'email' }>
                    <SettingsGroup of={ email }><EmailPanel s={ email } /></SettingsGroup>
                </div>
                <div hidden={ tab !== 'receipts' }>
                    <SettingsGroup of={ receipts }><ReceiptsPanel s={ receipts } /></SettingsGroup>
                </div>
                <div hidden={ tab !== 'numbering' }>
                    <SettingsGroup of={ numbering }><NumberingPanel s={ numbering } active={ tab === 'numbering' } /></SettingsGroup>
                </div>
                <div hidden={ tab !== 'privacy' }>
                    <SettingsGroup of={ [ privacy, consents ] }>
                        <div>
                            <PrivacyPanel s={ privacy } />
                            <ConsentsPanel s={ consents } />
                        </div>
                    </SettingsGroup>
                </div>
                <div hidden={ tab !== 'roles' }>
                    <SettingsGroup of={ roles }><RolesPanel s={ roles } /></SettingsGroup>
                </div>
                { extTabs.map( ( t ) => (
                    <div key={ t.id } hidden={ tab !== t.id }>
                        <ExtensionTabPanel tab={ t } context={ {} } />
                    </div>
                ) ) }
            </div>

            { dirtySections > 0 && (
                <div className="fundkit-save-bar" role="status" aria-live="polite">
                    <span className="fundkit-save-bar__dot" aria-hidden="true" />
                    <span className="fundkit-save-bar__count">
                        { dirtySections === 1
                            ? __( 'Unsaved changes in 1 section', 'fundkit-fundraising-campaigns' )
                            : sprintf(
                                /* translators: %d: number of sections with unsaved changes */
                                _n( 'Unsaved changes across %d section', 'Unsaved changes across %d sections', dirtySections, 'fundkit-fundraising-campaigns' ),
                                dirtySections,
                            ) }
                    </span>
                    <button
                        type="button"
                        className="fundkit-save-bar__btn fundkit-save-bar__btn--ghost"
                        onClick={ discardAll }
                        disabled={ anySaving }
                    >
                        { __( 'Discard', 'fundkit-fundraising-campaigns' ) }
                    </button>
                    <button
                        type="button"
                        className="fundkit-save-bar__btn fundkit-save-bar__btn--primary"
                        onClick={ saveAll }
                        disabled={ anySaving }
                    >
                        { __( 'Save changes', 'fundkit-fundraising-campaigns' ) }
                    </button>
                </div>
            ) }
        </div>
    );
}
