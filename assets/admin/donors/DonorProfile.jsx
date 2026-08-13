import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import Header from './profile/Header';
import ConfirmDialog from '../_shared/components/ConfirmDialog';
import Dialog from '../_shared/components/Dialog';
import Btn from '../_shared/components/Btn';
import { COUNTRIES } from '../../_shared/countries';
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
        const c = COUNTRIES.find( ( cur ) => cur.code === ( donor.country || '' ).toUpperCase() );
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
        // The footer lives outside the form element, so it calls this directly.
        if ( e ) e.preventDefault();
        if ( phoneInvalid ) {
            setError( __( 'Phone number looks malformed. Use digits, +, spaces, parentheses, or dashes.', 'dono-fundraising-platform' ) );
            return;
        }
        if ( emailInvalid ) {
            setError( __( 'Email address looks malformed.', 'dono-fundraising-platform' ) );
            return;
        }
        if ( emailChanged ) {
            setConfirm( {
                title:        __( 'Change donor email', 'dono-fundraising-platform' ),
                message:      __( 'Change this donor\'s email? Future donations from the new address will link to this record.', 'dono-fundraising-platform' ),
                confirmLabel: __( 'Change email', 'dono-fundraising-platform' ),
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
        <>
            <Dialog
                title={ __( 'Edit donor details', 'dono-fundraising-platform' ) }
                onClose={ () => ( saving ? null : onCancel() ) }
                size="wide"
                foot={
                    <>
                        <Btn variant="secondary" onClick={ onCancel } disabled={ saving }>
                            { __( 'Cancel', 'dono-fundraising-platform' ) }
                        </Btn>
                        <Btn
                            variant="primary"
                            onClick={ () => submit() }
                            isBusy={ saving }
                            disabled={ saving || phoneInvalid }
                        >
                            { saving ? __( 'Saving…', 'dono-fundraising-platform' ) : __( 'Save', 'dono-fundraising-platform' ) }
                        </Btn>
                    </>
                }
            >
                <form className="dp-edit-form" onSubmit={ submit }>
                    <label style={ { gridColumn: '1 / -1' } }>
                        { __( 'Email', 'dono-fundraising-platform' ) }
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
                                { __( 'Saving rehashes the donor identity. Future donations from this address will link to this record.', 'dono-fundraising-platform' ) }
                            </span>
                        ) }
                    </label>
                    <label>
                        { __( 'First name', 'dono-fundraising-platform' ) }
                        <input className="dono-input" type="text" value={ form.first_name } onChange={ set( 'first_name' ) } maxLength={ 100 } />
                    </label>
                    <label>
                        { __( 'Last name', 'dono-fundraising-platform' ) }
                        <input className="dono-input" type="text" value={ form.last_name } onChange={ set( 'last_name' ) } maxLength={ 100 } />
                    </label>
                    <label className="dp-edit-form__country">
                        { __( 'Country', 'dono-fundraising-platform' ) }
                        <div className="dp-edit-form__country-wrap">
                            <input className="dono-input"
                                type="text"
                                value={ countryQuery }
                                placeholder={ __( 'Search country…', 'dono-fundraising-platform' ) }
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
                        { __( 'Type', 'dono-fundraising-platform' ) }
                        <select className="dono-select" value={ form.donor_type } onChange={ set( 'donor_type' ) }>
                            <option value="individual">{ __( 'Individual', 'dono-fundraising-platform' ) }</option>
                            <option value="organization">{ __( 'Organization', 'dono-fundraising-platform' ) }</option>
                            <option value="household">{ __( 'Household', 'dono-fundraising-platform' ) }</option>
                        </select>
                    </label>
                    <label style={ { gridColumn: '1 / -1' } }>
                        { __( 'Company', 'dono-fundraising-platform' ) }
                        <input className="dono-input" type="text" value={ form.company } onChange={ set( 'company' ) } maxLength={ 150 } />
                    </label>
                    <label style={ { gridColumn: '1 / -1' } }>
                        { __( 'Phone', 'dono-fundraising-platform' ) }
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
                                { __( 'Use digits, +, spaces, parentheses, or dashes.', 'dono-fundraising-platform' ) }
                            </span>
                        ) }
                    </label>
                    <label style={ { gridColumn: '1 / -1' } }>
                        { __( 'Address line 1', 'dono-fundraising-platform' ) }
                        <input className="dono-input"
                            type="text"
                            value={ form.address.line1 }
                            onChange={ setAddr( 'line1' ) }
                            placeholder={ __( 'Street and number', 'dono-fundraising-platform' ) }
                            maxLength={ 200 }
                        />
                    </label>
                    <label style={ { gridColumn: '1 / -1' } }>
                        { __( 'Address line 2', 'dono-fundraising-platform' ) }
                        <input className="dono-input"
                            type="text"
                            value={ form.address.line2 }
                            onChange={ setAddr( 'line2' ) }
                            placeholder={ __( 'Apartment, suite, etc. (optional)', 'dono-fundraising-platform' ) }
                            maxLength={ 200 }
                        />
                    </label>
                    <label>
                        { __( 'City', 'dono-fundraising-platform' ) }
                        <input className="dono-input"
                            type="text"
                            value={ form.address.city }
                            onChange={ setAddr( 'city' ) }
                            maxLength={ 100 }
                        />
                    </label>
                    <label>
                        { __( 'Region', 'dono-fundraising-platform' ) }
                        <input className="dono-input"
                            type="text"
                            value={ form.address.region }
                            onChange={ setAddr( 'region' ) }
                            maxLength={ 100 }
                        />
                    </label>
                    <label>
                        { __( 'Postal code', 'dono-fundraising-platform' ) }
                        <input className="dono-input"
                            type="text"
                            value={ form.address.postal }
                            onChange={ setAddr( 'postal' ) }
                            maxLength={ 20 }
                        />
                    </label>
                    { error && <div className="dp-edit-form__error">{ error }</div> }
                    { /* Submit stays in the form so Enter still saves, but it is
                         not shown: the dialog footer carries the real buttons. */ }
                    <button type="submit" style={ { display: 'none' } } aria-hidden="true" tabIndex={ -1 } />
                </form>
            </Dialog>

            { /* Outside the dialog, so the email-change confirmation stacks on
                 top of it rather than inside its scrolling body. */ }
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

    if ( loading && ! data ) return <p className="dp-loading">{ __( 'Loading donor…', 'dono-fundraising-platform' ) }</p>;
    if ( error )              return <p className="dp-error">{ error }</p>;
    if ( ! data )             return null;

    const {
        donor, lifetime, donations, recurring, receipts, notes, consents,
        events, campaigns, banners,
        events_total: eventsTotal,
        donations_total: donationsTotal,
    } = data;

    const tabCounts = {
        activity:  null,
        // Counts what the tab lists, which includes test donations.
        // donations_count is live-only, so it read zero for a donor who has
        // only rehearsed while the tab under it showed their rows.
        donations: donationsTotal || null,
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
                    { tab === 'receipts'  && <ReceiptsTab receipts={ receipts } donations={ donations } donor={ donor } redacted={ !! donor.redacted_at } /> }
                    { tab === 'notes'     && <NotesTab donorId={ donor.id } notes={ notes } onChanged={ load } /> }
                    { tab === 'consent'   && <ConsentTab consents={ consents } donor={ donor } onChanged={ load } /> }
                </main>
            </div>
        </div>
    );
}
