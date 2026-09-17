import { useEffect, useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

import Btn from '../../_shared/components/Btn';
import DateField from '../../_shared/components/DateField';
import MonthField from '../../_shared/components/MonthField';
import { userCan } from '../../_shared/caps';
import { saveBlob } from '@gratora/ui/utils/download';

/** Fetch rather than a bare link: the REST route needs the nonce header. */
async function download( path, setNotice, setBusy, fallbackName ) {
    setBusy( true );
    setNotice( null );
    try {
        const res  = await apiFetch( { path, parse: false } );
        const blob = await res.blob();

        if ( blob.size === 0 ) {
            setNotice( { type: 'error', text: __( 'That export came back empty.', 'gratora-donation-platform' ) } );
            return;
        }

        // A truncated export downloads exactly like a complete one, and it is
        // what a bookkeeper reconciles against, so say it on the way out.
        const cap = res.headers.get( 'x-gratora-export-truncated' );
        if ( cap ) {
            setNotice( {
                type: 'warning',
                text: sprintf(
                    /* translators: %s: maximum number of rows an export can hold. */
                    __( 'This export holds the most recent %s rows and stops there. Narrow the date range to get the rest.', 'gratora-donation-platform' ),
                    Number( cap ).toLocaleString()
                ),
            } );
        }

        const match = ( res.headers.get( 'content-disposition' ) || '' ).match( /filename="([^"]+)"/ );
        saveBlob( blob, match ? match[ 1 ] : fallbackName );
    } catch ( err ) {
        setNotice( { type: 'error', text: err?.message || __( 'That export could not be generated.', 'gratora-donation-platform' ) } );
    } finally {
        setBusy( false );
    }
}

function Row( { title, description, children } ) {
    return (
        <tr>
            <th scope="row">
                <strong>{ title }</strong>
                <p>{ description }</p>
            </th>
            <td>{ children }</td>
        </tr>
    );
}

