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

    // Named back to the admin before anything is written: this rewrites live
    // gateway, email, receipt and role configuration and there is no undo.
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
        // A full export carries both halves, and the route writes the settings
        // half whichever file it came in on, so the records wording alone would
        // hide a rewrite of live gateway and email configuration.
        const hasSettings = !! parsed?.settings && Object.keys( parsed.settings ).length > 0;

        setConfirm( {
            title: isFullExport
                ? __( 'Restore records from this file', 'dono-fundraising-platform' )
                : __( 'Replace settings from this file', 'dono-fundraising-platform' ),
            message: isFullExport
                ? sprintf(
                    /* translators: %s: the chosen file name. */
                    __( '%s will add its campaigns, funds, forms, donors, donations, recurring plans and receipts to this site. Anything already here is left as it is, so running it twice is safe. Donors erased on this site stay erased.', 'dono-fundraising-platform' ),
                    file.name
                ) + ( hasSettings
                    ? ' ' + __( 'It carries settings too, and those are written over yours: gateway, email, receipt, numbering and roles.', 'dono-fundraising-platform' )
                    : '' )
                : sprintf(
                    /* translators: %s: the chosen file name. */
                    __( '%s will write its gateway, email, receipt, numbering and role settings over yours. A setting the file does not carry keeps the value it has here, except the role mapping, which is replaced whole: a role the file does not name loses its Dono capabilities. Donations, donors and campaigns are untouched. This cannot be undone.', 'dono-fundraising-platform' ),
                    file.name
                ),
            confirmLabel: isFullExport ? __( 'Restore', 'dono-fundraising-platform' ) : __( 'Replace settings', 'dono-fundraising-platform' ),
            destructive:  ! isFullExport,
            onConfirm:    () => doImport( parsed ),
        } );
    };

    // What landed, read off a payload. The refusal path is not all-or-nothing:
    // the groups accepted before the refused one keep their new values, so the
    // same reckoning has to run on a failure or the admin retries blind.
    const landedParts = ( payload ) => {
        const parts = [];

        if ( payload?.records ) {
            const sum = ( bucket ) => Object.values( bucket || {} ).reduce( ( a, b ) => a + Number( b || 0 ), 0 );
            const created  = sum( payload.records.created );
            const existing = sum( payload.records.existing );
            const skipped  = sum( payload.records.skipped );

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

        const applied = Number( payload?.applied ) || 0;
        if ( applied > 0 ) {
            parts.push( sprintf(
                /* translators: %d: number of settings groups. */
                _n( '%d settings group restored', '%d settings groups restored', applied, 'dono-fundraising-platform' ),
                applied
            ) );
        }

        return parts;
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

            const parts = landedParts( res );

            setNotice( parts.length
                ? { type: 'success', text: parts.join( ', ' ) + '. ' + __( 'Reload the page to see it.', 'dono-fundraising-platform' ) }
                : { type: 'error', text: __( 'Nothing in that file matched a Dono setting or record.', 'dono-fundraising-platform' ) }
            );
        } catch ( err ) {
            const reason = err?.message || __( 'Import failed. Check that the file is a Dono settings export.', 'dono-fundraising-platform' );
            const landed = landedParts( err?.data );

            setNotice( {
                type: 'error',
                text: landed.length
                    ? reason + ' ' + sprintf(
                        /* translators: %s: a comma-separated list of what the refused import did restore. */
                        __( 'What did land: %s. Running the file again is safe.', 'dono-fundraising-platform' ),
                        landed.join( ', ' )
                    )
                    : reason,
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
                sub={ __( 'Reads a Dono settings export and replaces the settings it carries. Anything it does not carry keeps the value it has here. Donations, donors and campaigns are left alone.', 'dono-fundraising-platform' ) }
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
