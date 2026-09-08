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
    { value: 'cheque',        label: __( 'Check', 'fundraising-toolkit' ) },
    { value: 'cash',          label: __( 'Cash', 'fundraising-toolkit' ) },
    { value: 'bank_transfer', label: __( 'Bank transfer', 'fundraising-toolkit' ) },
    { value: 'other',         label: __( 'Other', 'fundraising-toolkit' ) },
];

function today() {
    const d = new Date();
    const pad = ( n ) => String( n ).padStart( 2, '0' );

    return `${ d.getFullYear() }-${ pad( d.getMonth() + 1 ) }-${ pad( d.getDate() ) }`;
}

export default function RecordDonationDrawer( { onClose, onRecorded } ) {
    const currency = window.fundkit?.default_currency || 'USD';

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
        // Not /admin/campaigns: that needs fundkit_manage_campaigns, which a role
        // created just to enter checks will not have, and the picker rendered
        // blank so every donation they recorded went uncategorised.
        apiFetch( { path: '/fundkit/v1/admin/donations/fund-options' } )
            .then( ( res ) => setFunds( ( Array.isArray( res ) ? res : [] ).map( ( f ) => {
                /* translators: %s: fund name. */
                const isDefault = __( '%s (default)', 'fundraising-toolkit' );
                const name = f.depth ? `- ${ f.name }` : f.name;
                return {
                    value: String( f.id ),
                    label: f.is_default ? sprintf( isDefault, name ) : name,
                };
            } ) ) )
            // Silent: leaving this empty just means the org default applies,
            // which is what happens when nobody picks a fund anyway.
            .catch( () => setFunds( [] ) );

        apiFetch( { path: '/fundkit/v1/admin/donations/campaign-options' } )
            .then( ( res ) => {
                if ( aborted ) return;
                setCampaigns( ( Array.isArray( res ) ? res : [] ).map( ( c ) => ( {
                    value: String( c.id ),
                    label: c.archived
                        ? sprintf(
                            /* translators: %s: campaign title. */
                            __( '%s (archived)', 'fundraising-toolkit' ),
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
        apiFetch( { path: addQueryArgs( '/fundkit/v1/admin/donations/attribution-options', { campaign_id: Number( campaignId ) } ) } )
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
                path: '/fundkit/v1/admin/donations',
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
            if ( e?.code === 'fundkit_duplicate_donation' ) {
                setDuplicate( e?.data?.reference || '?' );
            } else {
                setError( e?.message || __( 'Could not record this donation.', 'fundraising-toolkit' ) );
            }
            setSaving( false );
        }
    };

    const foot = (
        <div className="fundkit-rd__foot">
            <Btn
                variant="primary"
                onClick={ () => submit( duplicate !== '' ) }
                disabled={ ! ready || saving }
                isBusy={ saving }
            >
                { saving
                    ? __( 'Recording…', 'fundraising-toolkit' )
                    : duplicate !== ''
                        ? __( 'Record it anyway', 'fundraising-toolkit' )
                        : __( 'Record donation', 'fundraising-toolkit' ) }
            </Btn>
            <Btn variant="ghost" onClick={ onClose } disabled={ saving }>
                { __( 'Cancel', 'fundraising-toolkit' ) }
            </Btn>
        </div>
    );

    return (
        <Dialog
            title={ __( 'Record a donation', 'fundraising-toolkit' ) }
            onClose={ saving ? undefined : onClose }
            foot={ foot }
        >
            <p className="fundkit-dialog__help">
                { __( 'Money that arrived off the site: a check, cash at an event, a bank transfer.', 'fundraising-toolkit' ) }
            </p>
            <div className="fundkit-rd">
                { error !== '' && (
                    <Notice status="error" isDismissible={ false }>{ error }</Notice>
                ) }

                { duplicate !== '' && (
                    <Notice status="warning" isDismissible={ false }>
                        { sprintf(
                            /* translators: %s: the reference of the donation already on the books. */
                            __( '%s is already down for this donor, this amount and this date. If they really gave twice, record it anyway. Otherwise change something above.', 'fundraising-toolkit' ),
                            duplicate
                        ) }
                    </Notice>
                ) }

                <Field label={ __( 'Donor email', 'fundraising-toolkit' ) } help={ __( 'Matches an existing donor, or creates one.', 'fundraising-toolkit' ) }>
                    <input
                        className="fundkit-input"
                        type="email"
                        value={ email }
                        autoFocus
                        onChange={ ( e ) => edited( setEmail )( e.target.value ) }
                    />
                </Field>

                <div className="fundkit-rd__row">
                    <Field label={ __( 'First name', 'fundraising-toolkit' ) }>
                        <input className="fundkit-input" type="text" value={ firstName } onChange={ ( e ) => setFirstName( e.target.value ) } />
                    </Field>
                    <Field label={ __( 'Last name', 'fundraising-toolkit' ) }>
                        <input className="fundkit-input" type="text" value={ lastName } onChange={ ( e ) => setLastName( e.target.value ) } />
                    </Field>
                </div>

                <Field label={ __( 'Amount', 'fundraising-toolkit' ) }>
                    <AmountInput value={ amount } onChange={ edited( setAmount ) } currency={ currency } placeholder="0" />
                </Field>

                <Field
                    label={ __( 'Date received', 'fundraising-toolkit' ) }
                    help={ __( 'When the money arrived, which is not always today. A check banked last month belongs to last month, and the totals for that month depend on this.', 'fundraising-toolkit' ) }
                >
                    <DateField
                        value={ receivedAt }
                        onChange={ ( next ) => edited( setReceived )( next || '' ) }
                        ariaLabel={ __( 'Date received', 'fundraising-toolkit' ) }
                    />
                </Field>

                <Field label={ __( 'How it arrived', 'fundraising-toolkit' ) }>
                    <select className="fundkit-select" value={ method } onChange={ ( e ) => setMethod( e.target.value ) }>
                        { METHODS.map( ( m ) => (
                            <option key={ m.value } value={ m.value }>{ m.label }</option>
                        ) ) }
                    </select>
                </Field>

                <Field
                    label={ __( 'Campaign', 'fundraising-toolkit' ) }
                    help={ campaignsFailed
                        ? __( 'Campaigns could not be loaded, so this will be recorded without one. Someone with campaign access can set it afterwards.', 'fundraising-toolkit' )
                        : __( 'Optional. Leave empty for a general donation.', 'fundraising-toolkit' ) }
                >
                    <SearchableSelect
                        value={ campaignId }
                        onChange={ ( next ) => { setCampaign( next ); setAttributedTo( '' ); } }
                        options={ campaigns }
                        placeholder={ campaignsFailed
                            ? __( 'Unavailable', 'fundraising-toolkit' )
                            : __( 'No campaign', 'fundraising-toolkit' ) }
                    />
                </Field>

                { attributions.length > 0 && (
                    <Field
                        label={ __( 'Credit to', 'fundraising-toolkit' ) }
                        help={ __( 'Optional. A check handed to somebody raising for this campaign counts towards their total as well as the campaign\'s.', 'fundraising-toolkit' ) }
                    >
                        <SearchableSelect
                            value={ attributedTo }
                            onChange={ setAttributedTo }
                            options={ attributions }
                            placeholder={ __( 'The campaign itself', 'fundraising-toolkit' ) }
                        />
                    </Field>
                ) }

                { funds.length > 0 && (
                    <Field
                        label={ __( 'Fund', 'fundraising-toolkit' ) }
                        help={ __( 'Optional. Leave empty to use the default fund.', 'fundraising-toolkit' ) }
                    >
                        <SearchableSelect
                            value={ fundId }
                            onChange={ setFund }
                            options={ funds }
                            placeholder={ __( 'Default fund', 'fundraising-toolkit' ) }
                        />
                    </Field>
                ) }

                <Field label={ __( 'Note', 'fundraising-toolkit' ) } help={ __( 'Only your team sees this.', 'fundraising-toolkit' ) }>
                    <textarea className="fundkit-input" rows={ 2 } value={ note } onChange={ ( e ) => setNote( e.target.value ) } />
                </Field>

                { /* eslint-disable-next-line jsx-a11y/label-has-associated-control -- Switch is self-labeled via its label prop */ }
                <label className="fundkit-rd__receipt">
                    <Switch checked={ sendReceipt } onChange={ setReceipt } label={ __( 'Email the donor a receipt', 'fundraising-toolkit' ) } />
                    <span className="fundkit-rd__receipt-txt">
                        <strong>{ sendReceipt
                            ? __( 'Email a receipt', 'fundraising-toolkit' )
                            : __( 'Do not email the donor', 'fundraising-toolkit' ) }</strong>
                        <span>{ sendReceipt
                            ? __( 'The donor gets a receipt for this donation.', 'fundraising-toolkit' )
                            : __( 'Nothing is sent, not even a receipt.', 'fundraising-toolkit' ) }</span>
                    </span>
                </label>
            </div>
        </Dialog>
    );
}
