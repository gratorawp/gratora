import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import Card from '../../_shared/components/Card';
import Btn from '../../_shared/components/Btn';

export default function ExportTab( { setNotice } ) {
    const [ exporting, setExporting ] = useState( false );

    const doExport = async () => {
        setExporting( true );
        setNotice( null );
        try {
            const data = await apiFetch( { path: '/dono/v1/admin/tools/export' } );
            const blob = new Blob( [ JSON.stringify( data, null, 2 ) ], { type: 'application/json' } );
            const url  = URL.createObjectURL( blob );
            const a    = document.createElement( 'a' );
            const ts   = new Date().toISOString().replace( /[:.]/g, '-' ).slice( 0, 19 );
            a.href     = url;
            a.download = `dono-settings-${ ts }.json`;
            a.click();
            URL.revokeObjectURL( url );
            setNotice( { type: 'success', text: __( 'Settings exported.', 'dono' ) } );
        } catch ( err ) {
            setNotice( { type: 'error', text: err?.message || __( 'Export failed.', 'dono' ) } );
        } finally {
            setExporting( false );
        }
    };

    return (
        <div className="dono-panel">
            <Card
                title={ __( 'Export settings', 'dono' ) }
                sub={ __( 'Downloads every Dono setting as JSON: gateways, receipts, currency, numbering, privacy, roles. Donations, donors and campaigns are not included.', 'dono' ) }
            >
                <div className="dono-advanced-actions">
                    <Btn variant="secondary" onClick={ doExport } disabled={ exporting } isBusy={ exporting }>
                        { exporting ? __( 'Exporting…', 'dono' ) : __( 'Download settings JSON', 'dono' ) }
                    </Btn>
                </div>
                <p className="dono-tools-note">
                    { __( 'Secrets are masked. A gateway key never leaves the site in an export, so an imported file cannot restore one.', 'dono' ) }
                </p>
            </Card>
        </div>
    );
}
