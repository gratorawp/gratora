import { useEffect, useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

import Btn from '../../_shared/components/Btn';
import DateField from '../../_shared/components/DateField';
import MonthField from '../../_shared/components/MonthField';
import { userCan } from '../../_shared/caps';
import { saveBlob } from '../../_shared/download';

/** Fetch rather than a bare link: the REST route needs the nonce header. */
async function download( path, setNotice, setBusy, fallbackName ) {
    setBusy( true );
    setNotice( null );
    try {
        const res  = await apiFetch( { path, parse: false } );
        const blob = await res.blob();

        if ( blob.size === 0 ) {
            setNotice( { type: 'error', text: __( 'That export came back empty.', 'fundraising-toolkit' ) } );
            return;
        }

        // A truncated export downloads exactly like a complete one, and it is
        // what a bookkeeper reconciles against, so say it on the way out.
        const cap = res.headers.get( 'x-fundkit-export-truncated' );
        if ( cap ) {
            setNotice( {
                type: 'warning',
                text: sprintf(
                    /* translators: %s: maximum number of rows an export can hold. */
                    __( 'This export holds the most recent %s rows and stops there. Narrow the date range to get the rest.', 'fundraising-toolkit' ),
                    Number( cap ).toLocaleString()
                ),
            } );
        }

        const match = ( res.headers.get( 'content-disposition' ) || '' ).match( /filename="([^"]+)"/ );
        saveBlob( blob, match ? match[ 1 ] : fallbackName );
    } catch ( err ) {
        setNotice( { type: 'error', text: err?.message || __( 'That export could not be generated.', 'fundraising-toolkit' ) } );
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
        apiFetch( { path: '/fundkit/v1/admin/exports/options' } )
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
                    text: __( 'The export options could not be loaded, so the choices below are incomplete. Reload the page to try again.', 'fundraising-toolkit' ),
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
        return '/fundkit/v1/admin/donations/export.csv' + ( s ? `?${ s }` : '' );
    }, [ donationsFrom, donationsTo, includeTest ] );

    const donorsPath = useMemo( () => {
        const q = new URLSearchParams();
        if ( donorsFrom ) q.set( 'from', donorsFrom );
        if ( donorsTo )   q.set( 'to', donorsTo );
        if ( donorsCampaign ) q.set( 'campaign_id', String( donorsCampaign ) );
        if ( columns.length ) q.set( 'columns', columns.join( ',' ) );
        return `/fundkit/v1/admin/exports/donors.csv?${ q.toString() }`;
    }, [ donorsFrom, donorsTo, donorsCampaign, columns ] );

    const statsPath = `/fundkit/v1/admin/exports/revenue.csv?from=${ statsFrom }&to=${ statsTo }`;

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
            const data = await apiFetch( { path: '/fundkit/v1/admin/tools/export' } );
            const blob = new Blob( [ JSON.stringify( data, null, 2 ) ], { type: 'application/json' } );
            saveBlob( blob, `fundkit-settings-${ new Date().toISOString().slice( 0, 10 ) }.json` );
        } catch ( err ) {
            setNotice( { type: 'error', text: err?.message || __( 'Export failed.', 'fundraising-toolkit' ) } );
        } finally {
            setBusy( '' );
        }
    };

    return (
        <div className="fundkit-panel">
            <table className="fundkit-exports">
                <thead>
                    <tr>
                        <th scope="col">{ __( 'Export type', 'fundraising-toolkit' ) }</th>
                        <th scope="col">{ __( 'Options', 'fundraising-toolkit' ) }</th>
                    </tr>
                </thead>
                <tbody>
                    { canDonations && (
                    <Row
                        title={ __( 'Donations', 'fundraising-toolkit' ) }
                        description={ __( 'Every donation as a CSV: reference, donor, amount, status, campaign and gateway.', 'fundraising-toolkit' ) }
                    >
                        <div className="fundkit-exports__controls">
                            <span className="fundkit-tools-field">
                                { __( 'From', 'fundraising-toolkit' ) }
                                <DateField
                                    value={ donationsFrom }
                                    onChange={ ( v ) => setDonationsFrom( v || '' ) }
                                    ariaLabel={ __( 'Export donations from', 'fundraising-toolkit' ) }
                                    placeholder={ __( 'Any', 'fundraising-toolkit' ) }
                                />
                            </span>
                            <span className="fundkit-tools-field">
                                { __( 'To', 'fundraising-toolkit' ) }
                                <DateField
                                    value={ donationsTo }
                                    onChange={ ( v ) => setDonationsTo( v || '' ) }
                                    ariaLabel={ __( 'Export donations to', 'fundraising-toolkit' ) }
                                    placeholder={ __( 'Any', 'fundraising-toolkit' ) }
                                />
                            </span>
                            <Btn
                                variant="secondary"
                                disabled={ busy === 'donations' }
                                isBusy={ busy === 'donations' }
                                onClick={ () => download( donationsPath, setNotice, ( b ) => setBusy( b ? 'donations' : '' ), 'donations.csv' ) }
                            >
                                { __( 'Generate CSV', 'fundraising-toolkit' ) }
                            </Btn>
                        </div>
                        <label className="fundkit-exports__check">
                            <input type="checkbox" checked={ includeTest } onChange={ ( e ) => setIncludeTest( e.target.checked ) } />
                            { __( 'Include test donations', 'fundraising-toolkit' ) }
                        </label>
                    </Row>
                    ) }

                    { canReports && (
                        <Row
                            title={ __( 'Revenue report (PDF)', 'fundraising-toolkit' ) }
                            description={ __( 'A one-page summary of a year: total raised, month by month, and the best month. No donor details, so it can go straight to a board.', 'fundraising-toolkit' ) }
                        >
                            <div className="fundkit-exports__controls">
                                <label className="fundkit-tools-field">
                                    { __( 'Year', 'fundraising-toolkit' ) }
                                    <select className="fundkit-select" value={ pdfYear } onChange={ ( e ) => setPdfYear( Number( e.target.value ) ) }>
                                        { years.map( ( y ) => <option key={ y } value={ y }>{ y }</option> ) }
                                    </select>
                                </label>
                                <Btn
                                    variant="secondary"
                                    disabled={ busy === 'pdf' }
                                    isBusy={ busy === 'pdf' }
                                    onClick={ () => download( `/fundkit/v1/admin/exports/revenue.pdf?year=${ pdfYear }`, setNotice, ( b ) => setBusy( b ? 'pdf' : '' ), 'revenue.pdf' ) }
                                >
                                    { __( 'Generate PDF', 'fundraising-toolkit' ) }
                                </Btn>
                            </div>
                        </Row>
                    ) }

                    { canReports && (
                        <Row
                            title={ __( 'Revenue by month', 'fundraising-toolkit' ) }
                            description={ __( 'Revenue, donation count and average donation for every month in the range. Quiet months are written as zero rows, so the file charts as a continuous series.', 'fundraising-toolkit' ) }
                        >
                            <div className="fundkit-exports__controls">
                                <span className="fundkit-tools-field">
                                    { __( 'From', 'fundraising-toolkit' ) }
                                    <MonthField
                                        value={ statsFrom }
                                        onChange={ setStatsFrom }
                                        min={ opts?.first_month }
                                        max={ opts?.current_month }
                                        ariaLabel={ __( 'Revenue from month', 'fundraising-toolkit' ) }
                                    />
                                </span>
                                <span className="fundkit-tools-field">
                                    { __( 'To', 'fundraising-toolkit' ) }
                                    <MonthField
                                        value={ statsTo }
                                        onChange={ setStatsTo }
                                        min={ opts?.first_month }
                                        max={ opts?.current_month }
                                        ariaLabel={ __( 'Revenue to month', 'fundraising-toolkit' ) }
                                    />
                                </span>
                                <Btn
                                    variant="secondary"
                                    disabled={ busy === 'stats' }
                                    isBusy={ busy === 'stats' }
                                    onClick={ () => download( statsPath, setNotice, ( b ) => setBusy( b ? 'stats' : '' ), 'revenue.csv' ) }
                                >
                                    { __( 'Generate CSV', 'fundraising-toolkit' ) }
                                </Btn>
                            </div>
                        </Row>
                    ) }

                    { canDonors && (
                        <Row
                            title={ __( 'Donors', 'fundraising-toolkit' ) }
                            description={ __( 'The donor list as a CSV, by when each donor record was created. Take only the columns you need: names, emails, phone numbers and addresses are personal data, and this file is not encrypted once it leaves the site.', 'fundraising-toolkit' ) }
                        >
                            <div className="fundkit-exports__controls">
                                <span className="fundkit-tools-field">
                                    { __( 'From', 'fundraising-toolkit' ) }
                                    <DateField
                                        value={ donorsFrom }
                                        onChange={ ( v ) => setDonorsFrom( v || '' ) }
                                        ariaLabel={ __( 'Export donors from', 'fundraising-toolkit' ) }
                                        placeholder={ __( 'Any', 'fundraising-toolkit' ) }
                                    />
                                </span>
                                <span className="fundkit-tools-field">
                                    { __( 'To', 'fundraising-toolkit' ) }
                                    <DateField
                                        value={ donorsTo }
                                        onChange={ ( v ) => setDonorsTo( v || '' ) }
                                        ariaLabel={ __( 'Export donors to', 'fundraising-toolkit' ) }
                                        placeholder={ __( 'Any', 'fundraising-toolkit' ) }
                                    />
                                </span>
                                <label className="fundkit-tools-field">
                                    { __( 'Campaign', 'fundraising-toolkit' ) }
                                    <select className="fundkit-select" value={ donorsCampaign } onChange={ ( e ) => setDonorsCampaign( Number( e.target.value ) ) }>
                                        <option value={ 0 }>{ __( 'All campaigns', 'fundraising-toolkit' ) }</option>
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
                                    { __( 'Generate CSV', 'fundraising-toolkit' ) }
                                </Btn>
                            </div>

                            <div className="fundkit-exports__columns">
                                <p className="fundkit-exports__columns-head">{ __( 'Columns', 'fundraising-toolkit' ) }</p>
                                <div className="fundkit-exports__grid">
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
                                    <p className="fundkit-tools-note">
                                        { __( 'Pick at least one column. With none selected the file would still carry names and email addresses.', 'fundraising-toolkit' ) }
                                    </p>
                                ) }
                            </div>
                        </Row>
                    ) }

                    { canEverything && (
                    <Row
                        title={ __( 'Everything', 'fundraising-toolkit' ) }
                        description={ __( 'Campaigns, funds, forms, donors, donations, recurring plans and receipts as one JSON file, which the Import tab can restore onto another Fundraising Toolkit site.', 'fundraising-toolkit' ) }
                    >
                        <div className="fundkit-exports__controls">
                            <Btn
                                variant="secondary"
                                disabled={ busy === 'everything' }
                                isBusy={ busy === 'everything' }
                                onClick={ () => download( '/fundkit/v1/admin/tools/export-all', setNotice, ( b ) => setBusy( b ? 'everything' : '' ), 'fundkit-export.json' ) }
                            >
                                { __( 'Export JSON', 'fundraising-toolkit' ) }
                            </Btn>
                        </div>
                        <p className="fundkit-tools-note">
                            { __( 'Donor names, email addresses and postal addresses are readable in this file. They have to be, or it could only ever be restored onto the site it came from. Treat it like the donor database it is.', 'fundraising-toolkit' ) }
                        </p>
                    </Row>
                    ) }

                    { canEverything && (
                    <Row
                        title={ __( 'Settings', 'fundraising-toolkit' ) }
                        description={ __( 'Every Fundraising Toolkit setting as JSON, to lift a configured site onto another install. Donations, donors and campaigns are not included.', 'fundraising-toolkit' ) }
                    >
                        <div className="fundkit-exports__controls">
                            <Btn
                                variant="secondary"
                                disabled={ busy === 'settings' }
                                isBusy={ busy === 'settings' }
                                onClick={ exportSettings }
                            >
                                { __( 'Export JSON', 'fundraising-toolkit' ) }
                            </Btn>
                        </div>
                        <p className="fundkit-tools-note">
                            { __( 'Secrets are masked. A gateway key never leaves the site in an export, so an imported file cannot restore one.', 'fundraising-toolkit' ) }
                        </p>
                    </Row>
                    ) }
                </tbody>
            </table>
        </div>
    );
}
