import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import Header from './profile/Header';
import ConfirmDialog from '../_shared/components/ConfirmDialog';
import { COUNTRIES } from '../../_shared/countries';
import LifetimeMetrics from './profile/LifetimeMetrics';
import Tabs from './profile/Tabs';
import IdentityCard from './profile/IdentityCard';
import ActivityTab from './profile/tabs/ActivityTab';
import DonationsTab from './profile/tabs/DonationsTab';
import RecurringTab from './profile/tabs/RecurringTab';
import ReceiptsTab from './profile/tabs/ReceiptsTab';
import NotesTab from './profile/tabs/NotesTab';
import ConsentTab from './profile/tabs/ConsentTab';

// Permissive phone shape: digits, +, spaces, dashes, parentheses. We don't
// enforce E.164 here because donors paste freeform numbers from many regions;
// strict validation belongs at integration boundaries (Stripe, SMS gateways).
const PHONE_RE = /^[+\d][\d\s().\-]{4,30}$/;

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

function EditPanel( { donor, onCancel, onSaved } ) {
    const initialAddr = donor.address_parts && typeof donor.address_parts === 'object'
        ? donor.address_parts
        : {};
    const [ form, setForm ] = useState( {
        email:      donor.email      || '',
        first_name: donor.first_name || '',
        last_name:  donor.last_name  || '',
        country:    donor.country    || '',
        company:    donor.company    || '',
        donor_type: donor.donor_type || 'individual',
        phone:      donor.phone      || '',
        address: {
            line1:  initialAddr.line1  || '',
            line2:  initialAddr.line2  || '',
            city:   initialAddr.city   || '',
            region: initialAddr.region || '',
            postal: initialAddr.postal || '',
            country: initialAddr.country || '',
        },
    } );
    const setAddr = ( k ) => ( e ) => setForm( ( s ) => ( {
        ...s,
        address: { ...s.address, [ k ]: e.target.value },
    } ) );
    const [ countryQuery, setCountryQuery ] = useState( () => {
        const c = COUNTRIES.find( ( c ) => c.code === ( donor.country || '' ).toUpperCase() );
        return c ? c.name : '';
    } );
    const [ countryOpen, setCountryOpen ] = useState( false );
    const [ saving, setSaving ] = useState( false );
    const [ error, setError ]   = useState( null );
    const [ confirm, setConfirm ] = useState( null );

    const set = ( k ) => ( e ) => setForm( ( s ) => ( { ...s, [ k ]: e.target.value } ) );

    const phoneInvalid = form.phone.trim() !== '' && ! PHONE_RE.test( form.phone.trim() );
    const emailInvalid = form.email.trim() === '' || ! EMAIL_RE.test( form.email.trim() );
    const emailChanged = form.email.trim().toLowerCase() !== ( donor.email || '' ).trim().toLowerCase();

    const doSave = async () => {
        setSaving( true );
        setError( null );
        try {
            const updated = await apiFetch( {
                path:   `/dono/v1/admin/donors/${ donor.id }`,
                method: 'PATCH',
                data:   form,
            } );
            onSaved( updated );
        } catch ( err ) {
            setError( err?.message || 'Save failed' );
        } finally {
            setSaving( false );
        }
    };

    const submit = async ( e ) => {
        e.preventDefault();
        if ( phoneInvalid ) {
            setError( __( 'Phone number looks malformed. Use digits, +, spaces, parentheses, or dashes.', 'dono' ) );
            return;
        }
        if ( emailInvalid ) {
            setError( __( 'Email address looks malformed.', 'dono' ) );
            return;
        }
        if ( emailChanged ) {
            setConfirm( {
                title:        __( 'Change donor email', 'dono' ),
                message:      __( 'Change this donor\'s email? Future donations from the new address will link to this record.', 'dono' ),
                confirmLabel: __( 'Change email', 'dono' ),
                onConfirm:    doSave,
            } );
            return;
        }
        doSave();
    };

    const q = countryQuery.trim().toLowerCase();
    const countryMatches = q === ''
        ? COUNTRIES
        : COUNTRIES.filter( ( c ) => c.name.toLowerCase().includes( q ) || c.code.toLowerCase().startsWith( q ) );

    const pickCountry = ( c ) => {
        setForm( ( s ) => ( { ...s, country: c ? c.code : '' } ) );
        setCountryQuery( c ? c.name : '' );
        setCountryOpen( false );
    };

    return (
        <div className="dp-card" style={ { marginBottom: 16 } }>
            <div className="dp-card__body">
                <form className="dp-edit-form" onSubmit={ submit }>
                    <label style={ { gridColumn: '1 / -1' } }>
                        { __( 'Email', 'dono' ) }
                        <input className="dono-input"
                            type="email"
                            value={ form.email }
                            onChange={ set( 'email' ) }
                            required
                            maxLength={ 254 }
                            aria-invalid={ emailInvalid }
                        />
                        { emailChanged && (
                            <span className="dp-field__hint">
                                { __( 'Saving rehashes the donor identity. Future donations from this address will link to this record.', 'dono' ) }
                            </span>
                        ) }
                    </label>
                    <label>
                        { __( 'First name', 'dono' ) }
                        <input className="dono-input" type="text" value={ form.first_name } onChange={ set( 'first_name' ) } maxLength={ 100 } />
                    </label>
                    <label>
                        { __( 'Last name', 'dono' ) }
                        <input className="dono-input" type="text" value={ form.last_name } onChange={ set( 'last_name' ) } maxLength={ 100 } />
                    </label>
                    <label className="dp-edit-form__country">
                        { __( 'Country', 'dono' ) }
                        <div className="dp-edit-form__country-wrap">
                            <input className="dono-input"
                                type="text"
                                value={ countryQuery }
                                placeholder={ __( 'Search country…', 'dono' ) }
                                onFocus={ () => setCountryOpen( true ) }
                                onBlur={ () => setTimeout( () => setCountryOpen( false ), 150 ) }
                                onChange={ ( e ) => { setCountryQuery( e.target.value ); setCountryOpen( true ); } }
                            />
                            { countryOpen && countryMatches.length > 0 && (
                                <ul className="dp-edit-form__country-list">
                                    { countryMatches.slice( 0, 50 ).map( ( c ) => (
                                        <li key={ c.code }>
                                            <button type="button" onMouseDown={ ( e ) => { e.preventDefault(); pickCountry( c ); } }>
                                                <span>{ c.name }</span>
                                                <span className="dp-edit-form__country-code">{ c.code }</span>
                                            </button>
                                        </li>
                                    ) ) }
                                </ul>
                            ) }
                        </div>
                    </label>
                    <label>
                        { __( 'Type', 'dono' ) }
                        <select className="dono-select" value={ form.donor_type } onChange={ set( 'donor_type' ) }>
                            <option value="individual">{ __( 'Individual', 'dono' ) }</option>
                            <option value="organization">{ __( 'Organisation', 'dono' ) }</option>
                            <option value="household">{ __( 'Household', 'dono' ) }</option>
                        </select>
                    </label>
                    <label style={ { gridColumn: '1 / -1' } }>
                        { __( 'Company', 'dono' ) }
                        <input className="dono-input" type="text" value={ form.company } onChange={ set( 'company' ) } maxLength={ 150 } />
                    </label>
                    <label style={ { gridColumn: '1 / -1' } }>
                        { __( 'Phone', 'dono' ) }
                        <input className="dono-input"
                            type="tel"
                            value={ form.phone }
                            onChange={ set( 'phone' ) }
                            placeholder="+49 30 1234 5678"
                            maxLength={ 32 }
                            aria-invalid={ phoneInvalid }
                        />
                        { phoneInvalid && (
                            <span className="dp-field__hint dp-field__hint--err">
                                { __( 'Use digits, +, spaces, parentheses, or dashes.', 'dono' ) }
                            </span>
                        ) }
                    </label>
                    <label style={ { gridColumn: '1 / -1' } }>
                        { __( 'Address line 1', 'dono' ) }
                        <input className="dono-input"
                            type="text"
                            value={ form.address.line1 }
                            onChange={ setAddr( 'line1' ) }
                            placeholder={ __( 'Street and number', 'dono' ) }
                            maxLength={ 200 }
                        />
                    </label>
                    <label style={ { gridColumn: '1 / -1' } }>
                        { __( 'Address line 2', 'dono' ) }
                        <input className="dono-input"
                            type="text"
                            value={ form.address.line2 }
                            onChange={ setAddr( 'line2' ) }
                            placeholder={ __( 'Apartment, suite, etc. (optional)', 'dono' ) }
                            maxLength={ 200 }
                        />
                    </label>
                    <label>
                        { __( 'City', 'dono' ) }
                        <input className="dono-input"
                            type="text"
                            value={ form.address.city }
                            onChange={ setAddr( 'city' ) }
                            maxLength={ 100 }
                        />
                    </label>
                    <label>
                        { __( 'Region', 'dono' ) }
                        <input className="dono-input"
                            type="text"
                            value={ form.address.region }
                            onChange={ setAddr( 'region' ) }
                            maxLength={ 100 }
                        />
                    </label>
                    <label>
                        { __( 'Postal code', 'dono' ) }
                        <input className="dono-input"
                            type="text"
                            value={ form.address.postal }
                            onChange={ setAddr( 'postal' ) }
                            maxLength={ 20 }
                        />
                    </label>
                    { error && <div className="dp-edit-form__error">{ error }</div> }
                    <div className="dp-edit-form__actions">
                        <button type="button" className="btn" onClick={ onCancel } disabled={ saving }>
                            { __( 'Cancel', 'dono' ) }
                        </button>
                        <button type="submit" className="btn btn--primary" disabled={ saving || phoneInvalid }>
                            { saving ? __( 'Saving…', 'dono' ) : __( 'Save', 'dono' ) }
                        </button>
                    </div>
                </form>
            </div>
            <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
        </div>
    );
}

