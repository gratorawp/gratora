import { useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { useExtensionPanels, ExtensionSection } from '../../_shared/extensionTabs';
import Card from '../../_shared/components/Card';
import FormRow from '../../_shared/components/FormRow';
import BrandMark from '../../_shared/components/BrandMark';
import useCardOpen from '../../_shared/useCardOpen';
import { ToggleRow } from '../../_shared/components/Switch';
import StripeKeysCard from './StripeKeysCard';
import PayPalKeysCard from './PayPalKeysCard';

/** Placeholders the offline bank-details template expands at render time. */
const BANK_PLACEHOLDERS = [ '{amount}', '{reference}', '{donor_name}' ];

export default function GatewaysPanel( { s } ) {
    const bankRef = useRef( null );

    // At the caret, matching the email templates: a placeholder belongs where
    // the line needs it, and these sit inside a formatted block where appending
    // to the end is never what was meant.
    const insertPlaceholder = ( tag ) => {
        const el      = bankRef.current;
        const current = String( s.value( 'offline.bank_details', '' ) );
        const write   = s.setValue( 'offline.bank_details' );

        if ( ! el ) {
            write( current + tag );
            return;
        }

        const start = el.selectionStart;
        const end   = el.selectionEnd;
        write( current.slice( 0, start ) + tag + current.slice( end ) );

        window.requestAnimationFrame( () => {
            el.focus();
            el.setSelectionRange( start + tag.length, start + tag.length );
        } );
    };

    // Gateways that ship in an add-on belong beside the ones core ships, not
    // in a tab of their own: an admin looking for how to take a payment should
    // find every answer in one place.
    const gatewayPanels = useExtensionPanels( 'settings-gateways' );

    const offlineEnabled = !! s.value( 'offline.enabled', true );

    // The same rule OfflineGateway::canCharge() applies: either field is a way
    // to pay, and whitespace is not. Checking only instructions told a site
    // that had written bank details it was not configured, and opened this card
    // to nag about it, while donors were paying through it perfectly well.
    const offlineConfigured = [ 'offline.instructions', 'offline.bank_details' ]
        .some( ( key ) => String( s.value( key, '' ) ).trim() !== '' );

    const [ offlineOpen, setOfflineOpen ] = useCardOpen( offlineEnabled && ! offlineConfigured, 'payments', 'offline' );

    const offlinePill = ! offlineEnabled
        ? <span className="fundkit-pill fundkit-pill--gray"><span className="fundkit-pill__dot fundkit-pill__dot--soft" />{ __( 'Disabled', 'fundraising-toolkit' ) }</span>
        : offlineConfigured
            ? <span className="fundkit-pill fundkit-pill--green"><span className="fundkit-pill__dot" />{ __( 'Configured', 'fundraising-toolkit' ) }</span>
            : <span className="fundkit-pill fundkit-pill--amber"><span className="fundkit-pill__dot" />{ __( 'Enabled, no way to pay', 'fundraising-toolkit' ) }</span>;

    return (
        <div className="fundkit-panel">
            <Card
                title={ __( 'Test mode', 'fundraising-toolkit' ) }
                sub={ __( 'Org-wide rehearsal switch, also settable per form', 'fundraising-toolkit' ) }
                edited={ s.isDirty }
            >
                <ToggleRow
                    title={ __( 'Enable test mode for all forms', 'fundraising-toolkit' ) }
                    sub={ __( 'No real payment is taken and these donations are excluded from reporting.', 'fundraising-toolkit' ) }
                    checked={ !! s.value( 'test_mode', false ) }
                    onChange={ s.setValue( 'test_mode' ) }
                />
            </Card>

            <StripeKeysCard s={ s } />
            <PayPalKeysCard s={ s } />

            { gatewayPanels.map( ( panel ) => (
                <ExtensionSection key={ panel.id } panel={ panel } />
            ) ) }


            <Card
                leading={ <BrandMark letter="O" variant="offline" /> }
                title={ __( 'Offline donations', 'fundraising-toolkit' ) }
                sub={ __( 'Donor sees your bank details and pays offline', 'fundraising-toolkit' ) }
                meta={ offlinePill }
                edited={ s.isDirty }
                collapsible
                open={ offlineOpen }
                onToggle={ setOfflineOpen }
            >
                <ToggleRow
                    title={ __( 'Enable offline donations', 'fundraising-toolkit' ) }
                    sub={ __( 'For cash, check, or bank transfer donations marked paid by admin.', 'fundraising-toolkit' ) }
                    checked={ offlineEnabled }
                    onChange={ s.setValue( 'offline.enabled' ) }
                />

                <FormRow
                    label={ __( 'Instructions', 'fundraising-toolkit' ) }
                    help={ __( 'Emailed to donors who choose bank transfer, with their donation reference.', 'fundraising-toolkit' ) }
                    wide
                >
                    <textarea
                        className="fundkit-textarea"
                        rows={ 4 }
                        placeholder={ __( 'Please transfer the donation amount within 7 days. Use the reference number so we can match your donation to your receipt.', 'fundraising-toolkit' ) }
                        { ...s.bind( 'offline.instructions' ) }
                    />
                </FormRow>

                <FormRow
                    label={ __( 'Bank details template', 'fundraising-toolkit' ) }
                    help={ __( 'Click a placeholder to drop it in. They expand when the donor is shown their transfer details.', 'fundraising-toolkit' ) }
                    wide
                >
                    <div className="fundkit-merge-tags">
                        { BANK_PLACEHOLDERS.map( ( tag ) => (
                            <button
                                key={ tag }
                                type="button"
                                className="fundkit-merge-tag"
                                onClick={ () => insertPlaceholder( tag ) }
                            >
                                { tag }
                            </button>
                        ) ) }
                    </div>
                    <textarea
                        ref={ bankRef }
                        className="fundkit-textarea fundkit-textarea--mono"
                        rows={ 5 }
                        placeholder={ 'Account holder: …\nIBAN: …\nBIC:  …\nReference: {reference}\nAmount:    {amount}' }
                        { ...s.bind( 'offline.bank_details' ) }
                    />
                </FormRow>
            </Card>
        </div>
    );
}
