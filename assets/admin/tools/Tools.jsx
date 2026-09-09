import { useCallback, useEffect, useState } from '@wordpress/element';
import { tablistKeyDown } from '../_shared/tablistKeys';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import Notice from '../_shared/components/Notice';
import Toaster from '../_shared/components/Toaster';
import ExportTab from './tabs/ExportTab';
import ImportTab from './tabs/ImportTab';
import LogsTab from './tabs/LogsTab';
import MaintenanceTab from './tabs/MaintenanceTab';
import SystemInfoTab from './tabs/SystemInfoTab';
import { userCan } from '../_shared/caps';

// Import restores a settings file that carries the role mapping and can grant
// capabilities, so every route behind that tab wants a full administrator. A
// settings manager reaching it finds a screen where nothing works.
const TABS = [
    { key: 'maintenance', label: __( 'Maintenance', 'gratora' ) },
    { key: 'logs',        label: __( 'Logs', 'gratora' ) },
    { key: 'system',      label: __( 'System info', 'gratora' ) },
    { key: 'export',      label: __( 'Export', 'gratora' ) },
    ...( userCan( 'manage_options' ) ? [ { key: 'import', label: __( 'Import', 'gratora' ) } ] : [] ),
];

const fromHash = () => {
    const key = ( window.location.hash || '' ).replace( '#', '' );
    return TABS.some( ( t ) => t.key === key ) ? key : TABS[ 0 ].key;
};

export default function Tools() {
    const [ tab, setTab ] = useState( fromHash );
    const [ info, setInfo ] = useState( null );
    const [ infoError, setInfoError ] = useState( false );
    const [ notice, setNotice ] = useState( null );

    const loadInfo = useCallback( () => {
        setInfoError( false );
        apiFetch( { path: '/gratora/v1/admin/tools/info' } )
            .then( setInfo )
            .catch( () => setInfoError( true ) );
    }, [] );

    useEffect( () => { loadInfo(); }, [ loadInfo ] );

    useEffect( () => {
        const onHash = () => setTab( fromHash() );
        window.addEventListener( 'hashchange', onHash );
        return () => window.removeEventListener( 'hashchange', onHash );
    }, [] );

    const jumpTo = ( key ) => {
        window.location.hash = key;
        setTab( key );
    };

    const shared = { info, infoError, loadInfo, notice, setNotice };

    return (
        <div className="gratora-settings-page">
            <div className="gratora-crumbs">
                <a href="admin.php?page=gratora">{ __( 'Fundraising', 'gratora' ) }</a>
                <span className="sep">›</span>
                <span>{ __( 'Tools', 'gratora' ) }</span>
                <span className="sep">›</span>
                <span>{ TABS.find( ( t ) => t.key === tab )?.label || '' }</span>
            </div>

            <div className="gratora-page-head">
                <div className="gratora-page-head__title-row">
                    <h1>{ __( 'Tools', 'gratora' ) }</h1>
                </div>
            </div>

            <div
                className="gratora-tabs"
                role="tablist"
                tabIndex={ -1 }
                aria-label={ __( 'Tools sections', 'gratora' ) }
                onKeyDown={ ( e ) => tablistKeyDown( e, TABS.map( ( t ) => t.key ), tab, jumpTo ) }
            >
                <div className="gratora-tabs__scroll">
                    { TABS.map( ( t ) => (
                        <a
                            key={ t.key }
                            href={ `#${ t.key }` }
                            role="tab"
                            aria-selected={ tab === t.key }
                            tabIndex={ tab === t.key ? 0 : -1 }
                            className={ tab === t.key ? 'is-active' : '' }
                            onClick={ ( e ) => { e.preventDefault(); jumpTo( t.key ); } }
                        >
                            { t.label }
                        </a>
                    ) ) }
                </div>
            </div>

            <Toaster />

            { notice && (
                <Notice status={ notice.type } onRemove={ () => setNotice( null ) }>
                    { notice.text }
                </Notice>
            ) }

            <div className="gratora-settings-page__body">
                <div hidden={ tab !== 'maintenance' }><MaintenanceTab { ...shared } active={ tab === 'maintenance' } /></div>
                <div hidden={ tab !== 'logs' }><LogsTab { ...shared } active={ tab === 'logs' } /></div>
                <div hidden={ tab !== 'system' }><SystemInfoTab { ...shared } /></div>
                <div hidden={ tab !== 'export' }><ExportTab { ...shared } /></div>
                { userCan( 'manage_options' ) && (
                    <div hidden={ tab !== 'import' }><ImportTab { ...shared } /></div>
                ) }
            </div>
        </div>
    );
}
