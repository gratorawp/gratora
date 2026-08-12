import { useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';

import Card from '../../_shared/components/Card';
import Btn from '../../_shared/components/Btn';
import ConfirmDialog from '../../_shared/components/ConfirmDialog';
import CsvImportCard from './CsvImportCard';

export default function ImportTab( { setNotice } ) {
    const [ importing, setImporting ] = useState( false );
    const [ confirm, setConfirm ]     = useState( null );
    const fileRef = useRef( null );

    // Picking a file used to be the whole interaction: the overwrite ran on
    // change, before the admin could read what they had chosen. It replaces
    // live gateway, email, receipt and role configuration and there is no undo,
    // so the file is named back to them first.
    // Read before asking, because the two kinds of file do different things and
    // the settings wording ("donors and campaigns are untouched") is a lie
    // about a full export.
    const askImport = async ( file ) => {
        if ( ! file ) return;

        let parsed;
        try {
            parsed = JSON.parse( await file.text() );
        } catch ( e ) {
            setNotice( { type: 'error', text: __( 'That file is not JSON. Use a file the Export tab produced.', 'dono-fundraising-platform' ) } );
            return;
        }

        const isFullExport = !! parsed?.tables;

        setConfirm( {
            title: isFullExport
                ? __( 'Restore records from this file', 'dono-fundraising-platform' )
                : __( 'Replace settings from this file', 'dono-fundraising-platform' ),
            message: isFullExport
                ? sprintf(
                    /* translators: %s: the chosen file name. */
                    __( '%s will add its campaigns, funds, forms, donors, donations, recurring plans and receipts to this site. Anything already here is left as it is, so running it twice is safe. Donors erased on this site stay erased.', 'dono-fundraising-platform' ),
                    file.name
                )
                : sprintf(
                    /* translators: %s: the chosen file name. */
                    __( '%s will overwrite your gateway, email, receipt, numbering and role settings. Donations, donors and campaigns are untouched. This cannot be undone.', 'dono-fundraising-platform' ),
                    file.name
                ),
            confirmLabel: isFullExport ? __( 'Restore', 'dono-fundraising-platform' ) : __( 'Replace settings', 'dono-fundraising-platform' ),
            destructive:  ! isFullExport,
            onConfirm:    () => doImport( parsed ),
        } );
    };

    const doImport = async ( parsed ) => {
        if ( ! parsed ) return;
        setImporting( true );
        setNotice( null );
        try {
            const res = await apiFetch( {
                path:   '/dono/v1/admin/tools/import',
                method: 'POST',
                data:   parsed,
            } );

            const applied = Number( res?.applied ) || 0;
            const parts   = [];

            if ( res?.records ) {
                const sum = ( bucket ) => Object.values( bucket || {} ).reduce( ( a, b ) => a + Number( b || 0 ), 0 );
                const created  = sum( res.records.created );
                const existing = sum( res.records.existing );
                const skipped  = sum( res.records.skipped );

                parts.push( sprintf(
                    /* translators: %d: number of records. */
                    _n( '%d record restored', '%d records restored', created, 'dono-fundraising-platform' ),
                    created
                ) );
                // Said plainly, because "already here" is the expected answer on
                // a second run and looks like failure if it goes unexplained.
                if ( existing ) {
                    parts.push( sprintf(
                        /* translators: %d: number of records. */
                        _n( '%d was already here', '%d were already here', existing, 'dono-fundraising-platform' ),
                        existing
                    ) );
                }
                if ( skipped ) {
                    parts.push( sprintf(
                        /* translators: %d: number of records. */
                        _n( '%d skipped', '%d skipped', skipped, 'dono-fundraising-platform' ),
                        skipped
                    ) );
                }
            }

            if ( applied > 0 ) {
                parts.push( sprintf(
                    /* translators: %d: number of settings groups. */
                    _n( '%d settings group restored', '%d settings groups restored', applied, 'dono-fundraising-platform' ),
                    applied
                ) );
            }

            setNotice( parts.length
                ? { type: 'success', text: parts.join( ', ' ) + '. ' + __( 'Reload the page to see it.', 'dono-fundraising-platform' ) }
                : { type: 'error', text: __( 'Nothing in that file matched a Dono setting or record.', 'dono-fundraising-platform' ) }
            );
        } catch ( err ) {
            setNotice( {
                type: 'error',
                text: err?.message || __( 'Import failed. Check that the file is a Dono settings export.', 'dono-fundraising-platform' ),
            } );
        } finally {
            setImporting( false );
            if ( fileRef.current ) fileRef.current.value = '';
        }
    };

    return (
        <div className="dono-panel">
            <Card
                title={ __( 'Import settings', 'dono-fundraising-platform' ) }
                sub={ __( 'Reads a Dono settings export and replaces the matching settings on this site. Donations, donors and campaigns are left alone.', 'dono-fundraising-platform' ) }
            >
                <div className="dono-advanced-actions">
                    <Btn
                        variant="secondary"
                        onClick={ () => fileRef.current?.click() }
                        disabled={ importing }
                        isBusy={ importing }
                    >
                        { importing ? __( 'Importing…', 'dono-fundraising-platform' ) : __( 'Choose a JSON file', 'dono-fundraising-platform' ) }
                    </Btn>
                    <input
                        ref={ fileRef }
                        type="file"
                        accept="application/json,.json"
                        style={ { display: 'none' } }
                        onChange={ ( e ) => askImport( e.target.files?.[ 0 ] ) }
                    />
                </div>
                <p className="dono-tools-note">
                    { __( 'A masked secret in the file leaves the stored key untouched, so importing an export cannot wipe a gateway key it was unable to carry.', 'dono-fundraising-platform' ) }
                </p>
            </Card>

            <ConfirmDialog confirm={ confirm } onClose={ () => {
                setConfirm( null );
                // Clear the input so choosing the same file again re-prompts.
                if ( fileRef.current ) fileRef.current.value = '';
            } } />
            <CsvImportCard setNotice={ setNotice } />
        </div>
    );
}
