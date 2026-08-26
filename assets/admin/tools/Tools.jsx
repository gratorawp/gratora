import { useCallback, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import Toaster from '../_shared/components/Toaster';
import ExportTab from './tabs/ExportTab';
import ImportTab from './tabs/ImportTab';
import LogsTab from './tabs/LogsTab';
import MaintenanceTab from './tabs/MaintenanceTab';
import SystemInfoTab from './tabs/SystemInfoTab';

const TABS = [
    { key: 'maintenance', label: __( 'Maintenance', 'giveflow-fundraising-campaigns' ) },
    { key: 'logs',        label: __( 'Logs', 'giveflow-fundraising-campaigns' ) },
    { key: 'system',      label: __( 'System info', 'giveflow-fundraising-campaigns' ) },
    { key: 'export',      label: __( 'Export', 'giveflow-fundraising-campaigns' ) },
    { key: 'import',      label: __( 'Import', 'giveflow-fundraising-campaigns' ) },
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
        apiFetch( { path: '/giveflow/v1/admin/tools/info' } )
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
        <div className="giveflow-settings-page">
            <div className="giveflow-crumbs">
                <a href="admin.php?page=giveflow">{ __( 'GiveFlow', 'giveflow-fundraising-campaigns' ) }</a>
                <span className="sep">›</span>
                <span>{ __( 'Tools', 'giveflow-fundraising-campaigns' ) }</span>
                <span className="sep">›</span>
                <span>{ TABS.find( ( t ) => t.key === tab )?.label || '' }</span>
            </div>

            <div className="giveflow-page-head">
                <div className="giveflow-page-head__title-row">
                    <h1>{ __( 'Tools', 'giveflow-fundraising-campaigns' ) }</h1>
                </div>
            </div>

            <div className="giveflow-tabs" role="tablist" aria-label={ __( 'Tools sections', 'giveflow-fundraising-campaigns' ) }>
                <div className="giveflow-tabs__scroll">
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
                <div className={ `giveflow-advanced-notice giveflow-advanced-notice--${ notice.type }` }>
                    { notice.text }
                </div>
            ) }

            <div className="giveflow-settings-page__body">
                <div hidden={ tab !== 'maintenance' }><MaintenanceTab { ...shared } active={ tab === 'maintenance' } /></div>
                <div hidden={ tab !== 'logs' }><LogsTab { ...shared } active={ tab === 'logs' } /></div>
                <div hidden={ tab !== 'system' }><SystemInfoTab { ...shared } /></div>
                <div hidden={ tab !== 'export' }><ExportTab { ...shared } /></div>
                <div hidden={ tab !== 'import' }><ImportTab { ...shared } /></div>
            </div>
        </div>
    );
}
