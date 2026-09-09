import { useState, useEffect, useMemo } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';

import Btn from '../_shared/components/Btn';
import Card from '../_shared/components/Card';
import Toaster from '../_shared/components/Toaster';
import { notify } from '../_shared/notify';
import { tablistKeyDown } from '../_shared/tablistKeys';
import { useExtensionTabs, ExtensionTabPanel } from '../_shared/extensionTabs';

import { useGratoraSettings } from '../_shared/useGratoraSettings';
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
    { key: 'setup',        label: __( 'Setup', 'gratora' ),                Icon: IconSetup },
    { key: 'gateways',     label: __( 'Payment gateways', 'gratora' ),     Icon: IconGateways },
    { key: 'organization', label: __( 'Organization', 'gratora' ),         Icon: IconOrganization },
    { key: 'brand',        label: __( 'Brand', 'gratora' ),                Icon: IconBrand },
    { key: 'email',        label: __( 'Emails', 'gratora' ),               Icon: IconEmail },
    { key: 'receipts',     label: __( 'Receipts', 'gratora' ),             Icon: IconReceipt },
    { key: 'currency',     label: __( 'Currency', 'gratora' ),             Icon: IconCurrency },
    { key: 'numbering',    label: __( 'Numbering', 'gratora' ),            Icon: IconNumbering },
    { key: 'privacy',      label: __( 'Privacy', 'gratora' ),              Icon: IconPrivacy },
    { key: 'roles',        label: __( 'Roles', 'gratora' ),                Icon: IconRoles, adminOnly: true },
];

// Always last, whatever add-ons register in between.
//
// Roles assigns Gratora capabilities, so the REST route requires full admin. A
// settings manager was still offered the tab and only learned their save was
// refused after editing the grid.
const visibleTabs = () =>
    TABS.filter( ( t ) => ! t.adminOnly || !! window.gratora?.can?.manage_options );

const TAIL_TABS = [];

