import { useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';

import Card from '../../_shared/components/Card';
import Btn from '../../_shared/components/Btn';

export default function ImportTab( { setNotice } ) {
    const [ importing, setImporting ] = useState( false );
    const fileRef = useRef( null );

    const doImport = async ( file ) => {
        if ( ! file ) return;
        setImporting( true );
        setNotice( null );
        try {
            const parsed = JSON.parse( await file.text() );
            const res = await apiFetch( {
                path:   '/dono/v1/admin/tools/import',
                method: 'POST',
                data:   parsed,
            } );

            const applied = Number( res?.applied ) || 0;
            setNotice( applied > 0
                ? {
                    type: 'success',
                    text: sprintf(
                        /* translators: %d: number of settings groups restored. */
                        _n(
                            '%d settings group restored. Reload the page to see it.',
                            '%d settings groups restored. Reload the page to see them.',
                            applied,
                            'dono'
                        ),
                        applied
                    ),
                }
                : {
                    type: 'error',
                    text: __( 'Nothing in that file matched a Dono setting.', 'dono' ),
                }
            );
        } catch ( err ) {
            setNotice( {
                type: 'error',
                text: err?.message || __( 'Import failed. Check that the file is a Dono settings export.', 'dono' ),
            } );
        } finally {
            setImporting( false );
            if ( fileRef.current ) fileRef.current.value = '';
        }
    };

    return (
        <div className="dono-panel">
            <Card
                title={ __( 'Import settings', 'dono' ) }
                sub={ __( 'Reads a Dono settings export and replaces the matching settings on this site. Donations, donors and campaigns are left alone.', 'dono' ) }
            >
                <div className="dono-advanced-actions">
                    <Btn
                        variant="secondary"
                        onClick={ () => fileRef.current?.click() }
                        disabled={ importing }
                        isBusy={ importing }
                    >
                        { importing ? __( 'Importing…', 'dono' ) : __( 'Choose a JSON file', 'dono' ) }
                    </Btn>
                    <input
                        ref={ fileRef }
                        type="file"
                        accept="application/json,.json"
                        style={ { display: 'none' } }
                        onChange={ ( e ) => doImport( e.target.files?.[ 0 ] ) }
                    />
                </div>
                <p className="dono-tools-note">
                    { __( 'A masked secret in the file leaves the stored key untouched, so importing an export cannot wipe a gateway key it was unable to carry.', 'dono' ) }
                </p>
            </Card>
        </div>
    );
}
