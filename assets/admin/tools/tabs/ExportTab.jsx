import { useEffect, useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import Btn from '../../_shared/components/Btn';

const monthNames = () => [
    __( 'Jan', 'dono' ), __( 'Feb', 'dono' ), __( 'Mar', 'dono' ), __( 'Apr', 'dono' ),
    __( 'May', 'dono' ), __( 'Jun', 'dono' ), __( 'Jul', 'dono' ), __( 'Aug', 'dono' ),
    __( 'Sep', 'dono' ), __( 'Oct', 'dono' ), __( 'Nov', 'dono' ), __( 'Dec', 'dono' ),
];

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
    const [ forms, setForms ] = useState( [] );

    const [ donationsFrom, setDonationsFrom ] = useState( '' );
    const [ donationsTo, setDonationsTo ]     = useState( '' );
    const [ includeTest, setIncludeTest ]     = useState( false );

    const [ pdfYear, setPdfYear ] = useState( 0 );

    const [ statsFromYear, setStatsFromYear ]   = useState( 0 );
    const [ statsFromMonth, setStatsFromMonth ] = useState( 0 );
    const [ statsToYear, setStatsToYear ]       = useState( 0 );
    const [ statsToMonth, setStatsToMonth ]     = useState( 0 );

    const [ donorsFrom, setDonorsFrom ]   = useState( '' );
    const [ donorsTo, setDonorsTo ]       = useState( '' );
    const [ donorsBasis, setDonorsBasis ] = useState( 'donation' );
    const [ donorsForm, setDonorsForm ]   = useState( 0 );
    const [ columns, setColumns ]         = useState( [] );

    useEffect( () => {
        apiFetch( { path: '/dono/v1/admin/exports/options' } )
            .then( ( o ) => {
                setOpts( o );
                setPdfYear( o.current_year );
                setStatsFromYear( o.current_year );
                setStatsToYear( o.current_year );
                setStatsToMonth( new Date().getMonth() );
                // Every column on by default: a file silently missing a column
                // is worse than one carrying a column nobody wanted.
                setColumns( ( o.donor_columns || [] ).map( ( c ) => c.key ) );
            } )
            .catch( () => setOpts( { donor_columns: [], years: [ new Date().getFullYear() ] } ) );

        apiFetch( { path: '/dono/v1/admin/forms?per_page=100' } )
            .then( ( r ) => setForms( Array.isArray( r?.items ) ? r.items : [] ) )
            .catch( () => setForms( [] ) );
    }, [] );

    const years  = opts?.years || [];
    const months = monthNames();
    const pad    = ( n ) => String( n + 1 ).padStart( 2, '0' );

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
        q.set( 'basis', donorsBasis );
        if ( donorsForm ) q.set( 'form_id', String( donorsForm ) );
        if ( columns.length ) q.set( 'columns', columns.join( ',' ) );
        return `/dono/v1/admin/exports/donors.csv?${ q.toString() }`;
    }, [ donorsFrom, donorsTo, donorsBasis, donorsForm, columns ] );

    const statsPath = `/dono/v1/admin/exports/revenue.csv?from=${ statsFromYear }-${ pad( statsFromMonth ) }&to=${ statsToYear }-${ pad( statsToMonth ) }`;

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
                            <label className="dono-tools-field">
                                { __( 'From', 'dono' ) }
                                <input type="date" className="dono-input" value={ donationsFrom } onChange={ ( e ) => setDonationsFrom( e.target.value ) } />
                            </label>
                            <label className="dono-tools-field">
                                { __( 'To', 'dono' ) }
                                <input type="date" className="dono-input" value={ donationsTo } onChange={ ( e ) => setDonationsTo( e.target.value ) } />
                            </label>
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
                                <label className="dono-tools-field">
                                    { __( 'From', 'dono' ) }
                                    <span className="dono-exports__pair">
                                        <select className="dono-select" value={ statsFromYear } onChange={ ( e ) => setStatsFromYear( Number( e.target.value ) ) }>
                                            { years.map( ( y ) => <option key={ y } value={ y }>{ y }</option> ) }
                                        </select>
                                        <select className="dono-select" value={ statsFromMonth } onChange={ ( e ) => setStatsFromMonth( Number( e.target.value ) ) }>
                                            { months.map( ( m, i ) => <option key={ m } value={ i }>{ m }</option> ) }
                                        </select>
                                    </span>
                                </label>
                                <label className="dono-tools-field">
                                    { __( 'To', 'dono' ) }
                                    <span className="dono-exports__pair">
                                        <select className="dono-select" value={ statsToYear } onChange={ ( e ) => setStatsToYear( Number( e.target.value ) ) }>
                                            { years.map( ( y ) => <option key={ y } value={ y }>{ y }</option> ) }
                                        </select>
                                        <select className="dono-select" value={ statsToMonth } onChange={ ( e ) => setStatsToMonth( Number( e.target.value ) ) }>
                                            { months.map( ( m, i ) => <option key={ m } value={ i }>{ m }</option> ) }
                                        </select>
                                    </span>
                                </label>
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
                            description={ __( 'The donor list as a CSV. Take only the columns you need: names, emails, phone numbers and addresses are personal data, and this file is not encrypted once it leaves the site.', 'dono' ) }
                        >
                            <div className="dono-exports__controls">
                                <label className="dono-tools-field">
                                    { __( 'From', 'dono' ) }
                                    <input type="date" className="dono-input" value={ donorsFrom } onChange={ ( e ) => setDonorsFrom( e.target.value ) } />
                                </label>
                                <label className="dono-tools-field">
                                    { __( 'To', 'dono' ) }
                                    <input type="date" className="dono-input" value={ donorsTo } onChange={ ( e ) => setDonorsTo( e.target.value ) } />
                                </label>
                                <label className="dono-tools-field">
                                    { __( 'Form', 'dono' ) }
                                    <select className="dono-select" value={ donorsForm } onChange={ ( e ) => setDonorsForm( Number( e.target.value ) ) }>
                                        <option value={ 0 }>{ __( 'All forms', 'dono' ) }</option>
                                        { forms.map( ( f ) => <option key={ f.id } value={ f.id }>{ f.title || `#${ f.id }` }</option> ) }
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

                            <fieldset className="dono-exports__radios">
                                <legend>{ __( 'Match those dates against', 'dono' ) }</legend>
                                <label>
                                    <input
                                        type="radio"
                                        name="dono-donor-basis"
                                        checked={ donorsBasis === 'donation' }
                                        onChange={ () => setDonorsBasis( 'donation' ) }
                                    />
                                    { __( 'When they gave', 'dono' ) }
                                </label>
                                <label>
                                    <input
                                        type="radio"
                                        name="dono-donor-basis"
                                        checked={ donorsBasis === 'created' }
                                        onChange={ () => setDonorsBasis( 'created' ) }
                                    />
                                    { __( 'When the donor record was created', 'dono' ) }
                                </label>
                            </fieldset>

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
