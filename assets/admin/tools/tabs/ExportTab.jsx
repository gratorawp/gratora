import { useEffect, useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import Btn from '../../_shared/components/Btn';
import DateField from '../../_shared/components/DateField';

/** Fetch rather than a bare link: the REST route needs the nonce header. */
async function download( path, setNotice, setBusy, fallbackName ) {
    setBusy( true );
    setNotice( null );
    try {
        const res  = await apiFetch( { path, parse: false } );
        const blob = await res.blob();

        if ( blob.size === 0 ) {
            setNotice( { type: 'error', text: __( 'That export came back empty.', 'dono' ) } );
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
        setNotice( { type: 'error', text: err?.message || __( 'That export could not be generated.', 'dono' ) } );
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

    // Month granularity, held as a date so DateField can drive it. Only the
    // YYYY-MM prefix is sent, so any day in a month selects that whole month.
    const [ statsFrom, setStatsFrom ] = useState( '' );
    const [ statsTo, setStatsTo ]     = useState( '' );

    const [ donorsFrom, setDonorsFrom ]   = useState( '' );
    const [ donorsTo, setDonorsTo ]       = useState( '' );
    const [ donorsCampaign, setDonorsCampaign ] = useState( 0 );
    const [ columns, setColumns ]         = useState( [] );

    useEffect( () => {
        apiFetch( { path: '/dono/v1/admin/exports/options' } )
            .then( ( o ) => {
                setOpts( o );
                setPdfYear( o.current_year );
                setStatsFrom( `${ o.current_year }-01-01` );
                setStatsTo( `${ o.current_month }-01` );
                // Every column on by default: a file silently missing a column
                // is worse than one carrying a column nobody wanted.
                setColumns( ( o.donor_columns || [] ).map( ( c ) => c.key ) );
            } )
            .catch( () => setOpts( { donor_columns: [], campaigns: [], years: [ new Date().getFullYear() ] } ) );
    }, [] );

    const years = opts?.years || [];
    const month = ( v ) => String( v || '' ).slice( 0, 7 );

    const toggleColumn = ( key ) => setColumns( ( c ) => (
        c.includes( key ) ? c.filter( ( k ) => k !== key ) : [ ...c, key ]
    ) );

    const donationsPath = useMemo( () => {
        const q = new URLSearchParams();
        if ( donationsFrom ) q.set( 'created_from', donationsFrom );
        if ( donationsTo )   q.set( 'created_to', donationsTo );
        if ( includeTest )   q.set( 'include_test', '1' );
        const s = q.toString();
        return '/dono/v1/admin/donations/export.csv' + ( s ? `?${ s }` : '' );
    }, [ donationsFrom, donationsTo, includeTest ] );

    const donorsPath = useMemo( () => {
        const q = new URLSearchParams();
        if ( donorsFrom ) q.set( 'from', donorsFrom );
        if ( donorsTo )   q.set( 'to', donorsTo );
        if ( donorsCampaign ) q.set( 'campaign_id', String( donorsCampaign ) );
        if ( columns.length ) q.set( 'columns', columns.join( ',' ) );
        return `/dono/v1/admin/exports/donors.csv?${ q.toString() }`;
    }, [ donorsFrom, donorsTo, donorsCampaign, columns ] );

    const statsPath = `/dono/v1/admin/exports/revenue.csv?from=${ month( statsFrom ) }&to=${ month( statsTo ) }`;

    const canDonors  = opts?.can_export_donors !== false;
    const canReports = opts?.can_view_reports !== false;

    const exportSettings = async () => {
        setBusy( 'settings' );
        setNotice( null );
        try {
            const data = await apiFetch( { path: '/dono/v1/admin/tools/export' } );
            const blob = new Blob( [ JSON.stringify( data, null, 2 ) ], { type: 'application/json' } );
            const url  = URL.createObjectURL( blob );
            const a    = document.createElement( 'a' );
            a.href     = url;
            a.download = `dono-settings-${ new Date().toISOString().slice( 0, 10 ) }.json`;
            document.body.appendChild( a );
            a.click();
            a.remove();
            URL.revokeObjectURL( url );
        } catch ( err ) {
            setNotice( { type: 'error', text: err?.message || __( 'Export failed.', 'dono' ) } );
        } finally {
            setBusy( '' );
        }
    };

    return (
        <div className="dono-panel">
            <table className="dono-exports">
                <thead>
                    <tr>
                        <th scope="col">{ __( 'Export type', 'dono' ) }</th>
                        <th scope="col">{ __( 'Options', 'dono' ) }</th>
                    </tr>
                </thead>
                <tbody>
                    <Row
                        title={ __( 'Donations', 'dono' ) }
                        description={ __( 'Every donation as a CSV: reference, donor, amount, status, campaign and gateway.', 'dono' ) }
                    >
                        <div className="dono-exports__controls">
                            <span className="dono-tools-field">
                                { __( 'From', 'dono' ) }
                                <DateField
                                    value={ donationsFrom }
                                    onChange={ ( v ) => setDonationsFrom( v || '' ) }
                                    ariaLabel={ __( 'Export donations from', 'dono' ) }
                                    placeholder={ __( 'Any', 'dono' ) }
                                />
                            </span>
                            <span className="dono-tools-field">
                                { __( 'To', 'dono' ) }
                                <DateField
                                    value={ donationsTo }
                                    onChange={ ( v ) => setDonationsTo( v || '' ) }
                                    ariaLabel={ __( 'Export donations to', 'dono' ) }
                                    placeholder={ __( 'Any', 'dono' ) }
                                />
                            </span>
                            <Btn
                                variant="secondary"
                                disabled={ busy === 'donations' }
                                isBusy={ busy === 'donations' }
                                onClick={ () => download( donationsPath, setNotice, ( b ) => setBusy( b ? 'donations' : '' ), 'donations.csv' ) }
                            >
                                { __( 'Generate CSV', 'dono' ) }
                            </Btn>
                        </div>
                        <label className="dono-exports__check">
                            <input type="checkbox" checked={ includeTest } onChange={ ( e ) => setIncludeTest( e.target.checked ) } />
                            { __( 'Include test donations', 'dono' ) }
                        </label>
                    </Row>

                    { canReports && (
                        <Row
                            title={ __( 'Revenue report (PDF)', 'dono' ) }
                            description={ __( 'A one-page summary of a year: total raised, month by month, and the best month. No donor details, so it can go straight to a board.', 'dono' ) }
                        >
                            <div className="dono-exports__controls">
                                <label className="dono-tools-field">
                                    { __( 'Year', 'dono' ) }
                                    <select className="dono-select" value={ pdfYear } onChange={ ( e ) => setPdfYear( Number( e.target.value ) ) }>
                                        { years.map( ( y ) => <option key={ y } value={ y }>{ y }</option> ) }
                                    </select>
                                </label>
                                <Btn
                                    variant="secondary"
                                    disabled={ busy === 'pdf' }
                                    isBusy={ busy === 'pdf' }
                                    onClick={ () => download( `/dono/v1/admin/exports/revenue.pdf?year=${ pdfYear }`, setNotice, ( b ) => setBusy( b ? 'pdf' : '' ), 'revenue.pdf' ) }
                                >
                                    { __( 'Generate PDF', 'dono' ) }
                                </Btn>
                            </div>
                        </Row>
                    ) }

                    { canReports && (
                        <Row
                            title={ __( 'Revenue by month', 'dono' ) }
                            description={ __( 'Revenue, donation count and average gift for every month in the range. Quiet months are written as zero rows, so the file charts as a continuous series.', 'dono' ) }
                        >
                            <div className="dono-exports__controls">
                                <span className="dono-tools-field">
                                    { __( 'From', 'dono' ) }
                                    <DateField
                                        value={ statsFrom }
                                        onChange={ ( v ) => setStatsFrom( v || '' ) }
                                        format="F Y"
                                        allowClear={ false }
                                        ariaLabel={ __( 'Revenue from month', 'dono' ) }
                                    />
                                </span>
                                <span className="dono-tools-field">
                                    { __( 'To', 'dono' ) }
                                    <DateField
                                        value={ statsTo }
                                        onChange={ ( v ) => setStatsTo( v || '' ) }
                                        format="F Y"
                                        allowClear={ false }
                                        ariaLabel={ __( 'Revenue to month', 'dono' ) }
                                    />
                                </span>
                                <Btn
                                    variant="secondary"
                                    disabled={ busy === 'stats' }
                                    isBusy={ busy === 'stats' }
                                    onClick={ () => download( statsPath, setNotice, ( b ) => setBusy( b ? 'stats' : '' ), 'revenue.csv' ) }
                                >
                                    { __( 'Generate CSV', 'dono' ) }
                                </Btn>
                            </div>
                        </Row>
                    ) }

                    { canDonors && (
                        <Row
                            title={ __( 'Donors', 'dono' ) }
                            description={ __( 'The donor list as a CSV, by when each donor record was created. Take only the columns you need: names, emails, phone numbers and addresses are personal data, and this file is not encrypted once it leaves the site.', 'dono' ) }
                        >
                            <div className="dono-exports__controls">
                                <span className="dono-tools-field">
                                    { __( 'From', 'dono' ) }
                                    <DateField
                                        value={ donorsFrom }
                                        onChange={ ( v ) => setDonorsFrom( v || '' ) }
                                        ariaLabel={ __( 'Export donors from', 'dono' ) }
                                        placeholder={ __( 'Any', 'dono' ) }
                                    />
                                </span>
                                <span className="dono-tools-field">
                                    { __( 'To', 'dono' ) }
                                    <DateField
                                        value={ donorsTo }
                                        onChange={ ( v ) => setDonorsTo( v || '' ) }
                                        ariaLabel={ __( 'Export donors to', 'dono' ) }
                                        placeholder={ __( 'Any', 'dono' ) }
                                    />
                                </span>
                                <label className="dono-tools-field">
                                    { __( 'Campaign', 'dono' ) }
                                    <select className="dono-select" value={ donorsCampaign } onChange={ ( e ) => setDonorsCampaign( Number( e.target.value ) ) }>
                                        <option value={ 0 }>{ __( 'All campaigns', 'dono' ) }</option>
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
                                    { __( 'Generate CSV', 'dono' ) }
                                </Btn>
                            </div>

                            <div className="dono-exports__columns">
                                <p className="dono-exports__columns-head">{ __( 'Columns', 'dono' ) }</p>
                                <div className="dono-exports__grid">
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
                        title={ __( 'Settings', 'dono' ) }
                        description={ __( 'Every Dono setting as JSON, to lift a configured site onto another install. Donations, donors and campaigns are not included.', 'dono' ) }
                    >
                        <div className="dono-exports__controls">
                            <Btn
                                variant="secondary"
                                disabled={ busy === 'settings' }
                                isBusy={ busy === 'settings' }
                                onClick={ exportSettings }
                            >
                                { __( 'Export JSON', 'dono' ) }
                            </Btn>
                        </div>
                        <p className="dono-tools-note">
                            { __( 'Secrets are masked. A gateway key never leaves the site in an export, so an imported file cannot restore one.', 'dono' ) }
                        </p>
                    </Row>
                </tbody>
            </table>
        </div>
    );
}