export default function ExportTab( { setNotice } ) {
    const [ opts, setOpts ]   = useState( null );
    const [ busy, setBusy ]   = useState( '' );

    const [ donationsFrom, setDonationsFrom ] = useState( '' );
    const [ donationsTo, setDonationsTo ]     = useState( '' );
    const [ includeTest, setIncludeTest ]     = useState( false );

    const [ pdfYear, setPdfYear ] = useState( 0 );

    const [ statsFrom, setStatsFrom ] = useState( '' );
    const [ statsTo, setStatsTo ]     = useState( '' );

    const [ donorsFrom, setDonorsFrom ]   = useState( '' );
    const [ donorsTo, setDonorsTo ]       = useState( '' );
    const [ donorsCampaign, setDonorsCampaign ] = useState( 0 );
    const [ columns, setColumns ]         = useState( [] );

    useEffect( () => {
        apiFetch( { path: '/gratora/v1/admin/exports/options' } )
            .then( ( o ) => {
                setOpts( o );
                setPdfYear( o.current_year );
                // The first month with donations, not January: a range opening
                // on months that never had one starts the file with zero rows
                // that read as a fault.
                setStatsFrom( o.first_month || `${ o.current_year }-01` );
                setStatsTo( o.current_month );
                // Every column on by default: a file silently missing a column
                // is worse than one carrying a column nobody wanted.
                setColumns( ( o.donor_columns || [] ).map( ( c ) => c.key ) );
            } )
            .catch( () => {
                // Without this the tab renders as a normal working screen on a
                // failed load: an empty Columns grid, a campaign select holding
                // only "All campaigns", and Generate CSV quietly exporting the
                // fallback columns instead of the ones the operator chose.
                setOpts( { donor_columns: [], campaigns: [], years: [ new Date().getFullYear() ] } );
                setNotice( {
                    type: 'error',
                    text: __( 'The export options could not be loaded, so the choices below are incomplete. Reload the page to try again.', 'gratora-donation-platform' ),
                } );
            } );
    }, [] );

    const years = opts?.years || [];

    const toggleColumn = ( key ) => setColumns( ( c ) => (
        c.includes( key ) ? c.filter( ( k ) => k !== key ) : [ ...c, key ]
    ) );

    const donationsPath = useMemo( () => {
        const q = new URLSearchParams();
        if ( donationsFrom ) q.set( 'created_from', donationsFrom );
        if ( donationsTo )   q.set( 'created_to', donationsTo );
        if ( includeTest )   q.set( 'include_test', '1' );
        const s = q.toString();
        return '/gratora/v1/admin/donations/export.csv' + ( s ? `?${ s }` : '' );
    }, [ donationsFrom, donationsTo, includeTest ] );

    const donorsPath = useMemo( () => {
        const q = new URLSearchParams();
        if ( donorsFrom ) q.set( 'from', donorsFrom );
        if ( donorsTo )   q.set( 'to', donorsTo );
        if ( donorsCampaign ) q.set( 'campaign_id', String( donorsCampaign ) );
        if ( columns.length ) q.set( 'columns', columns.join( ',' ) );
        return `/gratora/v1/admin/exports/donors.csv?${ q.toString() }`;
    }, [ donorsFrom, donorsTo, donorsCampaign, columns ] );

    const statsPath = `/gratora/v1/admin/exports/revenue.csv?from=${ statsFrom }&to=${ statsTo }`;

    // From the server-rendered capability snapshot, not from the options
    // payload: that payload is absent for exactly the reader the options route
    // refused, and its absence was read as permission.
    const canDonors     = userCan( 'export_donors' );
    const canReports    = userCan( 'view_reports' );
    const canDonations  = userCan( 'view_donations' );
    const canEverything = userCan( 'manage_options' );

    // Unchecking every box exported the fallback set, which is names and email
    // addresses: the opposite of what the reader asked for.
    const noColumns = columns.length === 0;

    const exportSettings = async () => {
        setBusy( 'settings' );
        setNotice( null );
        try {
            const data = await apiFetch( { path: '/gratora/v1/admin/tools/export' } );
            const blob = new Blob( [ JSON.stringify( data, null, 2 ) ], { type: 'application/json' } );
            saveBlob( blob, `gratora-settings-${ new Date().toISOString().slice( 0, 10 ) }.json` );
        } catch ( err ) {
            setNotice( { type: 'error', text: err?.message || __( 'Export failed.', 'gratora-donation-platform' ) } );
        } finally {
            setBusy( '' );
        }
    };

    return (
        <div className="gratora-panel">
            <table className="gratora-exports">
                <thead>
                    <tr>
                        <th scope="col">{ __( 'Export type', 'gratora-donation-platform' ) }</th>
                        <th scope="col">{ __( 'Options', 'gratora-donation-platform' ) }</th>
                    </tr>
                </thead>
                <tbody>
                    { canDonations && (
                    <Row
                        title={ __( 'Donations', 'gratora-donation-platform' ) }
                        description={ __( 'Every donation as a CSV: reference, donor, amount, status, campaign and gateway.', 'gratora-donation-platform' ) }
                    >
                        <div className="gratora-exports__controls">
                            <span className="gratora-tools-field">
                                { __( 'From', 'gratora-donation-platform' ) }
                                <DateField
                                    value={ donationsFrom }
                                    onChange={ ( v ) => setDonationsFrom( v || '' ) }
                                    ariaLabel={ __( 'Export donations from', 'gratora-donation-platform' ) }
                                    placeholder={ __( 'Any', 'gratora-donation-platform' ) }
                                />
                            </span>
                            <span className="gratora-tools-field">
                                { __( 'To', 'gratora-donation-platform' ) }
                                <DateField
                                    value={ donationsTo }
                                    onChange={ ( v ) => setDonationsTo( v || '' ) }
                                    ariaLabel={ __( 'Export donations to', 'gratora-donation-platform' ) }
                                    placeholder={ __( 'Any', 'gratora-donation-platform' ) }
                                />
                            </span>
                            <Btn
                                variant="secondary"
                                disabled={ busy === 'donations' }
                                isBusy={ busy === 'donations' }
                                onClick={ () => download( donationsPath, setNotice, ( b ) => setBusy( b ? 'donations' : '' ), 'donations.csv' ) }
                            >
                                { __( 'Generate CSV', 'gratora-donation-platform' ) }
                            </Btn>
                        </div>
                        <label className="gratora-exports__check">
                            <input type="checkbox" checked={ includeTest } onChange={ ( e ) => setIncludeTest( e.target.checked ) } />
                            { __( 'Include test donations', 'gratora-donation-platform' ) }
                        </label>
                    </Row>
                    ) }

                    { canReports && (
                        <Row
                            title={ __( 'Revenue report (PDF)', 'gratora-donation-platform' ) }
                            description={ __( 'A one-page summary of a year: total raised, month by month, and the best month. No donor details, so it can go straight to a board.', 'gratora-donation-platform' ) }
                        >
                            <div className="gratora-exports__controls">
                                <label className="gratora-tools-field">
                                    { __( 'Year', 'gratora-donation-platform' ) }
                                    <select className="gratora-select" value={ pdfYear } onChange={ ( e ) => setPdfYear( Number( e.target.value ) ) }>
                                        { years.map( ( y ) => <option key={ y } value={ y }>{ y }</option> ) }
                                    </select>
                                </label>
                                <Btn
                                    variant="secondary"
                                    disabled={ busy === 'pdf' }
                                    isBusy={ busy === 'pdf' }
                                    onClick={ () => download( `/gratora/v1/admin/exports/revenue.pdf?year=${ pdfYear }`, setNotice, ( b ) => setBusy( b ? 'pdf' : '' ), 'revenue.pdf' ) }
                                >
                                    { __( 'Generate PDF', 'gratora-donation-platform' ) }
                                </Btn>
                            </div>
                        </Row>
                    ) }

                    { canReports && (
                        <Row
                            title={ __( 'Revenue by month', 'gratora-donation-platform' ) }
                            description={ __( 'Revenue, donation count and average donation for every month in the range. Quiet months are written as zero rows, so the file charts as a continuous series.', 'gratora-donation-platform' ) }
                        >
                            <div className="gratora-exports__controls">
                                <span className="gratora-tools-field">
                                    { __( 'From', 'gratora-donation-platform' ) }
                                    <MonthField
                                        value={ statsFrom }
                                        onChange={ setStatsFrom }
                                        min={ opts?.first_month }
                                        max={ opts?.current_month }
                                        ariaLabel={ __( 'Revenue from month', 'gratora-donation-platform' ) }
                                    />
                                </span>
                                <span className="gratora-tools-field">
                                    { __( 'To', 'gratora-donation-platform' ) }
                                    <MonthField
                                        value={ statsTo }
                                        onChange={ setStatsTo }
                                        min={ opts?.first_month }
                                        max={ opts?.current_month }
                                        ariaLabel={ __( 'Revenue to month', 'gratora-donation-platform' ) }
                                    />
                                </span>
                                <Btn
                                    variant="secondary"
                                    disabled={ busy === 'stats' }
                                    isBusy={ busy === 'stats' }
                                    onClick={ () => download( statsPath, setNotice, ( b ) => setBusy( b ? 'stats' : '' ), 'revenue.csv' ) }
                                >
                                    { __( 'Generate CSV', 'gratora-donation-platform' ) }
                                </Btn>
                            </div>
                        </Row>
                    ) }

                    { canDonors && (
                        <Row
                            title={ __( 'Donors', 'gratora-donation-platform' ) }
                            description={ __( 'The donor list as a CSV, by when each donor record was created. Take only the columns you need: names, emails, phone numbers and addresses are personal data, and this file is not encrypted once it leaves the site.', 'gratora-donation-platform' ) }
                        >
                            <div className="gratora-exports__controls">
                                <span className="gratora-tools-field">
                                    { __( 'From', 'gratora-donation-platform' ) }
                                    <DateField
                                        value={ donorsFrom }
                                        onChange={ ( v ) => setDonorsFrom( v || '' ) }
                                        ariaLabel={ __( 'Export donors from', 'gratora-donation-platform' ) }
                                        placeholder={ __( 'Any', 'gratora-donation-platform' ) }
                                    />
                                </span>
                                <span className="gratora-tools-field">
                                    { __( 'To', 'gratora-donation-platform' ) }
                                    <DateField
                                        value={ donorsTo }
                                        onChange={ ( v ) => setDonorsTo( v || '' ) }
                                        ariaLabel={ __( 'Export donors to', 'gratora-donation-platform' ) }
                                        placeholder={ __( 'Any', 'gratora-donation-platform' ) }
                                    />
                                </span>
                                <label className="gratora-tools-field">
                                    { __( 'Campaign', 'gratora-donation-platform' ) }
                                    <select className="gratora-select" value={ donorsCampaign } onChange={ ( e ) => setDonorsCampaign( Number( e.target.value ) ) }>
                                        <option value={ 0 }>{ __( 'All campaigns', 'gratora-donation-platform' ) }</option>
                                        { ( opts?.campaigns || [] ).map( ( c ) => (
                                            <option key={ c.id } value={ c.id }>{ c.title || `#${ c.id }` }</option>
                                        ) ) }
                                    </select>
                                </label>
                                <Btn
                                    variant="secondary"
                                    disabled={ busy === 'donors' || noColumns }
                                    isBusy={ busy === 'donors' }
                                    onClick={ () => download( donorsPath, setNotice, ( b ) => setBusy( b ? 'donors' : '' ), 'donors.csv' ) }
                                >
                                    { __( 'Generate CSV', 'gratora-donation-platform' ) }
                                </Btn>
                            </div>

                            <div className="gratora-exports__columns">
                                <p className="gratora-exports__columns-head">{ __( 'Columns', 'gratora-donation-platform' ) }</p>
                                <div className="gratora-exports__grid">
                                    { ( opts?.donor_columns || [] ).map( ( c ) => (
                                        <label key={ c.key }>
                                            <input
                                                type="checkbox"
                                                checked={ columns.includes( c.key ) }
                                                onChange={ () => toggleColumn( c.key ) }
                                            />
                                            { c.label }
                                        </label>
                                    ) ) }
                                </div>
                                { noColumns && (
                                    <p className="gratora-tools-note">
                                        { __( 'Pick at least one column. With none selected the file would still carry names and email addresses.', 'gratora-donation-platform' ) }
                                    </p>
                                ) }
                            </div>
                        </Row>
                    ) }

                    { canEverything && (
                    <Row
                        title={ __( 'Everything', 'gratora-donation-platform' ) }
                        description={ __( 'Campaigns, funds, forms, donors, donations, recurring plans and receipts as one JSON file, which the Import tab can restore onto another Gratora site.', 'gratora-donation-platform' ) }
                    >
                        <div className="gratora-exports__controls">
                            <Btn
                                variant="secondary"
                                disabled={ busy === 'everything' }
                                isBusy={ busy === 'everything' }
                                onClick={ () => download( '/gratora/v1/admin/tools/export-all', setNotice, ( b ) => setBusy( b ? 'everything' : '' ), 'gratora-export.json' ) }
                            >
                                { __( 'Export JSON', 'gratora-donation-platform' ) }
                            </Btn>
                        </div>
                        <p className="gratora-tools-note">
                            { __( 'Donor names, email addresses and postal addresses are readable in this file. They have to be, or it could only ever be restored onto the site it came from. Treat it like the donor database it is.', 'gratora-donation-platform' ) }
                        </p>
                    </Row>
                    ) }

                    { canEverything && (
                    <Row
                        title={ __( 'Settings', 'gratora-donation-platform' ) }
                        description={ __( 'Every Gratora setting as JSON, to lift a configured site onto another install. Donations, donors and campaigns are not included.', 'gratora-donation-platform' ) }
                    >
                        <div className="gratora-exports__controls">
                            <Btn
                                variant="secondary"
                                disabled={ busy === 'settings' }
                                isBusy={ busy === 'settings' }
                                onClick={ exportSettings }
                            >
                                { __( 'Export JSON', 'gratora-donation-platform' ) }
                            </Btn>
                        </div>
                        <p className="gratora-tools-note">
                            { __( 'Secrets are masked. A gateway key never leaves the site in an export, so an imported file cannot restore one.', 'gratora-donation-platform' ) }
                        </p>
                    </Row>
                    ) }
                </tbody>
            </table>
        </div>
    );
}
