import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import Drawer from '../_shared/components/Drawer';
import Notice from '../_shared/components/Notice';
import Field from '../_shared/components/Field';
import AmountInput from '../_shared/components/AmountInput';
import DateField from '../_shared/components/DateField';
import SearchableSelect from '../_shared/components/SearchableSelect';
import { Switch } from '../_shared/components/Switch';
import Btn from '../_shared/components/Btn';

// The offline gateway's own list. Anything else is rejected server-side.
const METHODS = [
    { value: 'cheque',        label: __( 'Cheque', 'dono' ) },
    { value: 'cash',          label: __( 'Cash', 'dono' ) },
    { value: 'bank_transfer', label: __( 'Bank transfer', 'dono' ) },
    { value: 'other',         label: __( 'Other', 'dono' ) },
];

function today() {
    const d = new Date();
    const pad = ( n ) => String( n ).padStart( 2, '0' );

    return `${ d.getFullYear() }-${ pad( d.getMonth() + 1 ) }-${ pad( d.getDate() ) }`;
}

export default function RecordDonationDrawer( { onClose, onRecorded } ) {
    const currency = window.dono?.default_currency || 'USD';

    const [ email, setEmail ]         = useState( '' );
    const [ firstName, setFirstName ] = useState( '' );
    const [ lastName, setLastName ]   = useState( '' );
    const [ amount, setAmount ]       = useState( '' );
    const [ method, setMethod ]       = useState( 'cheque' );
    const [ receivedAt, setReceived ] = useState( today() );
    const [ campaignId, setCampaign ] = useState( '' );
    const [ note, setNote ]           = useState( '' );
    const [ sendReceipt, setReceipt ] = useState( false );

    const [ campaigns, setCampaigns ] = useState( [] );
    const [ saving, setSaving ]       = useState( false );
    const [ error, setError ]         = useState( '' );

    useEffect( () => {
        let aborted = false;
        apiFetch( { path: '/dono/v1/admin/campaigns?per_page=100' } )
            .then( ( res ) => {
                if ( aborted ) return;
                const rows = res?.items || res?.data || res || [];
                setCampaigns( ( Array.isArray( rows ) ? rows : [] ).map( ( c ) => ( {
                    value: String( c.id ),
                    label: c.title,
                } ) ) );
            } )
            .catch( () => {} );
        return () => { aborted = true; };
    }, [] );

    const cents = amount === '' ? 0 : Math.round( Number( amount ) * 100 );
    const ready = email.trim() !== '' && cents > 0 && receivedAt !== '';

    const submit = async () => {
        setSaving( true );
        setError( '' );
        try {
            const created = await apiFetch( {
                path: '/dono/v1/admin/donations',
                method: 'POST',
                data: {
                    email: email.trim(),
                    first_name: firstName.trim(),
                    last_name: lastName.trim(),
                    amount_cents: cents,
                    currency,
                    payment_method: method,
                    received_at: receivedAt,
                    campaign_id: campaignId === '' ? null : Number( campaignId ),
                    note_to_org: note.trim(),
                    send_receipt: sendReceipt,
                },
            } );
            onRecorded( created );
        } catch ( e ) {
            setError( e?.message || __( 'Could not record this donation.', 'dono' ) );
            setSaving( false );
        }
    };

    const foot = (
        <div className="dono-rd__foot">
            <Btn variant="primary" onClick={ submit } disabled={ ! ready || saving } isBusy={ saving }>
                { saving ? __( 'Recording…', 'dono' ) : __( 'Record donation', 'dono' ) }
            </Btn>
            <Btn variant="ghost" onClick={ onClose } disabled={ saving }>
                { __( 'Cancel', 'dono' ) }
            </Btn>
        </div>
    );

    return (
        <Drawer
            title={ __( 'Record a donation', 'dono' ) }
            sub={ __( 'Money that arrived off the site: a cheque, cash at an event, a bank transfer.', 'dono' ) }
            onClose={ saving ? undefined : onClose }
            foot={ foot }
        >
            <div className="dono-rd">
                { error !== '' && (
                    <Notice status="error" isDismissible={ false }>{ error }</Notice>
                ) }

                <Field label={ __( 'Donor email', 'dono' ) } help={ __( 'Matches an existing donor, or creates one.', 'dono' ) }>
                    <input
                        className="dono-input"
                        type="email"
                        value={ email }
                        autoFocus
                        onChange={ ( e ) => setEmail( e.target.value ) }
                    />
                </Field>

                <div className="dono-rd__row">
                    <Field label={ __( 'First name', 'dono' ) }>
                        <input className="dono-input" type="text" value={ firstName } onChange={ ( e ) => setFirstName( e.target.value ) } />
                    </Field>
                    <Field label={ __( 'Last name', 'dono' ) }>
                        <input className="dono-input" type="text" value={ lastName } onChange={ ( e ) => setLastName( e.target.value ) } />
                    </Field>
                </div>

                <Field label={ __( 'Amount', 'dono' ) }>
                    <AmountInput value={ amount } onChange={ setAmount } currency={ currency } placeholder="0" />
                </Field>

                <Field
                    label={ __( 'Date received', 'dono' ) }
                    help={ __( 'When the money arrived, which is not always today. A cheque banked last month belongs to last month, and the totals for that month depend on this.', 'dono' ) }
                >
                    <DateField
                        value={ receivedAt }
                        onChange={ ( next ) => setReceived( next || '' ) }
                        ariaLabel={ __( 'Date received', 'dono' ) }
                    />
                </Field>

                <Field label={ __( 'How it arrived', 'dono' ) }>
                    <select className="dono-select" value={ method } onChange={ ( e ) => setMethod( e.target.value ) }>
                        { METHODS.map( ( m ) => (
                            <option key={ m.value } value={ m.value }>{ m.label }</option>
                        ) ) }
                    </select>
                </Field>

                <Field label={ __( 'Campaign', 'dono' ) } help={ __( 'Optional. Leave empty for a general donation.', 'dono' ) }>
                    <SearchableSelect
                        value={ campaignId }
                        onChange={ setCampaign }
                        options={ campaigns }
                        placeholder={ __( 'No campaign', 'dono' ) }
                    />
                </Field>

                <Field label={ __( 'Note', 'dono' ) } help={ __( 'Only your team sees this.', 'dono' ) }>
                    <textarea className="dono-input" rows={ 2 } value={ note } onChange={ ( e ) => setNote( e.target.value ) } />
                </Field>

                { /* eslint-disable-next-line jsx-a11y/label-has-associated-control -- Switch is self-labeled via its label prop */ }
                <label className="dono-rd__receipt">
                    <Switch checked={ sendReceipt } onChange={ setReceipt } label={ __( 'Email the donor a receipt', 'dono' ) } />
                    <span className="dono-rd__receipt-txt">
                        <strong>{ sendReceipt ? __( 'Send a receipt', 'dono' ) : __( 'Send nothing', 'dono' ) }</strong>
                        <span>{ sendReceipt
                            ? __( 'They get a receipt for this donation.', 'dono' )
                            : __( 'No email at all, not even a thank-you. They never gave this site an address, so nothing is sent unless you ask.', 'dono' ) }</span>
                    </span>
                </label>
            </div>
        </Drawer>
    );
}
