import { useEffect, useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import Header from './profile/Header';
import ConfirmDialog from '../_shared/components/ConfirmDialog';
import Dialog from '../_shared/components/Dialog';
import Btn from '../_shared/components/Btn';
import Notice from '../_shared/components/Notice';
import { countryName, localizedCountries } from '../../_shared/countries';
import LifetimeMetrics from './profile/LifetimeMetrics';
import Tabs from './profile/Tabs';
import IdentityCard from './profile/IdentityCard';
import { ExtensionSection, useExtensionPanels } from '../_shared/extensionTabs';
import ActivityTab from './profile/tabs/ActivityTab';
import ActivityLogTab from './profile/tabs/ActivityLogTab';
import DonationsTab from './profile/tabs/DonationsTab';
import RecurringTab from './profile/tabs/RecurringTab';
import ReceiptsTab from './profile/tabs/ReceiptsTab';
import NotesTab from './profile/tabs/NotesTab';
import ConsentTab from './profile/tabs/ConsentTab';

// Accept freeform international phone numbers; integrations enforce stricter formats.
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
    // Match the profile card’s localized country names.
    const countries = useMemo( () => localizedCountries(), [] );
    const [ countryQuery, setCountryQuery ] = useState( () => countryName( donor.country ) );
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
                path:   `/gratora/v1/admin/donors/${ donor.id }`,
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
        // The footer sits outside the form.
        if ( e ) e.preventDefault();
        if ( phoneInvalid ) {
            setError( __( 'Phone number looks malformed. Use digits, +, spaces, parentheses, or dashes.', 'gratora' ) );
            return;
        }
        if ( emailInvalid ) {
            setError( __( 'Email address looks malformed.', 'gratora' ) );
            return;
        }
        if ( emailChanged ) {
            setConfirm( {
                title:        __( 'Change donor email', 'gratora' ),
                message:      __( 'Change this donor\'s email? Future donations from the new address will link to this record.', 'gratora' ),
                confirmLabel: __( 'Change email', 'gratora' ),
                onConfirm:    doSave,
            } );
            return;
        }
        doSave();
    };

    const q = countryQuery.trim().toLowerCase();
    const countryMatches = q === ''
        ? countries
        : countries.filter( ( c ) =>
            c.label.toLowerCase().includes( q )
            || c.name.toLowerCase().includes( q )
            || c.code.toLowerCase().startsWith( q ) );

    const pickCountry = ( c ) => {
        setForm( ( s ) => ( { ...s, country: c ? c.code : '' } ) );
        setCountryQuery( c ? c.label : '' );
        setCountryOpen( false );
    };

    return (
        <>
            <Dialog
                title={ __( 'Edit donor details', 'gratora' ) }
                onClose={ () => ( saving ? null : onCancel() ) }
                size="wide"
                foot={
                    <>
                        <Btn variant="secondary" onClick={ onCancel } disabled={ saving }>
                            { __( 'Cancel', 'gratora' ) }
                        </Btn>
                        <Btn
                            variant="primary"
                            onClick={ () => submit() }
                            isBusy={ saving }
                            disabled={ saving || phoneInvalid }
                        >
                            { saving ? __( 'Saving…', 'gratora' ) : __( 'Save', 'gratora' ) }
                        </Btn>
                    </>
                }
            >
                <form className="dp-edit-form" onSubmit={ submit }>
                    <label style={ { gridColumn: '1 / -1' } }>
                        { __( 'Email', 'gratora' ) }
                        <input className="gratora-input"
                            type="email"
                            value={ form.email }
                            onChange={ set( 'email' ) }
                            required
                            maxLength={ 254 }
                            aria-invalid={ emailInvalid }
                        />
                        { emailChanged && (
                            <span className="dp-field__hint">
                                { __( 'Saving rehashes the donor identity. Future donations from this address will link to this record.', 'gratora' ) }
                            </span>
                        ) }
                    </label>
                    <label>
                        { __( 'First name', 'gratora' ) }
                        <input className="gratora-input" type="text" value={ form.first_name } onChange={ set( 'first_name' ) } maxLength={ 100 } />
                    </label>
                    <label>
                        { __( 'Last name', 'gratora' ) }
                        <input className="gratora-input" type="text" value={ form.last_name } onChange={ set( 'last_name' ) } maxLength={ 100 } />
                    </label>
                    <label className="dp-edit-form__country">
                        { __( 'Country', 'gratora' ) }
                        <div className="dp-edit-form__country-wrap">
                            <input className="gratora-input"
                                type="text"
                                value={ countryQuery }
                                placeholder={ __( 'Search country…', 'gratora' ) }
                                onFocus={ () => setCountryOpen( true ) }
                                onBlur={ () => setTimeout( () => setCountryOpen( false ), 150 ) }
                                onChange={ ( e ) => { setCountryQuery( e.target.value ); setCountryOpen( true ); } }
                            />
                            { countryOpen && countryMatches.length > 0 && (
                                <ul className="dp-edit-form__country-list">
                                    { countryMatches.slice( 0, 50 ).map( ( c ) => (
                                        <li key={ c.code }>
                                            <button type="button" onMouseDown={ ( e ) => { e.preventDefault(); pickCountry( c ); } }>
                                                <span>{ c.label }</span>
                                                <span className="dp-edit-form__country-code">{ c.code }</span>
                                            </button>
                                        </li>
                                    ) ) }
                                </ul>
                            ) }
                        </div>
                    </label>
                    <label>
                        { __( 'Type', 'gratora' ) }
                        <select className="gratora-select" value={ form.donor_type } onChange={ set( 'donor_type' ) }>
                            <option value="individual">{ __( 'Individual', 'gratora' ) }</option>
                            <option value="organization">{ __( 'Organization', 'gratora' ) }</option>
                            <option value="household">{ __( 'Household', 'gratora' ) }</option>
                        </select>
                    </label>
                    <label style={ { gridColumn: '1 / -1' } }>
                        { __( 'Company', 'gratora' ) }
                        <input className="gratora-input" type="text" value={ form.company } onChange={ set( 'company' ) } maxLength={ 150 } />
                    </label>
                    <label style={ { gridColumn: '1 / -1' } }>
                        { __( 'Phone', 'gratora' ) }
                        <input className="gratora-input"
                            type="tel"
                            value={ form.phone }
                            onChange={ set( 'phone' ) }
                            placeholder="+49 30 1234 5678"
                            maxLength={ 32 }
                            aria-invalid={ phoneInvalid }
                        />
                        { phoneInvalid && (
                            <span className="dp-field__hint dp-field__hint--err">
                                { __( 'Use digits, +, spaces, parentheses, or dashes.', 'gratora' ) }
                            </span>
                        ) }
                    </label>
                    <label style={ { gridColumn: '1 / -1' } }>
                        { __( 'Address line 1', 'gratora' ) }
                        <input className="gratora-input"
                            type="text"
                            value={ form.address.line1 }
                            onChange={ setAddr( 'line1' ) }
                            placeholder={ __( 'Street and number', 'gratora' ) }
                            maxLength={ 200 }
                        />
                    </label>
                    <label style={ { gridColumn: '1 / -1' } }>
                        { __( 'Address line 2', 'gratora' ) }
                        <input className="gratora-input"
                            type="text"
                            value={ form.address.line2 }
                            onChange={ setAddr( 'line2' ) }
                            placeholder={ __( 'Apartment, suite, etc. (optional)', 'gratora' ) }
                            maxLength={ 200 }
                        />
                    </label>
                    <label>
                        { __( 'City', 'gratora' ) }
                        <input className="gratora-input"
                            type="text"
                            value={ form.address.city }
                            onChange={ setAddr( 'city' ) }
                            maxLength={ 100 }
                        />
                    </label>
                    <label>
                        { __( 'Region', 'gratora' ) }
                        <input className="gratora-input"
                            type="text"
                            value={ form.address.region }
                            onChange={ setAddr( 'region' ) }
                            maxLength={ 100 }
                        />
                    </label>
                    <label>
                        { __( 'Postal code', 'gratora' ) }
                        <input className="gratora-input"
                            type="text"
                            value={ form.address.postal }
                            onChange={ setAddr( 'postal' ) }
                            maxLength={ 20 }
                        />
                    </label>
                    { error && <div className="dp-edit-form__error">{ error }</div> }
                    { /* Keep a hidden submit button for Enter-key submission. */ }
                    <button type="submit" style={ { display: 'none' } } aria-hidden="true" tabIndex={ -1 } />
                </form>
            </Dialog>

            { /* Mount outside the dialog so confirmation overlays its scrolling body. */ }
            <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
        </>
    );
}

