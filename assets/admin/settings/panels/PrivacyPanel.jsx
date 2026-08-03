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
                    label={ __( 'Reunite window after redaction', 'dono' ) }
                    help={ __( 'A redacted donor who gives again within this many days is recognised and reunited with their giving history. After it, the last link to their email is severed and they are treated as a new donor. Their past donations stay counted either way. 0 severs it at redaction; unlike the retention settings below, 0 does not mean "off".', 'dono' ) }
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

                <FormRow
                    label={ __( 'Erase donors inactive for', 'dono' ) }
                    help={ __( 'Years. A donor who has not given for this long is redacted automatically, exactly as an erasure request would. Anyone with an active or paused recurring plan is skipped, however long ago they last gave. Their donations stay counted. 0 turns this off.', 'dono' ) }
                >
                    <input
                        type="number"
                        min={ 0 }
                        max={ 100 }
                        className="dono-input"
                        style={ { maxWidth: 120 } }
                        value={ s.value( 'donor_retention_years', 10 ) }
                        onChange={ ( e ) => s.edit( { donor_retention_years: parseInt( e.target.value, 10 ) || 0 } ) }
                    />
                </FormRow>

                <FormRow
                    label={ __( 'Keep the activity log for', 'dono' ) }
                    help={ __( 'Days. Older entries are deleted. The log records what happened and when, and backs the donor timeline and recent-activity views; donations, donors and receipts are never touched by it. 0 turns this off and the log grows without limit.', 'dono' ) }
                >
                    <input
                        type="number"
                        min={ 0 }
                        max={ 36500 }
                        className="dono-input"
                        style={ { maxWidth: 120 } }
                        value={ s.value( 'event_retention_days', 730 ) }
                        onChange={ ( e ) => s.edit( { event_retention_days: parseInt( e.target.value, 10 ) || 0 } ) }
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
                    sub={ __( 'Donors can request redaction directly. Donations and receipts are kept either way, for tax and accounting; only the personal details are erased.', 'dono' ) }
                    checked={ !! s.value( 'allow_account_delete', true ) }
                    onChange={ s.setValue( 'allow_account_delete' ) }
                />
            </Card>
        </div>
    );
}
