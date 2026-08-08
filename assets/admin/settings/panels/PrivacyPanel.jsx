import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

import Card from '../../_shared/components/Card';
import FormRow from '../../_shared/components/FormRow';
import { ToggleRow } from '../../_shared/components/Switch';
import Notice from '../../_shared/components/Notice';

/**
 * What the nightly sweep would take. Erasure is the one thing here that runs
 * without being asked and cannot be undone, so the number is shown before it
 * happens rather than discovered afterwards as blank names.
 */
function RetentionPreview() {
    const [ data, setData ] = useState( null );

    useEffect( () => {
        apiFetch( { path: '/dono/v1/admin/settings/retention-preview?days=30' } )
            .then( setData )
            .catch( () => setData( null ) );
    }, [] );

    if ( ! data || ! data.years ) return null;

    const startsAt = Number( data.starts_at || 0 ) * 1000;
    const pending  = startsAt > Date.now();

    if ( pending ) {
        return (
            <Notice status="info" isDismissible={ false }>
                { sprintf(
                    /* translators: %s: a date. */
                    __( 'Automatic erasure has not started on this site yet. The first run is %s, which leaves time to import your history and check the window above.', 'dono' ),
                    new Date( startsAt ).toLocaleDateString()
                ) }
            </Notice>
        );
    }

    if ( ! data.eligible_now && ! data.within_days ) {
        return (
            <Notice status="info" isDismissible={ false }>
                { __( 'No donor is due to be erased in the next 30 days.', 'dono' ) }
            </Notice>
        );
    }

    return (
        <Notice status="warning" isDismissible={ false }>
            { data.eligible_now > 0 && sprintf(
                /* translators: %s: number of donors. */
                _n(
                    '%s donor is past the window and will be erased on the next nightly run.',
                    '%s donors are past the window and will be erased on the next nightly run.',
                    data.eligible_now,
                    'dono'
                ),
                data.eligible_now.toLocaleString()
            ) }
            { ' ' }
            { data.within_days > data.eligible_now && sprintf(
                /* translators: %s: number of donors. */
                _n(
                    '%s in total will have gone within 30 days.',
                    '%s in total will have gone within 30 days.',
                    data.within_days,
                    'dono'
                ),
                data.within_days.toLocaleString()
            ) }
        </Notice>
    );
}

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
                    label={ __( 'Reunite window after redaction (days)', 'dono' ) }
                    fieldHelp={ __( 'An erased donor who gives again within this window keeps their giving history. After it, they start over as a new donor. Past donations stay counted either way. 0 severs the link at once; it does not mean off.', 'dono' ) }
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
                    label={ __( 'Erase donors inactive for (years)', 'dono' ) }
                    fieldHelp={ __( 'Donors with no donation for this long are erased automatically, as if they had asked. Anyone on a recurring plan is skipped. Their donations stay counted. 0 turns this off.', 'dono' ) }
                >
                    <input
                        type="number"
                        min={ 0 }
                        max={ 100 }
                        className="dono-input"
                        style={ { maxWidth: 120 } }
                        value={ s.value( 'donor_retention_years', 7 ) }
                        onChange={ ( e ) => s.edit( { donor_retention_years: parseInt( e.target.value, 10 ) || 0 } ) }
                    />
                </FormRow>

                <RetentionPreview />

                <FormRow
                    label={ __( 'Keep the activity log for (days)', 'dono' ) }
                    fieldHelp={ __( 'Older entries are deleted. Only the log is affected; donations, donors and receipts are kept. 0 turns this off.', 'dono' ) }
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
                    title={ __( 'Show Gravatar profile pictures', 'dono' ) }
                    sub={ __( "Donor lists show each donor's Gravatar instead of their initials. Their browser asks gravatar.com for it, which means a hash of the donor's email address reaches a third party from every visit to a public campaign page. Anonymous donors are never shown one.", 'dono' ) }
                    checked={ !! s.value( 'gravatar_avatars', false ) }
                    onChange={ s.setValue( 'gravatar_avatars' ) }
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