export default function DonorProfile( { id, onBack } ) {
    const [ data, setData ]       = useState( null );
    const [ loading, setLoading ] = useState( true );
    const [ error, setError ]     = useState( null );
    const [ tab, setTab ]         = useState( 'activity' );
    const [ editing, setEditing ] = useState( false );

    const load = () => {
        setLoading( true );
        return apiFetch( { path: `/dono/v1/admin/donors/${ id }/profile` } )
            .then( ( d ) => { setData( d ); setError( null ); } )
            .catch( ( e ) => setError( e?.message || 'Error' ) )
            .finally( () => setLoading( false ) );
    };

    useEffect( () => {
        let aborted = false;
        setLoading( true );
        setData( null );
        apiFetch( { path: `/dono/v1/admin/donors/${ id }/profile` } )
            .then( ( d ) => { if ( ! aborted ) { setData( d ); setError( null ); } } )
            .catch( ( e ) => { if ( ! aborted ) setError( e?.message || 'Error' ); } )
            .finally( () => { if ( ! aborted ) setLoading( false ); } );
        return () => { aborted = true; };
    }, [ id ] );

    if ( loading && ! data ) return <p className="dp-loading">{ __( 'Loading donor…', 'dono' ) }</p>;
    if ( error )              return <p className="dp-error">{ error }</p>;
    if ( ! data )             return null;

    const {
        donor, lifetime, donations, recurring, receipts, notes, consents,
        events, campaigns, banners, magic_link_url: magicLinkUrl,
    } = data;

    const tabCounts = {
        activity:  null,
        donations: donor.donations_count || lifetime.count || null,
        recurring: recurring.plans.length || null,
        receipts:  receipts.length || null,
        notes:     notes.length || null,
        consent:   null,
    };

    const hasPastDue = recurring.plans.some( ( p ) => p.status === 'past_due' );
    const tabDots = { recurring: hasPastDue ? 'is-amber' : null };

    return (
        <div className="dp-shell">
            <Header
                donor={ donor }
                lifetime={ lifetime }
                banners={ banners }
                recurring={ recurring }
                magicLinkUrl={ magicLinkUrl }
                onBack={ onBack }
                onEdit={ () => setEditing( true ) }
                onTabSwitch={ ( t ) => setTab( t ) }
            />

            { editing && (
                <EditPanel
                    donor={ donor }
                    onCancel={ () => setEditing( false ) }
                    onSaved={ ( updated ) => { setData( updated ); setEditing( false ); } }
                />
            ) }

            <LifetimeMetrics lifetime={ lifetime } />

            <Tabs active={ tab } onChange={ setTab } counts={ tabCounts } dots={ tabDots } />

            <div className="dp-layout">
                <aside className="dp-sidebar">
                    <IdentityCard donor={ donor } magicLinkUrl={ magicLinkUrl } />
                </aside>
                <main className="dp-main">
                    { tab === 'activity' && (
                        <ActivityTab
                            donations={ donations }
                            events={ events }
                            campaigns={ campaigns }
                            recurring={ recurring }
                            lifetime={ lifetime }
                            onAllDonations={ () => setTab( 'donations' ) }
                        />
                    ) }
                    { tab === 'donations' && <DonationsTab donorId={ donor.id } /> }
                    { tab === 'recurring' && <RecurringTab recurring={ recurring } /> }
                    { tab === 'receipts'  && <ReceiptsTab receipts={ receipts } /> }
                    { tab === 'notes'     && <NotesTab donorId={ donor.id } notes={ notes } onChanged={ load } /> }
                    { tab === 'consent'   && <ConsentTab consents={ consents } donor={ donor } onChanged={ load } /> }
                </main>
            </div>
        </div>
    );
}