// Save-job slug -> human label, for failure messages (job slugs are not tab keys).
const SECTION_LABELS = {
    'org-profile':     __( 'Organization', 'gratora' ),
    'org-brand':       __( 'Brand', 'gratora' ),
    'currency-locale': __( 'Currency & locale', 'gratora' ),
    'exchange-rates':  __( 'Exchange rates', 'gratora' ),
    'gateways':        __( 'Payment gateways', 'gratora' ),
    'email':           __( 'Emails', 'gratora' ),
    'receipts':        __( 'Receipts', 'gratora' ),
    'numbering':       __( 'Numbering', 'gratora' ),
    'consents':        __( 'Consents', 'gratora' ),
    'privacy':         __( 'Data & privacy', 'gratora' ),
    'roles':           __( 'Roles & permissions', 'gratora' ),
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
 * on, prefix GRATORA, an empty legal name. None of it is this site's settings, and
 * nothing on the screen said so.
 *
 * @since 1.0.0
 */
export function SettingsGroup( { of, children } ) {
    const groups = Array.isArray( of ) ? of : [ of ];
    const failed = groups.find( ( g ) => g.loadError );

    if ( failed ) {
        return (
            <div className="gratora-panel">
                <Card>
                    <p style={ { color: '#b42318', margin: '0 0 12px' } }>{ failed.loadError }</p>
                    <Btn variant="secondary" onClick={ () => groups.forEach( ( g ) => g.reload?.() ) }>
                        { __( 'Retry', 'gratora' ) }
                    </Btn>
                </Card>
            </div>
        );
    }

    if ( groups.some( ( g ) => g.isLoading ) ) {
        return <PanelSkeleton />;
    }

    return children;
}

/** Reserve panel space while loading; hide decorative skeletons from assistive technology. */
function PanelSkeleton() {
    return (
        <>
            <p className="screen-reader-text" role="status">
                { __( 'Loading settings…', 'gratora' ) }
            </p>
            <div className="gratora-card" aria-hidden="true">
                <div className="gratora-card__head">
                    <div className="gratora-card__head-left">
                        <span className="gratora-skeleton gratora-skeleton--lg" />
                        <span className="gratora-skeleton" style={ { display: 'block', marginTop: 8, width: 220 } } />
                    </div>
                </div>
                <div className="gratora-card__body">
                    { [ 0, 1, 2 ].map( ( i ) => (
                        <div className="gratora-form-row" key={ i }>
                            <div className="gratora-form-row__label">
                                <span className="gratora-skeleton" />
                            </div>
                            <div className="gratora-form-row__field">
                                <span className="gratora-skeleton" style={ { width: '100%', height: 32 } } />
                            </div>
                        </div>
                    ) ) }
                </div>
            </div>
        </>
    );
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

    const org      = useGratoraSettings( 'org-profile' );
    const brand    = useGratoraSettings( 'org-brand' );
    const currency = useGratoraSettings( 'currency-locale' );
    const fx       = useFxRates();
    const gateways = useGratoraSettings( 'gateways' );
    const email    = useGratoraSettings( 'email' );
    const receipts = useGratoraSettings( 'receipts' );
    const numbering = useGratoraSettings( 'numbering' );
    const consents = useGratoraSettings( 'consents' );
    const privacy  = useGratoraSettings( 'privacy' );
    const roles    = useGratoraSettings( 'roles' );

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

        // Resolve ?tab= against add-on tabs too; the hash takes precedence.
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
            notify.success( __( 'All changes saved.', 'gratora' ) );
            return;
        }

        // Report via toast (not a top-of-page notice) so feedback is visible
        // regardless of scroll position, with the tab labels and server reason.
        const labels = failed.map( ( f ) => SECTION_LABELS[ f.name ] || f.name );
        const reason = failed[ 0 ].reason?.message || '';
        const base = failed.length < jobs.length
            ? sprintf(
                /* translators: %s: comma-separated section names that failed */
                __( 'Could not save: %s.', 'gratora' ),
                labels.join( ', ' ),
            )
            : __( 'Save failed.', 'gratora' );
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
        <div className="gratora-settings-page">
            <div className="gratora-crumbs">
                <a href="admin.php?page=gratora">{ __( 'Fundraising', 'gratora' ) }</a>
                <span className="sep">›</span>
                <span>{ __( 'Settings', 'gratora' ) }</span>
                <span className="sep">›</span>
                <span>{ allTabs.find( ( t ) => t.key === tab )?.label || '' }</span>
            </div>

            <div className="gratora-page-head">
                <div className="gratora-page-head__title-row">
                    <h1>{ __( 'Settings', 'gratora' ) }</h1>
                </div>
                <div className="gratora-page-head__right">
                    <span className="gratora-page-head__meta">
                        { __( 'Changes save when you click Save changes', 'gratora' ) }
                    </span>
                </div>
            </div>

            <div
                className="gratora-tabs"
                role="tablist"
                tabIndex={ -1 }
                aria-label={ __( 'Settings sections', 'gratora' ) }
                onKeyDown={ ( e ) => tablistKeyDown( e, allTabs.map( ( t ) => t.key ), tab, jumpTo ) }
            >
                <div className="gratora-tabs__scroll">
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
                                <Icon className="gratora-tab__icon" />
                                { t.label }
                                { isDirty && <span className="gratora-tab__dot" title={ __( 'Unsaved changes', 'gratora' ) } /> }
                            </a>
                        );
                    } ) }
                </div>
            </div>

            <Toaster />

            <div className="gratora-settings-page__body">
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
                <div className="gratora-save-bar" role="status" aria-live="polite">
                    <span className="gratora-save-bar__dot" aria-hidden="true" />
                    <span className="gratora-save-bar__count">
                        { dirtySections === 1
                            ? __( 'Unsaved changes in 1 section', 'gratora' )
                            : sprintf(
                                /* translators: %d: number of sections with unsaved changes */
                                _n( 'Unsaved changes across %d section', 'Unsaved changes across %d sections', dirtySections, 'gratora' ),
                                dirtySections,
                            ) }
                    </span>
                    <button
                        type="button"
                        className="gratora-save-bar__btn gratora-save-bar__btn--ghost"
                        onClick={ discardAll }
                        disabled={ anySaving }
                    >
                        { __( 'Discard', 'gratora' ) }
                    </button>
                    <button
                        type="button"
                        className="gratora-save-bar__btn gratora-save-bar__btn--primary"
                        onClick={ saveAll }
                        disabled={ anySaving }
                    >
                        { __( 'Save changes', 'gratora' ) }
                    </button>
                </div>
            ) }
        </div>
    );
}
