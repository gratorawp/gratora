import { useState, useEffect, useMemo } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';

import Toaster from '../_shared/components/Toaster';
import { notify } from '../_shared/notify';
import { tablistKeyDown } from '../_shared/tablistKeys';
import { useExtensionTabs, ExtensionTabPanel } from '../_shared/extensionTabs';
import Licenses from '../licenses/Licenses';

import { useDonoSettings } from '../_shared/useDonoSettings';
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
import AdvancedPanel from './panels/AdvancedPanel';
import {
    IconSetup, IconOrganization, IconCurrency, IconGateways, IconBrand,
    IconEmail, IconReceipt, IconNumbering, IconPrivacy, IconRoles,
    IconLicense, IconExtension, IconAdvanced,
} from './icons';

// Ordered by how often an operator opens it, money first. Add-on tabs land
// after these, ahead of Licenses and Advanced.
const TABS = [
    { key: 'setup',        label: __( 'Setup', 'dono' ),                Icon: IconSetup },
    { key: 'gateways',     label: __( 'Payments', 'dono' ),             Icon: IconGateways },
    { key: 'organization', label: __( 'Organization', 'dono' ),         Icon: IconOrganization },
    { key: 'brand',        label: __( 'Brand', 'dono' ),                Icon: IconBrand },
    { key: 'email',        label: __( 'Emails', 'dono' ),               Icon: IconEmail },
    { key: 'receipts',     label: __( 'Receipts', 'dono' ),             Icon: IconReceipt },
    { key: 'currency',     label: __( 'Currency', 'dono' ),             Icon: IconCurrency },
    { key: 'numbering',    label: __( 'Numbering', 'dono' ),            Icon: IconNumbering },
    { key: 'privacy',      label: __( 'Privacy', 'dono' ),              Icon: IconPrivacy },
    { key: 'roles',        label: __( 'Roles', 'dono' ),                Icon: IconRoles },
];

// Always last, whatever add-ons register in between.
const TAIL_TABS = [
    { key: 'licenses',     label: __( 'Licenses', 'dono' ),             Icon: IconLicense },
    { key: 'advanced',     label: __( 'Advanced', 'dono' ),             Icon: IconAdvanced },
];

// Save-job slug -> human label, for failure messages (job slugs are not tab keys).
const SECTION_LABELS = {
    'org-profile':     __( 'Organization', 'dono' ),
    'org-brand':       __( 'Brand', 'dono' ),
    'currency-locale': __( 'Currency & locale', 'dono' ),
    'exchange-rates':  __( 'Exchange rates', 'dono' ),
    'gateways':        __( 'Payment gateways', 'dono' ),
    'email':           __( 'Emails', 'dono' ),
    'receipts':        __( 'Receipts', 'dono' ),
    'numbering':       __( 'Numbering', 'dono' ),
    'consents':        __( 'Consents', 'dono' ),
    'privacy':         __( 'Data & privacy', 'dono' ),
    'roles':           __( 'Roles & permissions', 'dono' ),
    'advanced':        __( 'Advanced', 'dono' ),
};