export default function DonorProfile( { id, onBack } ) {
    const [ data, setData ]       = useState( null );
    const [ loading, setLoading ] = useState( true );
    const [ error, setError ]     = useState( null );
    const [ tab, setTab ]         = useState( 'overview' );
    const extensionPanels         = useExtensionPanels( 'donor' );
    const [ editing, setEditing ] = useState( false );

    const load = () => {
        setLoading( true );
        setError( null );
        return apiFetch( { path: `/gratora/v1/admin/donors/${ id }/profile` } )
            .then( ( d ) => { setData( d ); setError( null ); } )
            .catch( ( e ) => setError( e?.message || __( 'Could not load this donor.', 'gratora' ) ) )
            .finally( () => setLoading( false ) );
    };

    useEffect( () => {
        let aborted = false;
        setLoading( true );
        setData( null );
        apiFetch( { path: `/gratora/v1/admin/donors/${ id }/profile` } )
            .then( ( d ) => { if ( ! aborted ) { setData( d ); setError( null ); } } )
            .catch( ( e ) => { if ( ! aborted ) setError( e?.message || __( 'Could not load this donor.', 'gratora' ) ); } )
            .finally( () => { if ( ! aborted ) setLoading( false ); } );
        return () => { aborted = true; };
    }, [ id ] );

    if ( loading && ! data ) return <p className="dp-loading">{ __( 'Loading donor…', 'gratora' ) }</p>;

    // Keep loaded data and retry controls after refresh failures.
    if ( error && ! data ) {
        return (
            <div className="dp-shell">
                <Notice status="error" isDismissible={ false }>{ error }</Notice>
                <p>
                    <Btn variant="secondary" onClick={ load }>{ __( 'Try again', 'gratora' ) }</Btn>
                    { ' ' }
                    <Btn onClick={ onBack }>{ __( 'Back to donors', 'gratora' ) }</Btn>
                </p>
            </div>
        );
    }

    if ( ! data )             return null;

    const {
        donor, lifetime, donations, recurring, receipts, notes, consents,
        events, campaigns, banners,
        events_total: eventsTotal,
        donations_total: donationsTotal,
        receipts_total: receiptsTotal,
        notes_total: notesTotal,
    } = data;

    const tabCounts = {
        activity:  null,
        // Match the tab’s count, including test donations.
        donations: donationsTotal || null,
        recurring: recurring.plans.length || null,
        receipts:  receiptsTotal || null,
        notes:     notesTotal || notes.length || null,
        consent:   null,
    };

    const hasPastDue = recurring.plans.some( ( p ) => p.status === 'past_due' );
    const tabDots = { recurring: hasPastDue ? 'is-amber' : null };

    return (
        <div className="dp-shell">
            { error && (
                <Notice status="error" onRemove={ () => setError( null ) }>{ error }</Notice>
            ) }

            <Header
                donor={ donor }
                lifetime={ lifetime }
                banners={ banners }
                recurring={ recurring }
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
                    <IdentityCard donor={ donor } />
                    { extensionPanels.map( ( panel ) => (
                        <ExtensionSection
                            key={ panel.id }
                            panel={ panel }
                            context={ { donorId: donor?.id } }
                            token={ donor?.id }
                        />
                    ) ) }
                </aside>
                <main className="dp-main">
                    { tab === 'overview' && (
                        <ActivityTab
                            donations={ donations }
                            donationsTotal={ donationsTotal }
                            events={ events }
                            eventsTotal={ eventsTotal }
                            campaigns={ campaigns }
                            recurring={ recurring }
                            onAllDonations={ () => setTab( 'donations' ) }
                            onSeeAllActivity={ () => setTab( 'activity' ) }
                        />
                    ) }
                    { tab === 'activity'  && <ActivityLogTab donorId={ donor.id } /> }
                    { tab === 'donations' && <DonationsTab donorId={ donor.id } redacted={ !! donor.redacted_at } /> }
                    { tab === 'recurring' && <RecurringTab recurring={ recurring } onChange={ load } /> }
                    { tab === 'receipts'  && <ReceiptsTab receipts={ receipts } total={ receiptsTotal } donations={ donations } donor={ donor } redacted={ !! donor.redacted_at } /> }
                    { tab === 'notes'     && <NotesTab donorId={ donor.id } notes={ notes } total={ notesTotal } onChanged={ load } /> }
                    { tab === 'consent'   && <ConsentTab consents={ consents } donor={ donor } onChanged={ load } /> }
                </main>
            </div>
        </div>
    );
}
