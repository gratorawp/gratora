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

    const offlineEnabled    = !! s.value( 'offline.enabled', true );
    const offlineConfigured = !! s.value( 'offline.instructions', '' );

    const [ offlineOpen, setOfflineOpen ] = useCardOpen( offlineEnabled && ! offlineConfigured, 'payments', 'offline' );

    const offlinePill = ! offlineEnabled
        ? <span className="giveflow-pill giveflow-pill--gray"><span className="giveflow-pill__dot giveflow-pill__dot--soft" />{ __( 'Disabled', 'giveflow-fundraising-campaigns' ) }</span>
        : offlineConfigured
            ? <span className="giveflow-pill giveflow-pill--green"><span className="giveflow-pill__dot" />{ __( 'Configured', 'giveflow-fundraising-campaigns' ) }</span>
            : <span className="giveflow-pill giveflow-pill--amber"><span className="giveflow-pill__dot" />{ __( 'Enabled, no instructions', 'giveflow-fundraising-campaigns' ) }</span>;

    return (
        <div className="giveflow-panel">
            <Card
                title={ __( 'Test mode', 'giveflow-fundraising-campaigns' ) }
                sub={ __( 'Org-wide rehearsal switch, also settable per form', 'giveflow-fundraising-campaigns' ) }
                edited={ s.isDirty }
            >
                <ToggleRow
                    title={ __( 'Enable test mode for all forms', 'giveflow-fundraising-campaigns' ) }
                    sub={ __( 'No real payment is taken and these donations are excluded from reporting.', 'giveflow-fundraising-campaigns' ) }
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
                title={ __( 'Offline donations', 'giveflow-fundraising-campaigns' ) }
                sub={ __( 'Donor sees your bank details and pays offline', 'giveflow-fundraising-campaigns' ) }
                meta={ offlinePill }
                edited={ s.isDirty }
                collapsible
                open={ offlineOpen }
                onToggle={ setOfflineOpen }
            >
                <ToggleRow
                    title={ __( 'Enable offline donations', 'giveflow-fundraising-campaigns' ) }
                    sub={ __( 'For cash, check, or bank transfer donations marked paid by admin.', 'giveflow-fundraising-campaigns' ) }
                    checked={ offlineEnabled }
                    onChange={ s.setValue( 'offline.enabled' ) }
                />

                <FormRow
                    label={ __( 'Instructions', 'giveflow-fundraising-campaigns' ) }
                    help={ __( 'Emailed to donors who choose bank transfer, with their donation reference.', 'giveflow-fundraising-campaigns' ) }
                    wide
                >
                    <textarea
                        className="giveflow-textarea"
                        rows={ 4 }
                        placeholder={ __( 'Please transfer the donation amount within 7 days. Use the reference number so we can match your donation to your receipt.', 'giveflow-fundraising-campaigns' ) }
                        { ...s.bind( 'offline.instructions' ) }
                    />
                </FormRow>

                <FormRow
                    label={ __( 'Bank details template', 'giveflow-fundraising-campaigns' ) }
                    help={ __( 'Click a placeholder to drop it in. They expand when the donor is shown their transfer details.', 'giveflow-fundraising-campaigns' ) }
                    wide
                >
                    <div className="giveflow-merge-tags">
                        { BANK_PLACEHOLDERS.map( ( tag ) => (
                            <button
                                key={ tag }
                                type="button"
                                className="giveflow-merge-tag"
                                onClick={ () => insertPlaceholder( tag ) }
                            >
                                { tag }
                            </button>
                        ) ) }
                    </div>
                    <textarea
                        ref={ bankRef }
                        className="giveflow-textarea giveflow-textarea--mono"
                        rows={ 5 }
                        placeholder={ 'Account holder: …\nIBAN: …\nBIC:  …\nReference: {reference}\nAmount:    {amount}' }
                        { ...s.bind( 'offline.bank_details' ) }
                    />
                </FormRow>
            </Card>
        </div>
    );
}
