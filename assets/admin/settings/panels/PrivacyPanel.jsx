import { __ } from '@wordpress/i18n';

import Card from '../../_shared/components/Card';
import FormRow from '../../_shared/components/FormRow';
import { ToggleRow } from '../../_shared/components/Switch';

export default function PrivacyPanel( { s } ) {
    return (
        <div className="dono-panel">
            <Card
                title={ __( 'Donor data handling', 'dono' ) }
                sub={ __( 'Controls applied to the donor record, IP logs, and what donors can do from their portal.', 'dono' ) }
                edited={ s.isDirty }
            >
                <FormRow
                    label={ __( 'Privacy policy URL', 'dono' ) }
                    help={ __( 'Linked from the donation form, receipts, and the donor portal footer.', 'dono' ) }
                >
                    <input
                        type="url"
                        className="dono-input"
                        value={ s.value( 'privacy_policy_url', '' ) }
                        onChange={ ( e ) => s.edit( { privacy_policy_url: e.target.value } ) }
                        placeholder={ __( 'Enter your privacy policy URL', 'dono' ) }
                    />
                </FormRow>

                <FormRow
                    label={ __( 'Retention after redaction', 'dono' ) }
                    help={ __( 'Days a redacted donor record is kept before being purged entirely. Donations stay linked to the anonymous record. 0 = purge immediately.', 'dono' ) }
                >
                    <input
                        type="number"
                        min={ 0 }
                        max={ 3650 }
                        className="dono-input"
                        style={ { maxWidth: 120 } }
                        value={ s.value( 'retention_days_after_redaction', 90 ) }
                        onChange={ ( e ) => s.edit( { retention_days_after_redaction: parseInt( e.target.value, 10 ) || 0 } ) }
                    />
                </FormRow>

                <ToggleRow
                    title={ __( 'Anonymise IPs in event logs', 'dono' ) }
                    sub={ __( 'IPs are hashed (SHA-256) before storage. Only the country is kept in clear text.', 'dono' ) }
                    checked={ !! s.value( 'anonymize_ips', true ) }
                    onChange={ s.setValue( 'anonymize_ips' ) }
                />

                <ToggleRow
                    title={ __( 'Default new donations to anonymous', 'dono' ) }
                    sub={ __( 'Pre-check the anonymous toggle on every donation form. Donors can opt out.', 'dono' ) }
                    checked={ !! s.value( 'always_anonymous_default', false ) }
                    onChange={ s.setValue( 'always_anonymous_default' ) }
                />

                <ToggleRow
                    title={ __( 'Allow data export from portal', 'dono' ) }
                    sub={ __( 'Donors can download a JSON archive of their data from the portal.', 'dono' ) }
                    checked={ !! s.value( 'allow_data_export', true ) }
                    onChange={ s.setValue( 'allow_data_export' ) }
                />

                <ToggleRow
                    title={ __( 'Allow account delete from portal', 'dono' ) }
                    sub={ __( 'Donors can request redaction directly. Receipt retention rules above still apply.', 'dono' ) }
                    checked={ !! s.value( 'allow_account_delete', true ) }
                    onChange={ s.setValue( 'allow_account_delete' ) }
                />
            </Card>
        </div>
    );
}
