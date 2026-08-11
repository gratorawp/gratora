import { useCallback, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import Toaster from '../_shared/components/Toaster';
import ExportTab from './tabs/ExportTab';
import ImportTab from './tabs/ImportTab';
import LogsTab from './tabs/LogsTab';
import MaintenanceTab from './tabs/MaintenanceTab';
import SystemInfoTab from './tabs/SystemInfoTab';
import WebhooksTab from './tabs/WebhooksTab';

const TABS = [
    { key: 'maintenance', label: __( 'Maintenance', 'dono' ) },
    { key: 'logs',        label: __( 'Logs', 'dono' ) },
    { key: 'webhooks',    label: __( 'Webhooks', 'dono' ) },
    { key: 'system',      label: __( 'System info', 'dono' ) },
    { key: 'export',      label: __( 'Export', 'dono' ) },
    { key: 'import',      label: __( 'Import', 'dono' ) },
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
        apiFetch( { path: '/dono/v1/admin/tools/info' } )
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
        <div className="dono-settings-page">
            <div className="dono-crumbs">
                <a href="admin.php?page=dono">{ __( 'Dono', 'dono' ) }</a>
                <span className="sep">›</span>
                <span>{ __( 'Tools', 'dono' ) }</span>
                <span className="sep">›</span>
                <span>{ TABS.find( ( t ) => t.key === tab )?.label || '' }</span>
            </div>

            <div className="dono-page-head">
                <div className="dono-page-head__title-row">
                    <h1>{ __( 'Tools', 'dono' ) }</h1>
                </div>
            </div>

            <div className="dono-tabs" role="tablist" aria-label={ __( 'Tools sections', 'dono' ) }>
                <div className="dono-tabs__scroll">
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
                <div className={ `dono-advanced-notice dono-advanced-notice--${ notice.type }` }>
                    { notice.text }
                </div>
            ) }

            <div className="dono-settings-page__body">
                <div hidden={ tab !== 'maintenance' }><MaintenanceTab { ...shared } active={ tab === 'maintenance' } /></div>
                <div hidden={ tab !== 'logs' }><LogsTab { ...shared } active={ tab === 'logs' } /></div>
                <div hidden={ tab !== 'webhooks' }><WebhooksTab { ...shared } active={ tab === 'webhooks' } /></div>
                <div hidden={ tab !== 'system' }><SystemInfoTab { ...shared } /></div>
                <div hidden={ tab !== 'export' }><ExportTab { ...shared } /></div>
                <div hidden={ tab !== 'import' }><ImportTab { ...shared } /></div>
            </div>
        </div>
    );
}
