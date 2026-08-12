import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { formatDate } from '@dono/ui/utils/format';

import Card from '../../_shared/components/Card';
import FormRow from '../../_shared/components/FormRow';
import { ToggleRow } from '../../_shared/components/Switch';
import Notice from '../../_shared/components/Notice';

/**
 * What the sweep would take at the window on screen. Erasure reaches donors who
 * never asked for it and cannot be undone, so the number belongs in front of
 * whoever is choosing the window, while they are still choosing it.
 *
 * inForce says whether that window is the saved one, because a count of donors
 * nothing is going to touch yet must not be worded as a sentence already passed.
 */
function RetentionPreview( { years, inForce } ) {
    const [ data, setData ] = useState( null );

    // Saving re-arms the grace period, so the first-run date below has to come
    // from a request made after it, which is what inForce brings in here.
    useEffect( () => {
        let aborted = false;
        // Choosing a window is several keystrokes, and each one is a count over
        // the donor table.
        const timer = setTimeout( () => {
            apiFetch( { path: `/dono/v1/admin/settings/retention-preview?days=30&years=${ years }` } )
                .then( ( d ) => { if ( ! aborted ) setData( d ); } )
                .catch( () => { if ( ! aborted ) setData( null ); } );
        }, 400 );

        return () => { aborted = true; clearTimeout( timer ); };
    }, [ years, inForce ] );

    if ( ! data ) return null;

    if ( ! data.years ) {
        return (
            <Notice status="info" isDismissible={ false }>
                { __( 'No window is set, so nothing is erased automatically. Enter a number of years above.', 'dono' ) }
            </Notice>
        );
    }

    const startsAt = Number( data.starts_at || 0 ) * 1000;
    const pending  = startsAt > Date.now();
    const now      = Number( data.eligible_now || 0 );
    const soon     = Number( data.within_days || 0 );

    const lines = [];

    if ( now > 0 ) {
        lines.push( sprintf(
            /* translators: %s: number of donors. */
            _n(
                '%s donor is past this window.',
                '%s donors are past this window.',
                now,
                'dono'
            ),
            now.toLocaleString()
        ) );

        if ( soon > now ) {
            lines.push( sprintf(
                /* translators: %s: number of donors. */
                _n(
                    '%s in total reaches it within 30 days.',
                    '%s in total reach it within 30 days.',
                    soon,
                    'dono'
                ),
                soon.toLocaleString()
            ) );
        }
    } else if ( soon > 0 ) {
        lines.push( sprintf(
            /* translators: %s: number of donors. */
            _n(
                '%s donor reaches this window within 30 days.',
                '%s donors reach this window within 30 days.',
                soon,
                'dono'
            ),
            soon.toLocaleString()
        ) );
    }

    if ( lines.length === 0 ) {
        lines.push( __( 'No donor is due for erasure in the next 30 days.', 'dono' ) );
    } else if ( ! inForce ) {
        lines.push( __( 'Nothing is erased until this is saved.', 'dono' ) );
    } else if ( ! pending ) {
        lines.push( __( 'They are erased on the next nightly run.', 'dono' ) );
    }

    // Only once the window is the saved one. While it is still being chosen the
    // line above already says nothing happens, and two sentences about nothing
    // being erased read as a contradiction rather than as two facts.
    if ( pending && inForce ) {
        lines.push( sprintf(
            /* translators: %s: a date. */
            __( 'Nothing is erased before %s.', 'dono' ),
            formatDate( new Date( startsAt ).toISOString() )
        ) );
    }

    return (
        <Notice status={ now > 0 || soon > 0 ? 'warning' : 'info' } isDismissible={ false }>
            { lines.join( ' ' ) }
        </Notice>
    );
}

export default function PrivacyPanel( { s } ) {
    const eraseInactive = !! s.value( 'erase_inactive_donors', false );
    // Read as text, so a box cleared to be retyped stays cleared: coercing an
    // empty one to a number puts a digit in front of whatever is typed next,
    // and the shortest window this field can express is the one that erases the
    // most people. A cleared box saves as no window, which erases nobody.
    const years         = s.value( 'donor_retention_years', '' );
    const inForce       = !! s.savedRecord.erase_inactive_donors
        && Number( s.savedRecord.donor_retention_years ) === Number( years );

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

                <ToggleRow
                    title={ __( 'Erase inactive donors automatically', 'dono' ) }
                    sub={ __( 'While this is off, a donor is only ever erased because they asked or because an admin erased them. Turning it on lets a nightly run erase donors who have gone years without giving.', 'dono' ) }
                    checked={ eraseInactive }
                    onChange={ s.setValue( 'erase_inactive_donors' ) }
                />

                { eraseInactive && (
                    <>
                        <FormRow
                            label={ __( 'Erase donors inactive for (years)', 'dono' ) }
                            fieldHelp={ __( 'Donors with no donation for this long are erased on the nightly run, as if they had asked. Anyone on a recurring plan is skipped. Their donations stay counted.', 'dono' ) }
                        >
                            <input
                                type="number"
                                min={ 1 }
                                max={ 100 }
                                className="dono-input"
                                style={ { maxWidth: 120 } }
                                { ...s.bindNumber( 'donor_retention_years' ) }
                            />
                        </FormRow>

                        <RetentionPreview years={ Number( years ) || 0 } inForce={ inForce } />
                    </>
                ) }

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
                    title={ __( 'Anonymize IPs in event logs', 'dono' ) }
                    sub={ __( 'IPs are hashed (SHA-256) before storage. Only the country is kept in clear text.', 'dono' ) }
                    checked={ !! s.value( 'anonymize_ips', true ) }
                    onChange={ s.setValue( 'anonymize_ips' ) }
                />

                <ToggleRow
                    title={ __( 'Show Gravatar profile pictures', 'dono' ) }
                    sub={ __( "Donor lists show Gravatars instead of initials. Each one sends a hash of the donor's email to gravatar.com from the visitor's browser. Anonymous donors are never shown one.", 'dono' ) }
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