function initialTab() {
    const hash = ( window.location.hash || '' ).replace( /^#/, '' ).split( '/' )[ 0 ];
    if ( [ ...TABS, ...TAIL_TABS ].some( ( t ) => t.key === hash ) ) return hash;
    const params = new URLSearchParams( window.location.search );
    const q = params.get( 'tab' );
    return [ ...TABS, ...TAIL_TABS ].some( ( t ) => t.key === q ) ? q : 'setup';
}

export default function Settings() {
    const [ tab, setTab ]                 = useState( initialTab );
    const extTabs                         = useExtensionTabs( 'settings' );
    const allTabs                         = [
        ...TABS,
        // An add-on may ship its own icon component; otherwise it reads as a plug-in.
        ...extTabs.map( ( t ) => ( {
            key: t.id,
            label: t.label,
            Icon: typeof t.icon === 'function' ? t.icon : IconExtension,
        } ) ),
        ...TAIL_TABS,
    ];

    const org      = useDonoSettings( 'org-profile' );
    const brand    = useDonoSettings( 'org-brand' );
    const currency = useDonoSettings( 'currency-locale' );
    const fx       = useFxRates();
    const gateways = useDonoSettings( 'gateways' );
    const email    = useDonoSettings( 'email' );
    const receipts = useDonoSettings( 'receipts' );
    const numbering = useDonoSettings( 'numbering' );
    const consents = useDonoSettings( 'consents' );
    const privacy  = useDonoSettings( 'privacy' );
    const roles    = useDonoSettings( 'roles' );
    const advanced = useDonoSettings( 'advanced' );

    // Re-run when extTabs changes so this closure never holds a stale list: an
    // add-on tab registers after mount, and a hash-only navigation to it never
    // reloads the page.
    useEffect( () => {
        const known = ( h ) =>
            [ ...TABS, ...TAIL_TABS ].some( ( t ) => t.key === h ) || extTabs.some( ( t ) => t.id === h );
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

    const anyDirty = org.isDirty || brand.isDirty || currency.isDirty || fx.isDirty || gateways.isDirty || email.isDirty || receipts.isDirty || numbering.isDirty || consents.isDirty || privacy.isDirty || roles.isDirty || advanced.isDirty;
    useEffect( () => {
        if ( ! anyDirty ) return undefined;
        const handler = ( e ) => { e.preventDefault(); e.returnValue = ''; return ''; };
        window.addEventListener( 'beforeunload', handler );
        return () => window.removeEventListener( 'beforeunload', handler );
    }, [ anyDirty ] );

    const jumpTo = ( next ) => {
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
        advanced:     advanced.isDirty,
    } ), [ org.isDirty, brand.isDirty, currency.isDirty, fx.isDirty, gateways.isDirty, email.isDirty, receipts.isDirty, numbering.isDirty, consents.isDirty, privacy.isDirty, roles.isDirty, advanced.isDirty ] );

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
        if ( advanced.isDirty ) jobs.push( { name: 'advanced',        run: advanced.save } );

        const results = await Promise.allSettled( jobs.map( ( j ) => j.run() ) );
        const failed = results
            .map( ( r, i ) => ( r.status === 'rejected' ? { name: jobs[ i ].name, reason: r.reason } : null ) )
            .filter( Boolean );

        if ( failed.length === 0 ) {
            notify.success( __( 'All changes saved.', 'dono' ) );
            return;
        }

        // Report via toast (not a top-of-page notice) so feedback is visible
        // regardless of scroll position, with the tab labels and server reason.
        const labels = failed.map( ( f ) => SECTION_LABELS[ f.name ] || f.name );
        const reason = failed[ 0 ].reason?.message || '';
        const base = failed.length < jobs.length
            ? sprintf(
                /* translators: %s: comma-separated section names that failed */
                __( 'Could not save: %s.', 'dono' ),
                labels.join( ', ' ),
            )
            : __( 'Save failed.', 'dono' );
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
        advanced.discard();
    };

    const anySaving = org.isSaving || brand.isSaving || currency.isSaving || fx.isSaving || gateways.isSaving || email.isSaving || receipts.isSaving || numbering.isSaving || consents.isSaving || privacy.isSaving || roles.isSaving || advanced.isSaving;

    return (
        <div className="dono-settings-page">
            <div className="dono-crumbs">
                <a href="admin.php?page=dono">{ __( 'Dono', 'dono' ) }</a>
                <span className="sep">›</span>
                <span>{ __( 'Settings', 'dono' ) }</span>
                <span className="sep">›</span>
                <span>{ allTabs.find( ( t ) => t.key === tab )?.label || '' }</span>
            </div>

            <div className="dono-page-head">
                <div className="dono-page-head__title-row">
                    <h1>{ __( 'Settings', 'dono' ) }</h1>
                </div>
                <div className="dono-page-head__right">
                    <span className="dono-page-head__meta">
                        { __( 'Changes save when you click Save changes', 'dono' ) }
                    </span>
                </div>
            </div>

            <div
                className="dono-tabs"
                role="tablist"
                tabIndex={ -1 }
                aria-label={ __( 'Settings sections', 'dono' ) }
                onKeyDown={ ( e ) => tablistKeyDown( e, allTabs.map( ( t ) => t.key ), tab, jumpTo ) }
            >
                <div className="dono-tabs__scroll">
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
                                <Icon className="dono-tab__icon" />
                                { t.label }
                                { isDirty && <span className="dono-tab__dot" title={ __( 'Unsaved changes', 'dono' ) } /> }
                            </a>
                        );
                    } ) }
                </div>
            </div>

            <Toaster />

            <div className="dono-settings-page__body">
                <div hidden={ tab !== 'setup' }>
                    <SetupPanel onJumpTo={ jumpTo } />
                </div>
                <div hidden={ tab !== 'organization' }>
                    <OrganizationPanel s={ org } />
                </div>
                <div hidden={ tab !== 'brand' }>
                    <BrandPanel s={ brand } />
                </div>
                <div hidden={ tab !== 'currency' }>
                    <CurrencyPanel s={ currency } fx={ fx } />
                </div>
                <div hidden={ tab !== 'gateways' }>
                    <GatewaysPanel s={ gateways } />
                </div>
                <div hidden={ tab !== 'email' }>
                    <EmailPanel s={ email } />
                </div>
                <div hidden={ tab !== 'receipts' }>
                    <ReceiptsPanel s={ receipts } />
                </div>
                <div hidden={ tab !== 'numbering' }>
                    <NumberingPanel s={ numbering } />
                </div>
                <div hidden={ tab !== 'privacy' }>
                    <PrivacyPanel s={ privacy } />
                    <ConsentsPanel s={ consents } />
                </div>
                <div hidden={ tab !== 'roles' }>
                    <RolesPanel s={ roles } />
                </div>
                <div hidden={ tab !== 'advanced' }>
                    <AdvancedPanel s={ advanced } />
                </div>
                <div hidden={ tab !== 'licenses' }>
                    <Licenses />
                </div>
                { extTabs.map( ( t ) => (
                    <div key={ t.id } hidden={ tab !== t.id }>
                        <ExtensionTabPanel tab={ t } context={ {} } />
                    </div>
                ) ) }
            </div>

            { dirtySections > 0 && (
                <div className="dono-save-bar" role="status" aria-live="polite">
                    <span className="dono-save-bar__dot" aria-hidden="true" />
                    <span className="dono-save-bar__count">
                        { dirtySections === 1
                            ? __( 'Unsaved changes in 1 section', 'dono' )
                            : sprintf(
                                /* translators: %d: number of sections with unsaved changes */
                                _n( 'Unsaved changes across %d section', 'Unsaved changes across %d sections', dirtySections, 'dono' ),
                                dirtySections,
                            ) }
                    </span>
                    <button
                        type="button"
                        className="dono-save-bar__btn dono-save-bar__btn--ghost"
                        onClick={ discardAll }
                        disabled={ anySaving }
                    >
                        { __( 'Discard', 'dono' ) }
                    </button>
                    <button
                        type="button"
                        className="dono-save-bar__btn dono-save-bar__btn--primary"
                        onClick={ saveAll }
                        disabled={ anySaving }
                    >
                        { __( 'Save changes', 'dono' ) }
                    </button>
                </div>
            ) }
        </div>
    );
}
