import { useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';

import Card from '../../_shared/components/Card';
import Btn from '../../_shared/components/Btn';

/**
 * Donors and donations from anyone else's CSV.
 *
 * Three steps, and the middle one is the point: the file is read, the admin
 * says which column means what, and a dry run reports exactly what a real run
 * would do before anything is written.
 */

// Why a row did not make it, in the admin's words rather than a code.
const SKIP_LABELS = {
    no_email:          __( 'no email address', 'dono' ),
    invalid_email:     __( 'the email address is not one', 'dono' ),
    invalid_amount:    __( 'the amount is missing, zero or unreadable', 'dono' ),
    duplicate_in_file: __( 'the same donation appears earlier in this file', 'dono' ),
    already_imported:  __( 'already imported by an earlier run', 'dono' ),
    donor_erased:      __( 'the donor was erased on this site', 'dono' ),
    error:             __( 'the row could not be read', 'dono' ),
};

export default function CsvImportCard( { setNotice } ) {
    const [ csv, setCsv ]         = useState( '' );
    const [ inspected, setInspected ] = useState( null );
    const [ mapping, setMapping ] = useState( {} );
    const [ preview, setPreview ] = useState( null );
    const [ busy, setBusy ]       = useState( '' );
    const fileRef = useRef( null );

    const reset = () => {
        setCsv( '' );
        setInspected( null );
        setMapping( {} );
        setPreview( null );
        if ( fileRef.current ) fileRef.current.value = '';
    };

    const choose = async ( file ) => {
        if ( ! file ) return;
        setBusy( 'inspect' );
        setNotice( null );
        setPreview( null );
        try {
            const text = await file.text();
            const res  = await apiFetch( {
                path: '/dono/v1/admin/tools/csv-inspect',
                method: 'POST',
                data: { csv: text },
            } );
            setCsv( text );
            setInspected( res );
            // The guess is a starting point, not an answer; every row of it is
            // a control the admin can change.
            setMapping( res.mapping || {} );
        } catch ( err ) {
            setNotice( { type: 'error', text: err?.message || __( 'That file could not be read as a CSV.', 'dono' ) } );
        } finally {
            setBusy( '' );
        }
    };

    const run = async ( dryRun ) => {
        setBusy( dryRun ? 'preview' : 'import' );
        setNotice( null );
        try {
            const res = await apiFetch( {
                path: '/dono/v1/admin/tools/csv-import',
                method: 'POST',
                data: { csv, mapping, dry_run: dryRun },
            } );

            if ( dryRun ) {
                setPreview( res );
                return;
            }

            setNotice( {
                type: res.imported > 0 ? 'success' : 'error',
                text: res.imported > 0
                    ? sprintf(
                        /* translators: 1: donations imported, 2: donors created. */
                        __( 'Imported %1$d donations and created %2$d donors.', 'dono' ),
                        res.imported,
                        res.donors_created
                    )
                    : __( 'Nothing was imported. The preview above says why.', 'dono' ),
            } );
            if ( res.imported > 0 ) reset();
        } catch ( err ) {
            setNotice( { type: 'error', text: err?.message || __( 'The import failed.', 'dono' ) } );
        } finally {
            setBusy( '' );
        }
    };

    const setField = ( field ) => ( e ) => setMapping( { ...mapping, [ field ]: e.target.value } );

    const fields  = inspected?.fields || {};
    const headers = inspected?.headers || [];
    const ready   = !! mapping.email && !! mapping.amount;

    return (
        <Card
            title={ __( 'Import donations from a CSV', 'dono' ) }
            sub={ __( 'A file from another platform or a spreadsheet. Donors are matched on their email address, so a donor who is already here gains the donations rather than a second record.', 'dono' ) }
        >
            <div className="dono-advanced-actions">
                <Btn
                    variant="secondary"
                    onClick={ () => fileRef.current?.click() }
                    disabled={ busy !== '' }
                    isBusy={ busy === 'inspect' }
                >
                    { inspected ? __( 'Choose a different file', 'dono' ) : __( 'Choose a CSV file', 'dono' ) }
                </Btn>
                { inspected && (
                    <Btn variant="tertiary" onClick={ reset } disabled={ busy !== '' }>
                        { __( 'Cancel', 'dono' ) }
                    </Btn>
                ) }
                <input
                    ref={ fileRef }
                    type="file"
                    accept="text/csv,.csv"
                    style={ { display: 'none' } }
                    onChange={ ( e ) => choose( e.target.files?.[ 0 ] ) }
                />
            </div>

            { inspected && (
                <>
                    <p className="dono-tools-note">
                        { sprintf(
                            /* translators: 1: number of rows, 2: number of columns. */
                            _n( '%1$d row, %2$d columns.', '%1$d rows, %2$d columns.', inspected.rows, 'dono' ),
                            inspected.rows,
                            headers.length
                        ) }
                    </p>

                    <table className="dono-csv-map">
                        <thead>
                            <tr>
                                <th scope="col">{ __( 'Dono field', 'dono' ) }</th>
                                <th scope="col">{ __( 'Column in your file', 'dono' ) }</th>
                                <th scope="col">{ __( 'First value', 'dono' ) }</th>
                            </tr>
                        </thead>
                        <tbody>
                            { Object.entries( fields ).map( ( [ field, label ] ) => {
                                const chosen = mapping[ field ] || '';
                                const sample = chosen ? ( inspected.sample?.[ 0 ]?.[ chosen ] ?? '' ) : '';
                                const required = field === 'email' || field === 'amount';
                                return (
                                    <tr key={ field }>
                                        <th scope="row">
                                            { label }
                                            { required && <span className="dono-csv-map__req"> *</span> }
                                        </th>
                                        <td>
                                            <select
                                                className="dono-input"
                                                value={ chosen }
                                                onChange={ setField( field ) }
                                            >
                                                <option value="">{ __( 'Not imported', 'dono' ) }</option>
                                                { headers.map( ( h ) => (
                                                    <option key={ h } value={ h }>{ h }</option>
                                                ) ) }
                                            </select>
                                        </td>
                                        <td className="dono-csv-map__sample">{ sample || '-' }</td>
                                    </tr>
                                );
                            } ) }
                        </tbody>
                    </table>

                    <div className="dono-advanced-actions">
                        <Btn
                            variant="secondary"
                            onClick={ () => run( true ) }
                            disabled={ ! ready || busy !== '' }
                            isBusy={ busy === 'preview' }
                        >
                            { __( 'Preview', 'dono' ) }
                        </Btn>
                        { preview && preview.imported > 0 && (
                            <Btn
                                variant="primary"
                                onClick={ () => run( false ) }
                                disabled={ busy !== '' }
                                isBusy={ busy === 'import' }
                            >
                                { sprintf(
                                    /* translators: %d: number of donations. */
                                    _n( 'Import %d donation', 'Import %d donations', preview.imported, 'dono' ),
                                    preview.imported
                                ) }
                            </Btn>
                        ) }
                    </div>

                    { ! ready && (
                        <p className="dono-tools-note">
                            { __( 'Email and Amount have to be mapped. Everything else is optional.', 'dono' ) }
                        </p>
                    ) }

                    { preview && (
                        <div className="dono-csv-preview">
                            <p>
                                <strong>
                                    { sprintf(
                                        /* translators: 1: donations, 2: donors. */
                                        __( '%1$d donations would be imported, creating %2$d donors.', 'dono' ),
                                        preview.imported,
                                        preview.donors_created
                                    ) }
                                </strong>
                            </p>
                            { Object.keys( preview.skipped || {} ).length > 0 && (
                                <ul className="dono-csv-preview__skips">
                                    { Object.entries( preview.skipped ).map( ( [ reason, n ] ) => (
                                        <li key={ reason }>
                                            { sprintf(
                                                /* translators: 1: number of rows, 2: the reason. */
                                                _n( '%1$d row skipped: %2$s', '%1$d rows skipped: %2$s', n, 'dono' ),
                                                n,
                                                SKIP_LABELS[ reason ] || reason
                                            ) }
                                        </li>
                                    ) ) }
                                </ul>
                            ) }
                            { ( preview.errors || [] ).length > 0 && (
                                <ul className="dono-csv-preview__errors">
                                    { preview.errors.map( ( e, i ) => <li key={ i }>{ e }</li> ) }
                                </ul>
                            ) }
                            <p className="dono-tools-note">
                                { __( 'Nothing has been written yet. Imported donations are marked as coming from a CSV and can be told apart from donations this site took.', 'dono' ) }
                            </p>
                        </div>
                    ) }
                </>
            ) }
        </Card>
    );
}
