import { useEffect, useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import Btn from '../../_shared/components/Btn';
import DateField from '../../_shared/components/DateField';
import MonthField from '../../_shared/components/MonthField';

/** Fetch rather than a bare link: the REST route needs the nonce header. */
async function download( path, setNotice, setBusy, fallbackName ) {
    setBusy( true );
    setNotice( null );
    try {
        const res  = await apiFetch( { path, parse: false } );
        const blob = await res.blob();

        if ( blob.size === 0 ) {
            setNotice( { type: 'error', text: __( 'That export came back empty.', 'giveflow-fundraising-campaigns' ) } );
            return;
        }

        const match = ( res.headers.get( 'content-disposition' ) || '' ).match( /filename="([^"]+)"/ );
        const url   = URL.createObjectURL( blob );
        const a     = document.createElement( 'a' );
        a.href      = url;
        a.download  = match ? match[ 1 ] : fallbackName;
        document.body.appendChild( a );
        a.click();
        a.remove();
        URL.revokeObjectURL( url );
    } catch ( err ) {
        setNotice( { type: 'error', text: err?.message || __( 'That export could not be generated.', 'giveflow-fundraising-campaigns' ) } );
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
        apiFetch( { path: '/giveflow/v1/admin/exports/options' } )
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
                    text: __( 'The export options could not be loaded, so the choices below are incomplete. Reload the page to try again.', 'giveflow-fundraising-campaigns' ),
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
        return '/giveflow/v1/admin/donations/export.csv' + ( s ? `?${ s }` : '' );
    }, [ donationsFrom, donationsTo, includeTest ] );

    const donorsPath = useMemo( () => {
        const q = new URLSearchParams();
        if ( donorsFrom ) q.set( 'from', donorsFrom );
        if ( donorsTo )   q.set( 'to', donorsTo );
        if ( donorsCampaign ) q.set( 'campaign_id', String( donorsCampaign ) );
        if ( columns.length ) q.set( 'columns', columns.join( ',' ) );
        return `/giveflow/v1/admin/exports/donors.csv?${ q.toString() }`;
    }, [ donorsFrom, donorsTo, donorsCampaign, columns ] );

    const statsPath = `/giveflow/v1/admin/exports/revenue.csv?from=${ statsFrom }&to=${ statsTo }`;

    const canDonors  = opts?.can_export_donors !== false;
    const canReports = opts?.can_view_reports !== false;

    const exportSettings = async () => {
        setBusy( 'settings' );
        setNotice( null );
        try {
            const data = await apiFetch( { path: '/giveflow/v1/admin/tools/export' } );
            const blob = new Blob( [ JSON.stringify( data, null, 2 ) ], { type: 'application/json' } );
            const url  = URL.createObjectURL( blob );
            const a    = document.createElement( 'a' );
            a.href     = url;
            a.download = `giveflow-settings-${ new Date().toISOString().slice( 0, 10 ) }.json`;
            document.body.appendChild( a );
            a.click();
            a.remove();
            URL.revokeObjectURL( url );
        } catch ( err ) {
            setNotice( { type: 'error', text: err?.message || __( 'Export failed.', 'giveflow-fundraising-campaigns' ) } );
        } finally {
            setBusy( '' );
        }
    };

    return (
        <div className="giveflow-panel">
            <table className="giveflow-exports">
                <thead>
                    <tr>
                        <th scope="col">{ __( 'Export type', 'giveflow-fundraising-campaigns' ) }</th>
                        <th scope="col">{ __( 'Options', 'giveflow-fundraising-campaigns' ) }</th>
                    </tr>
                </thead>
                <tbody>
                    <Row
                        title={ __( 'Donations', 'giveflow-fundraising-campaigns' ) }
                        description={ __( 'Every donation as a CSV: reference, donor, amount, status, campaign and gateway.', 'giveflow-fundraising-campaigns' ) }
                    >
                        <div className="giveflow-exports__controls">
                            <span className="giveflow-tools-field">
                                { __( 'From', 'giveflow-fundraising-campaigns' ) }
                                <DateField
                                    value={ donationsFrom }
                                    onChange={ ( v ) => setDonationsFrom( v || '' ) }
                                    ariaLabel={ __( 'Export donations from', 'giveflow-fundraising-campaigns' ) }
                                    placeholder={ __( 'Any', 'giveflow-fundraising-campaigns' ) }
                                />
                            </span>
                            <span className="giveflow-tools-field">
                                { __( 'To', 'giveflow-fundraising-campaigns' ) }
                                <DateField
                                    value={ donationsTo }
                                    onChange={ ( v ) => setDonationsTo( v || '' ) }
                                    ariaLabel={ __( 'Export donations to', 'giveflow-fundraising-campaigns' ) }
                                    placeholder={ __( 'Any', 'giveflow-fundraising-campaigns' ) }
                                />
                            </span>
                            <Btn
                                variant="secondary"
                                disabled={ busy === 'donations' }
                                isBusy={ busy === 'donations' }
                                onClick={ () => download( donationsPath, setNotice, ( b ) => setBusy( b ? 'donations' : '' ), 'donations.csv' ) }
                            >
                                { __( 'Generate CSV', 'giveflow-fundraising-campaigns' ) }
                            </Btn>
                        </div>
                        <label className="giveflow-exports__check">
                            <input type="checkbox" checked={ includeTest } onChange={ ( e ) => setIncludeTest( e.target.checked ) } />
                            { __( 'Include test donations', 'giveflow-fundraising-campaigns' ) }
                        </label>
                    </Row>

                    { canReports && (
                        <Row
                            title={ __( 'Revenue report (PDF)', 'giveflow-fundraising-campaigns' ) }
                            description={ __( 'A one-page summary of a year: total raised, month by month, and the best month. No donor details, so it can go straight to a board.', 'giveflow-fundraising-campaigns' ) }
                        >
                            <div className="giveflow-exports__controls">
                                <label className="giveflow-tools-field">
                                    { __( 'Year', 'giveflow-fundraising-campaigns' ) }
                                    <select className="giveflow-select" value={ pdfYear } onChange={ ( e ) => setPdfYear( Number( e.target.value ) ) }>
                                        { years.map( ( y ) => <option key={ y } value={ y }>{ y }</option> ) }
                                    </select>
                                </label>
                                <Btn
                                    variant="secondary"
                                    disabled={ busy === 'pdf' }
                                    isBusy={ busy === 'pdf' }
                                    onClick={ () => download( `/giveflow/v1/admin/exports/revenue.pdf?year=${ pdfYear }`, setNotice, ( b ) => setBusy( b ? 'pdf' : '' ), 'revenue.pdf' ) }
                                >
                                    { __( 'Generate PDF', 'giveflow-fundraising-campaigns' ) }
                                </Btn>
                            </div>
                        </Row>
                    ) }

                    { canReports && (
                        <Row
                            title={ __( 'Revenue by month', 'giveflow-fundraising-campaigns' ) }
                            description={ __( 'Revenue, donation count and average donation for every month in the range. Quiet months are written as zero rows, so the file charts as a continuous series.', 'giveflow-fundraising-campaigns' ) }
                        >
                            <div className="giveflow-exports__controls">
                                <span className="giveflow-tools-field">
                                    { __( 'From', 'giveflow-fundraising-campaigns' ) }
                                    <MonthField
                                        value={ statsFrom }
                                        onChange={ setStatsFrom }
                                        min={ opts?.first_month }
                                        max={ opts?.current_month }
                                        ariaLabel={ __( 'Revenue from month', 'giveflow-fundraising-campaigns' ) }
                                    />
                                </span>
                                <span className="giveflow-tools-field">
                                    { __( 'To', 'giveflow-fundraising-campaigns' ) }
                                    <MonthField
                                        value={ statsTo }
                                        onChange={ setStatsTo }
                                        min={ opts?.first_month }
                                        max={ opts?.current_month }
                                        ariaLabel={ __( 'Revenue to month', 'giveflow-fundraising-campaigns' ) }
                                    />
                                </span>
                                <Btn
                                    variant="secondary"
                                    disabled={ busy === 'stats' }
                                    isBusy={ busy === 'stats' }
                                    onClick={ () => download( statsPath, setNotice, ( b ) => setBusy( b ? 'stats' : '' ), 'revenue.csv' ) }
                                >
                                    { __( 'Generate CSV', 'giveflow-fundraising-campaigns' ) }
                                </Btn>
                            </div>
                        </Row>
                    ) }

                    { canDonors && (
                        <Row
                            title={ __( 'Donors', 'giveflow-fundraising-campaigns' ) }
                            description={ __( 'The donor list as a CSV, by when each donor record was created. Take only the columns you need: names, emails, phone numbers and addresses are personal data, and this file is not encrypted once it leaves the site.', 'giveflow-fundraising-campaigns' ) }
                        >
                            <div className="giveflow-exports__controls">
                                <span className="giveflow-tools-field">
                                    { __( 'From', 'giveflow-fundraising-campaigns' ) }
                                    <DateField
                                        value={ donorsFrom }
                                        onChange={ ( v ) => setDonorsFrom( v || '' ) }
                                        ariaLabel={ __( 'Export donors from', 'giveflow-fundraising-campaigns' ) }
                                        placeholder={ __( 'Any', 'giveflow-fundraising-campaigns' ) }
                                    />
                                </span>
                                <span className="giveflow-tools-field">
                                    { __( 'To', 'giveflow-fundraising-campaigns' ) }
                                    <DateField
                                        value={ donorsTo }
                                        onChange={ ( v ) => setDonorsTo( v || '' ) }
                                        ariaLabel={ __( 'Export donors to', 'giveflow-fundraising-campaigns' ) }
                                        placeholder={ __( 'Any', 'giveflow-fundraising-campaigns' ) }
                                    />
                                </span>
                                <label className="giveflow-tools-field">
                                    { __( 'Campaign', 'giveflow-fundraising-campaigns' ) }
                                    <select className="giveflow-select" value={ donorsCampaign } onChange={ ( e ) => setDonorsCampaign( Number( e.target.value ) ) }>
                                        <option value={ 0 }>{ __( 'All campaigns', 'giveflow-fundraising-campaigns' ) }</option>
                                        { ( opts?.campaigns || [] ).map( ( c ) => (
                                            <option key={ c.id } value={ c.id }>{ c.title || `#${ c.id }` }</option>
                                        ) ) }
                                    </select>
                                </label>
                                <Btn
                                    variant="secondary"
                                    disabled={ busy === 'donors' }
                                    isBusy={ busy === 'donors' }
                                    onClick={ () => download( donorsPath, setNotice, ( b ) => setBusy( b ? 'donors' : '' ), 'donors.csv' ) }
                                >
                                    { __( 'Generate CSV', 'giveflow-fundraising-campaigns' ) }
                                </Btn>
                            </div>

                            <div className="giveflow-exports__columns">
                                <p className="giveflow-exports__columns-head">{ __( 'Columns', 'giveflow-fundraising-campaigns' ) }</p>
                                <div className="giveflow-exports__grid">
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
                            </div>
                        </Row>
                    ) }

                    <Row
                        title={ __( 'Everything', 'giveflow-fundraising-campaigns' ) }
                        description={ __( 'Campaigns, funds, forms, donors, donations, recurring plans and receipts as one JSON file, which the Import tab can restore onto another GiveFlow site.', 'giveflow-fundraising-campaigns' ) }
                    >
                        <div className="giveflow-exports__controls">
                            <Btn
                                variant="secondary"
                                disabled={ busy === 'everything' }
                                isBusy={ busy === 'everything' }
                                onClick={ () => download( '/giveflow/v1/admin/tools/export-all', setNotice, ( b ) => setBusy( b ? 'everything' : '' ), 'giveflow-export.json' ) }
                            >
                                { __( 'Export JSON', 'giveflow-fundraising-campaigns' ) }
                            </Btn>
                        </div>
                        <p className="giveflow-tools-note">
                            { __( 'Donor names, email addresses and postal addresses are readable in this file. They have to be, or it could only ever be restored onto the site it came from. Treat it like the donor database it is.', 'giveflow-fundraising-campaigns' ) }
                        </p>
                    </Row>

                    <Row
                        title={ __( 'Settings', 'giveflow-fundraising-campaigns' ) }
                        description={ __( 'Every GiveFlow setting as JSON, to lift a configured site onto another install. Donations, donors and campaigns are not included.', 'giveflow-fundraising-campaigns' ) }
                    >
                        <div className="giveflow-exports__controls">
                            <Btn
                                variant="secondary"
                                disabled={ busy === 'settings' }
                                isBusy={ busy === 'settings' }
                                onClick={ exportSettings }
                            >
                                { __( 'Export JSON', 'giveflow-fundraising-campaigns' ) }
                            </Btn>
                        </div>
                        <p className="giveflow-tools-note">
                            { __( 'Secrets are masked. A gateway key never leaves the site in an export, so an imported file cannot restore one.', 'giveflow-fundraising-campaigns' ) }
                        </p>
                    </Row>
                </tbody>
            </table>
        </div>
    );
}
