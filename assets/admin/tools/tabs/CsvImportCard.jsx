import { useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';

import Card from '../../_shared/components/Card';
import Btn from '../../_shared/components/Btn';

/**
 * Donors, and their donations when the file has any, from anyone else's CSV.
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
    duplicate_in_file: __( 'the same row appears earlier in this file', 'dono' ),
    already_imported:  __( 'already imported by an earlier run', 'dono' ),
    donor_erased:      __( 'the donor was erased on this site', 'dono' ),
    error:             __( 'the row could not be read', 'dono' ),
};

// Two groups, because the second one decides what the import does at all.
const DONOR_FIELDS = [
    'email', 'first_name', 'last_name', 'full_name', 'company', 'phone',
    'address_line1', 'address_line2', 'city', 'region', 'postal', 'country',
];
const DONATION_FIELDS = [ 'amount', 'currency', 'date', 'status', 'reference' ];

export default function CsvImportCard( { setNotice } ) {
    const [ csv, setCsv ]             = useState( '' );
    const [ inspected, setInspected ] = useState( null );
    const [ mapping, setMapping ]     = useState( {} );
    const [ preview, setPreview ]     = useState( null );
    const [ busy, setBusy ]           = useState( '' );
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

            const landed = res.mode === 'donors'
                ? res.donors_created + res.donors_matched
                : res.donations_imported;

            setNotice( {
                type: landed > 0 ? 'success' : 'error',
                text: landed > 0 ? summarise( res ) : __( 'Nothing was imported. The preview above says why.', 'dono' ),
            } );
            if ( landed > 0 ) reset();
        } catch ( err ) {
            setNotice( { type: 'error', text: err?.message || __( 'The import failed.', 'dono' ) } );
        } finally {
            setBusy( '' );
        }
    };

    const setField = ( field ) => ( e ) => {
        const next = { ...mapping };
        if ( e.target.value ) {
            next[ field ] = e.target.value;
        } else {
            delete next[ field ];
        }
        setMapping( next );
    };

    const fields     = inspected?.fields || {};
    const headers    = inspected?.headers || [];
    const ready      = !! mapping.email;
    const withAmount = !! mapping.amount;

    const rowFor = ( field ) => {
        const chosen = mapping[ field ] || '';
        const sample = chosen ? ( inspected.sample?.[ 0 ]?.[ chosen ] ?? '' ) : '';
        return (
            <tr key={ field }>
                <th scope="row">
                    { fields[ field ] || field }
                    { field === 'email' && <span className="dono-csv-map__req"> *</span> }
                </th>
                <td>
                    <select className="dono-input" value={ chosen } onChange={ setField( field ) }>
                        <option value="">{ __( 'Not imported', 'dono' ) }</option>
                        { headers.map( ( h ) => (
                            <option key={ h } value={ h }>{ h }</option>
                        ) ) }
                    </select>
                </td>
                <td className="dono-csv-map__sample">{ sample || '-' }</td>
            </tr>
        );
    };

    return (
        <Card
            title={ __( 'Import from a CSV', 'dono' ) }
            sub={ __( 'A file from another platform or a spreadsheet. Donors are matched on their email address, so a donor who is already here gains the donations rather than a second record. A file with no amounts imports the people on their own.', 'dono' ) }
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

                    <h4 className="dono-csv-map__heading">{ __( 'The donor', 'dono' ) }</h4>
                    <table className="dono-csv-map">
                        <thead>
                            <tr>
                                <th scope="col">{ __( 'Dono field', 'dono' ) }</th>
                                <th scope="col">{ __( 'Column in your file', 'dono' ) }</th>
                                <th scope="col">{ __( 'First value', 'dono' ) }</th>
                            </tr>
                        </thead>
                        <tbody>{ DONOR_FIELDS.map( rowFor ) }</tbody>
                    </table>

                    <h4 className="dono-csv-map__heading">{ __( 'The donation', 'dono' ) }</h4>
                    <p className="dono-tools-note">
                        { withAmount
                            ? __( 'Each row will be imported as a donation.', 'dono' )
                            : __( 'No amount column is mapped, so this file will import donors only. Map Amount to bring their donations in as well.', 'dono' ) }
                    </p>
                    <table className="dono-csv-map">
                        <tbody>{ DONATION_FIELDS.map( rowFor ) }</tbody>
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
                        { preview && hasWork( preview ) && (
                            <Btn
                                variant="primary"
                                onClick={ () => run( false ) }
                                disabled={ busy !== '' }
                                isBusy={ busy === 'import' }
                            >
                                { __( 'Import', 'dono' ) }
                            </Btn>
                        ) }
                    </div>

                    { ! ready && (
                        <p className="dono-tools-note">
                            { __( 'Email has to be mapped. Everything else is optional.', 'dono' ) }
                        </p>
                    ) }

                    { preview && (
                        <div className="dono-csv-preview">
                            <p><strong>{ summarise( preview ) }</strong></p>
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
                            { preview.dry_run && (
                                <p className="dono-tools-note">
                                    { preview.mode === 'donors'
                                        ? __( 'Nothing has been written yet.', 'dono' )
                                        : __( 'Nothing has been written yet. Imported donations are marked as coming from a CSV and can be told apart from donations this site took.', 'dono' ) }
                                </p>
                            ) }
                        </div>
                    ) }
                </>
            ) }
        </Card>
    );
}

function hasWork( res ) {
    return res.donations_imported > 0 || res.donors_created > 0 || res.donors_matched > 0;
}

/** One sentence covering both modes, in the tense the caller needs. */
function summarise( res ) {
    const people = res.dry_run
        ? sprintf(
            /* translators: 1: donors to create, 2: donors already here. */
            __( '%1$d donors would be created and %2$d matched to donors already here.', 'dono' ),
            res.donors_created,
            res.donors_matched
        )
        : sprintf(
            /* translators: 1: donors created, 2: donors already here. */
            __( 'Created %1$d donors and matched %2$d to donors already here.', 'dono' ),
            res.donors_created,
            res.donors_matched
        );

    if ( res.mode === 'donors' ) {
        return people;
    }

    const gifts = res.dry_run
        ? sprintf(
            /* translators: %d: number of donations. */
            _n( '%d donation would be imported.', '%d donations would be imported.', res.donations_imported, 'dono' ),
            res.donations_imported
        )
        : sprintf(
            /* translators: %d: number of donations. */
            _n( 'Imported %d donation.', 'Imported %d donations.', res.donations_imported, 'dono' ),
            res.donations_imported
        );

    return `${ gifts } ${ people }`;
}
