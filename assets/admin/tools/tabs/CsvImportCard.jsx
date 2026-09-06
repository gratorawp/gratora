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
    no_email:          __( 'no email address', 'fundraising-toolkit' ),
    invalid_email:     __( 'the email address is not one', 'fundraising-toolkit' ),
    invalid_amount:    __( 'the amount is missing, zero or unreadable', 'fundraising-toolkit' ),
    invalid_date:      __( 'the date is missing or unreadable', 'fundraising-toolkit' ),
    unknown_status:    __( 'the status is not one this site knows', 'fundraising-toolkit' ),
    duplicate_in_file: __( 'the same row appears earlier in this file', 'fundraising-toolkit' ),
    already_imported:  __( 'already imported by an earlier run', 'fundraising-toolkit' ),
    donor_erased:      __( 'the donor was erased on this site', 'fundraising-toolkit' ),
    error:             __( 'the row could not be read', 'fundraising-toolkit' ),
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
                path: '/fundkit/v1/admin/tools/csv-inspect',
                method: 'POST',
                data: { csv: text },
            } );
            setCsv( text );
            setInspected( res );
            // The guess is a starting point, not an answer; every row of it is
            // a control the admin can change.
            setMapping( res.mapping || {} );
        } catch ( err ) {
            setNotice( { type: 'error', text: err?.message || __( 'That file could not be read as a CSV.', 'fundraising-toolkit' ) } );
        } finally {
            setBusy( '' );
        }
    };

    const run = async ( dryRun ) => {
        setBusy( dryRun ? 'preview' : 'import' );
        setNotice( null );
        try {
            const res = await apiFetch( {
                path: '/fundkit/v1/admin/tools/csv-import',
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
                text: landed > 0 ? summarise( res ) : __( 'Nothing was imported. The preview above says why.', 'fundraising-toolkit' ),
            } );
            if ( landed > 0 ) reset();
        } catch ( err ) {
            setNotice( { type: 'error', text: err?.message || __( 'The import failed.', 'fundraising-toolkit' ) } );
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
        // The preview describes the mapping it was run against, and Import is
        // only offered for a mapping that has been dry-run.
        setPreview( null );
    };

    const fields     = inspected?.fields || {};
    const headers    = inspected?.headers || [];
    const withAmount = !! mapping.amount;
    // A donation has to say when the money arrived: the importer refuses every
    // row without one, so a file mapped this far would import nothing at all.
    const needsDate  = withAmount && ! mapping.date;
    const ready      = !! mapping.email && ! needsDate;

    const rowFor = ( field ) => {
        const chosen = mapping[ field ] || '';
        const sample = chosen ? ( inspected.sample?.[ 0 ]?.[ chosen ] ?? '' ) : '';
        return (
            <tr key={ field }>
                <th scope="row">
                    { fields[ field ] || field }
                    { ( field === 'email' || ( field === 'date' && withAmount ) ) && (
                        <span className="fundkit-csv-map__req"> *</span>
                    ) }
                </th>
                <td>
                    <select className="fundkit-input" value={ chosen } onChange={ setField( field ) }>
                        <option value="">{ __( 'Not imported', 'fundraising-toolkit' ) }</option>
                        { headers.map( ( h ) => (
                            <option key={ h } value={ h }>{ h }</option>
                        ) ) }
                    </select>
                </td>
                <td className="fundkit-csv-map__sample">{ sample || '-' }</td>
            </tr>
        );
    };

    return (
        <Card
            title={ __( 'Import from a CSV', 'fundraising-toolkit' ) }
            sub={ __( 'A file from another platform or a spreadsheet. Donors are matched on their email address, so a donor who is already here gains the donations rather than a second record. A file with no amounts imports the people on their own.', 'fundraising-toolkit' ) }
        >
            <div className="fundkit-advanced-actions">
                <Btn
                    variant="secondary"
                    onClick={ () => fileRef.current?.click() }
                    disabled={ busy !== '' }
                    isBusy={ busy === 'inspect' }
                >
                    { inspected ? __( 'Choose a different file', 'fundraising-toolkit' ) : __( 'Choose a CSV file', 'fundraising-toolkit' ) }
                </Btn>
                { inspected && (
                    <Btn variant="tertiary" onClick={ reset } disabled={ busy !== '' }>
                        { __( 'Cancel', 'fundraising-toolkit' ) }
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
                    <p className="fundkit-tools-note">
                        { sprintf(
                            /* translators: 1: number of rows, 2: number of columns. */
                            _n( '%1$d row, %2$d columns.', '%1$d rows, %2$d columns.', inspected.rows, 'fundraising-toolkit' ),
                            inspected.rows,
                            headers.length
                        ) }
                    </p>

                    <h4 className="fundkit-csv-map__heading">{ __( 'The donor', 'fundraising-toolkit' ) }</h4>
                    <table className="fundkit-csv-map">
                        <thead>
                            <tr>
                                <th scope="col">{ __( 'Fundraising Toolkit field', 'fundraising-toolkit' ) }</th>
                                <th scope="col">{ __( 'Column in your file', 'fundraising-toolkit' ) }</th>
                                <th scope="col">{ __( 'First value', 'fundraising-toolkit' ) }</th>
                            </tr>
                        </thead>
                        <tbody>{ DONOR_FIELDS.map( rowFor ) }</tbody>
                    </table>

                    <h4 className="fundkit-csv-map__heading">{ __( 'The donation', 'fundraising-toolkit' ) }</h4>
                    <p className="fundkit-tools-note">
                        { ! withAmount && __( 'No amount column is mapped, so this file will import donors only. Map Amount to bring their donations in as well.', 'fundraising-toolkit' ) }
                        { withAmount && ! needsDate && __( 'Each row will be imported as a donation.', 'fundraising-toolkit' ) }
                        { needsDate && __( 'Map the Date column as well. A donation has to say when the money arrived, and every row without a date is skipped.', 'fundraising-toolkit' ) }
                    </p>
                    <table className="fundkit-csv-map">
                        <tbody>{ DONATION_FIELDS.map( rowFor ) }</tbody>
                    </table>

                    <div className="fundkit-advanced-actions">
                        <Btn
                            variant="secondary"
                            onClick={ () => run( true ) }
                            disabled={ ! ready || busy !== '' }
                            isBusy={ busy === 'preview' }
                        >
                            { __( 'Preview', 'fundraising-toolkit' ) }
                        </Btn>
                        { preview && hasWork( preview ) && (
                            <Btn
                                variant="primary"
                                onClick={ () => run( false ) }
                                disabled={ busy !== '' }
                                isBusy={ busy === 'import' }
                            >
                                { __( 'Import', 'fundraising-toolkit' ) }
                            </Btn>
                        ) }
                    </div>

                    { ! ready && (
                        <p className="fundkit-tools-note">
                            { ! mapping.email
                                ? __( 'Email has to be mapped before this file can be previewed.', 'fundraising-toolkit' )
                                : __( 'Date has to be mapped as well, or every row is skipped for want of one.', 'fundraising-toolkit' ) }
                        </p>
                    ) }

                    { preview && (
                        <div className="fundkit-csv-preview">
                            <p><strong>{ summarise( preview ) }</strong></p>
                            { Object.keys( preview.skipped || {} ).length > 0 && (
                                <ul className="fundkit-csv-preview__skips">
                                    { Object.entries( preview.skipped ).map( ( [ reason, n ] ) => (
                                        <li key={ reason }>
                                            { sprintf(
                                                /* translators: 1: number of rows, 2: the reason. */
                                                _n( '%1$d row skipped: %2$s', '%1$d rows skipped: %2$s', n, 'fundraising-toolkit' ),
                                                n,
                                                SKIP_LABELS[ reason ] || reason
                                            ) }
                                        </li>
                                    ) ) }
                                </ul>
                            ) }
                            { ( preview.errors || [] ).length > 0 && (
                                <ul className="fundkit-csv-preview__errors">
                                    { preview.errors.map( ( e, i ) => <li key={ i }>{ e }</li> ) }
                                </ul>
                            ) }
                            { preview.dry_run && (
                                <p className="fundkit-tools-note">
                                    { preview.mode === 'donors'
                                        ? __( 'Nothing has been written yet.', 'fundraising-toolkit' )
                                        : __( 'Nothing has been written yet. Imported donations are marked as coming from a CSV and can be told apart from donations this site took.', 'fundraising-toolkit' ) }
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
            __( '%1$d donors would be created and %2$d matched to donors already here.', 'fundraising-toolkit' ),
            res.donors_created,
            res.donors_matched
        )
        : sprintf(
            /* translators: 1: donors created, 2: donors already here. */
            __( 'Created %1$d donors and matched %2$d to donors already here.', 'fundraising-toolkit' ),
            res.donors_created,
            res.donors_matched
        );

    if ( res.mode === 'donors' ) {
        return people;
    }

    const donations = res.dry_run
        ? sprintf(
            /* translators: %d: number of donations. */
            _n( '%d donation would be imported.', '%d donations would be imported.', res.donations_imported, 'fundraising-toolkit' ),
            res.donations_imported
        )
        : sprintf(
            /* translators: %d: number of donations. */
            _n( 'Imported %d donation.', 'Imported %d donations.', res.donations_imported, 'fundraising-toolkit' ),
            res.donations_imported
        );

    return `${ donations } ${ people }`;
}
