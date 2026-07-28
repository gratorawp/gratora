import { __ } from '@wordpress/i18n';

import Card from '../../_shared/components/Card';
import FormRow from '../../_shared/components/FormRow';
import BrandMark from '../../_shared/components/BrandMark';
import { ToggleRow } from '../../_shared/components/Switch';
import StripeKeysCard from './StripeKeysCard';

export default function GatewaysPanel( { s } ) {
    const offlineEnabled    = !! s.value( 'offline.enabled', true );
    const offlineConfigured = !! s.value( 'offline.instructions', '' );

    const offlinePill = ! offlineEnabled
        ? <span className="dono-pill dono-pill--gray"><span className="dono-pill__dot dono-pill__dot--soft" />{ __( 'Disabled', 'dono' ) }</span>
        : offlineConfigured
            ? <span className="dono-pill dono-pill--green"><span className="dono-pill__dot" />{ __( 'Configured', 'dono' ) }</span>
            : <span className="dono-pill dono-pill--amber"><span className="dono-pill__dot" />{ __( 'Enabled, no instructions', 'dono' ) }</span>;

    return (
        <div className="dono-panel">
            <Card
                title={ __( 'Test mode', 'dono' ) }
                sub={ __( 'Org-wide rehearsal switch, also settable per form', 'dono' ) }
                edited={ s.isDirty }
            >
                <ToggleRow
                    title={ __( 'Enable test mode for all forms', 'dono' ) }
                    sub={ __( 'No real payment is taken and these donations are excluded from reporting.', 'dono' ) }
                    checked={ !! s.value( 'test_mode', false ) }
                    onChange={ s.setValue( 'test_mode' ) }
                />
            </Card>

            <StripeKeysCard s={ s } />

            <Card
                leading={ <BrandMark letter="O" variant="offline" /> }
                title={ __( 'Offline donations', 'dono' ) }
                sub={ __( 'Donor sees your bank details and pays offline', 'dono' ) }
                meta={ offlinePill }
                edited={ s.isDirty }
            >
                <ToggleRow
                    title={ __( 'Enable offline donations', 'dono' ) }
                    sub={ __( 'For cash, cheque, or bank transfer donations marked paid by admin.', 'dono' ) }
                    checked={ offlineEnabled }
                    onChange={ s.setValue( 'offline.enabled' ) }
                />

                <FormRow
                    label={ __( 'Instructions', 'dono' ) }
                    help={ __( 'Shown to donors after they pick bank transfer.', 'dono' ) }
                    wide
                >
                    <textarea
                        className="dono-textarea"
                        rows={ 4 }
                        placeholder={ __( 'Please transfer the donation amount within 7 days. Use the reference number so we can match your donation to your receipt.', 'dono' ) }
                        { ...s.bind( 'offline.instructions' ) }
                    />
                </FormRow>

                <FormRow
                    label={ __( 'Bank details template', 'dono' ) }
                    help={ <>{ __( 'Placeholders:', 'dono' ) } <code>{'{amount}'}</code> · <code>{'{reference}'}</code> · <code>{'{donor_name}'}</code></> }
                    wide
                >
                    <textarea
                        className="dono-textarea dono-textarea--mono"
                        rows={ 5 }
                        placeholder={ 'Account holder: …\nIBAN: …\nBIC:  …\nReference: {reference}\nAmount:    {amount}' }
                        { ...s.bind( 'offline.bank_details' ) }
                    />
                </FormRow>
            </Card>
        </div>
    );
}
