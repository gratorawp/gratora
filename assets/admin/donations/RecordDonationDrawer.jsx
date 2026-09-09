import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __, sprintf } from '@wordpress/i18n';

import Dialog from '../_shared/components/Dialog';
import Notice from '../_shared/components/Notice';
import Field from '../_shared/components/Field';
import AmountInput from '../_shared/components/AmountInput';
import DateField from '../_shared/components/DateField';
import SearchableSelect from '../_shared/components/SearchableSelect';
import { Switch } from '../_shared/components/Switch';
import Btn from '../_shared/components/Btn';

// The offline gateway's own list. Anything else is rejected server-side.
const METHODS = [
    { value: 'cheque',        label: __( 'Check', 'gratora' ) },
    { value: 'cash',          label: __( 'Cash', 'gratora' ) },
    { value: 'bank_transfer', label: __( 'Bank transfer', 'gratora' ) },
    { value: 'other',         label: __( 'Other', 'gratora' ) },
];

function today() {
    const d = new Date();
    const pad = ( n ) => String( n ).padStart( 2, '0' );

    return `${ d.getFullYear() }-${ pad( d.getMonth() + 1 ) }-${ pad( d.getDate() ) }`;
}

export default function RecordDonationDrawer( { onClose, onRecorded } ) {
    const currency = window.gratora?.default_currency || 'USD';

    const [ email, setEmail ]         = useState( '' );
    const [ firstName, setFirstName ] = useState( '' );
    const [ lastName, setLastName ]   = useState( '' );
    const [ amount, setAmount ]       = useState( '' );
    const [ method, setMethod ]       = useState( 'cheque' );
    const [ receivedAt, setReceived ] = useState( today() );
    const [ campaignId, setCampaign ] = useState( '' );
    const [ note, setNote ]           = useState( '' );
    const [ sendReceipt, setReceipt ] = useState( false );

    const [ fundId, setFund ]                   = useState( '' );
    const [ funds, setFunds ]                   = useState( [] );
    const [ campaigns, setCampaigns ]           = useState( [] );
    const [ campaignsFailed, setCampaignsFailed ] = useState( false );
    // Who inside the campaign this can be credited to. Empty unless an add-on
    // has somebody, which is most sites, and the field is not rendered then.
    const [ attributedTo, setAttributedTo ]     = useState( '' );
    const [ attributions, setAttributions ]     = useState( [] );
    const [ saving, setSaving ]       = useState( false );
    const [ error, setError ]         = useState( '' );
    // Set when the server found a donation this would duplicate. Holds its
    // reference so the admin can go and look before deciding.
    const [ duplicate, setDuplicate ] = useState( '' );

    useEffect( () => {
        let aborted = false;
        // Not /admin/campaigns: that needs gratora_manage_campaigns, which a role
        // created just to enter checks will not have, and the picker rendered
        // blank so every donation they recorded went uncategorised.
        apiFetch( { path: '/gratora/v1/admin/donations/fund-options' } )
            .then( ( res ) => setFunds( ( Array.isArray( res ) ? res : [] ).map( ( f ) => {
                /* translators: %s: fund name. */
                const isDefault = __( '%s (default)', 'gratora' );
                const name = f.depth ? `- ${ f.name }` : f.name;
                return {
                    value: String( f.id ),
                    label: f.is_default ? sprintf( isDefault, name ) : name,
                };
            } ) ) )
            // Silent: leaving this empty just means the org default applies,
            // which is what happens when nobody picks a fund anyway.
            .catch( () => setFunds( [] ) );

        apiFetch( { path: '/gratora/v1/admin/donations/campaign-options' } )
            .then( ( res ) => {
                if ( aborted ) return;
                setCampaigns( ( Array.isArray( res ) ? res : [] ).map( ( c ) => ( {
                    value: String( c.id ),
                    label: c.archived
                        ? sprintf(
                            /* translators: %s: campaign title. */
                            __( '%s (archived)', 'gratora' ),
                            c.title
                        )
                        : c.title,
                } ) ) );
            } )
            .catch( () => {
                if ( aborted ) return;
                // Say so. Silence here reads as "this org has no campaigns".
                setCampaignsFailed( true );
            } );
        return () => { aborted = true; };
    }, [] );

    // Asked again whenever the campaign changes: who can be credited belongs to
    // that campaign, and a name carried over from the last one would be wrong.
    useEffect( () => {
        if ( campaignId === '' ) {
            setAttributions( [] );
            setAttributedTo( '' );
            return undefined;
        }

        let aborted = false;
        apiFetch( { path: addQueryArgs( '/gratora/v1/admin/donations/attribution-options', { campaign_id: Number( campaignId ) } ) } )
            .then( ( res ) => {
                if ( aborted ) return;
                setAttributions( ( Array.isArray( res ) ? res : [] ).map( ( o ) => ( {
                    value: String( o.id ),
                    label: o.group ? `${ o.group }: ${ o.label }` : o.label,
                } ) ) );
            } )
            // An empty fund list credits the campaign alone.
            .catch( () => {
                if ( ! aborted ) setAttributions( [] );
            } );

        return () => { aborted = true; };
    }, [ campaignId ] );

    const cents = amount === '' ? 0 : Math.round( Number( amount ) * 100 );
    const ready = email.trim() !== '' && cents > 0 && receivedAt !== '';

    // Any edit after a duplicate warning describes a different donation, so the
    // warning stops applying and the button goes back to being a plain one.
    const edited = ( setter ) => ( next ) => {
        setDuplicate( '' );
        setError( '' );
        setter( next );
    };

    const submit = async ( anyway = false ) => {
        setSaving( true );
        setError( '' );
        try {
            const created = await apiFetch( {
                path: '/gratora/v1/admin/donations',
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
                    attributed_to: attributedTo,
                    fund_id: fundId === '' ? null : Number( fundId ),
                    note_to_org: note.trim(),
                    send_receipt: sendReceipt,
                    confirm_duplicate: anyway,
                },
            } );
            onRecorded( created );
        } catch ( e ) {
            if ( e?.code === 'gratora_duplicate_donation' ) {
                setDuplicate( e?.data?.reference || '?' );
            } else {
                setError( e?.message || __( 'Could not record this donation.', 'gratora' ) );
            }
            setSaving( false );
        }
    };

    const foot = (
        <div className="gratora-rd__foot">
            <Btn
                variant="primary"
                onClick={ () => submit( duplicate !== '' ) }
                disabled={ ! ready || saving }
                isBusy={ saving }
            >
                { saving
                    ? __( 'Recording…', 'gratora' )
                    : duplicate !== ''
                        ? __( 'Record it anyway', 'gratora' )
                        : __( 'Record donation', 'gratora' ) }
            </Btn>
            <Btn variant="ghost" onClick={ onClose } disabled={ saving }>
                { __( 'Cancel', 'gratora' ) }
            </Btn>
        </div>
    );

    return (
        <Dialog
            title={ __( 'Record a donation', 'gratora' ) }
            onClose={ saving ? undefined : onClose }
            foot={ foot }
        >
            <p className="gratora-dialog__help">
                { __( 'Money that arrived off the site: a check, cash at an event, a bank transfer.', 'gratora' ) }
            </p>
            <div className="gratora-rd">
                { error !== '' && (
                    <Notice status="error" isDismissible={ false }>{ error }</Notice>
                ) }

                { duplicate !== '' && (
                    <Notice status="warning" isDismissible={ false }>
                        { sprintf(
                            /* translators: %s: the reference of the donation already on the books. */
                            __( '%s is already down for this donor, this amount and this date. If they really gave twice, record it anyway. Otherwise change something above.', 'gratora' ),
                            duplicate
                        ) }
                    </Notice>
                ) }

                <Field label={ __( 'Donor email', 'gratora' ) } help={ __( 'Matches an existing donor, or creates one.', 'gratora' ) }>
                    <input
                        className="gratora-input"
                        type="email"
                        value={ email }
                        autoFocus
                        onChange={ ( e ) => edited( setEmail )( e.target.value ) }
                    />
                </Field>

                <div className="gratora-rd__row">
                    <Field label={ __( 'First name', 'gratora' ) }>
                        <input className="gratora-input" type="text" value={ firstName } onChange={ ( e ) => setFirstName( e.target.value ) } />
                    </Field>
                    <Field label={ __( 'Last name', 'gratora' ) }>
                        <input className="gratora-input" type="text" value={ lastName } onChange={ ( e ) => setLastName( e.target.value ) } />
                    </Field>
                </div>

                <Field label={ __( 'Amount', 'gratora' ) }>
                    <AmountInput value={ amount } onChange={ edited( setAmount ) } currency={ currency } placeholder="0" />
                </Field>

                <Field
                    label={ __( 'Date received', 'gratora' ) }
                    help={ __( 'When the money arrived, which is not always today. A check banked last month belongs to last month, and the totals for that month depend on this.', 'gratora' ) }
                >
                    <DateField
                        value={ receivedAt }
                        onChange={ ( next ) => edited( setReceived )( next || '' ) }
                        ariaLabel={ __( 'Date received', 'gratora' ) }
                    />
                </Field>

                <Field label={ __( 'How it arrived', 'gratora' ) }>
                    <select className="gratora-select" value={ method } onChange={ ( e ) => setMethod( e.target.value ) }>
                        { METHODS.map( ( m ) => (
                            <option key={ m.value } value={ m.value }>{ m.label }</option>
                        ) ) }
                    </select>
                </Field>

                <Field
                    label={ __( 'Campaign', 'gratora' ) }
                    help={ campaignsFailed
                        ? __( 'Campaigns could not be loaded, so this will be recorded without one. Someone with campaign access can set it afterwards.', 'gratora' )
                        : __( 'Optional. Leave empty for a general donation.', 'gratora' ) }
                >
                    <SearchableSelect
                        value={ campaignId }
                        onChange={ ( next ) => { setCampaign( next ); setAttributedTo( '' ); } }
                        options={ campaigns }
                        placeholder={ campaignsFailed
                            ? __( 'Unavailable', 'gratora' )
                            : __( 'No campaign', 'gratora' ) }
                    />
                </Field>

                { attributions.length > 0 && (
                    <Field
                        label={ __( 'Credit to', 'gratora' ) }
                        help={ __( 'Optional. A check handed to somebody raising for this campaign counts towards their total as well as the campaign\'s.', 'gratora' ) }
                    >
                        <SearchableSelect
                            value={ attributedTo }
                            onChange={ setAttributedTo }
                            options={ attributions }
                            placeholder={ __( 'The campaign itself', 'gratora' ) }
                        />
                    </Field>
                ) }

                { funds.length > 0 && (
                    <Field
                        label={ __( 'Fund', 'gratora' ) }
                        help={ __( 'Optional. Leave empty to use the default fund.', 'gratora' ) }
                    >
                        <SearchableSelect
                            value={ fundId }
                            onChange={ setFund }
                            options={ funds }
                            placeholder={ __( 'Default fund', 'gratora' ) }
                        />
                    </Field>
                ) }

                <Field label={ __( 'Note', 'gratora' ) } help={ __( 'Only your team sees this.', 'gratora' ) }>
                    <textarea className="gratora-input" rows={ 2 } value={ note } onChange={ ( e ) => setNote( e.target.value ) } />
                </Field>

                { /* eslint-disable-next-line jsx-a11y/label-has-associated-control -- Switch is self-labeled via its label prop */ }
                <label className="gratora-rd__receipt">
                    <Switch checked={ sendReceipt } onChange={ setReceipt } label={ __( 'Email the donor a receipt', 'gratora' ) } />
                    <span className="gratora-rd__receipt-txt">
                        <strong>{ sendReceipt
                            ? __( 'Email a receipt', 'gratora' )
                            : __( 'Do not email the donor', 'gratora' ) }</strong>
                        <span>{ sendReceipt
                            ? __( 'The donor gets a receipt for this donation.', 'gratora' )
                            : __( 'Nothing is sent, not even a receipt.', 'gratora' ) }</span>
                    </span>
                </label>
            </div>
        </Dialog>
    );
}
