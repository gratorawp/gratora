import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { formatDate } from '@gratora/ui/utils/format';

import Card from '../../_shared/components/Card';
import FormRow from '../../_shared/components/FormRow';
import { ToggleRow } from '../../_shared/components/Switch';
import Notice from '../../_shared/components/Notice';

/** Preview the affected donor count; inForce distinguishes saved settings from drafts. */
function RetentionPreview( { years, inForce } ) {
    const [ data, setData ] = useState( null );

    // Saving re-arms the grace period, so the first-run date below has to come
    // from a request made after it, which is what inForce brings in here.
    useEffect( () => {
        let aborted = false;
        // Choosing a window is several keystrokes, and each one is a count over
        // the donor table.
        const timer = setTimeout( () => {
            apiFetch( { path: `/gratora/v1/admin/settings/retention-preview?days=30&years=${ years }` } )
                .then( ( d ) => { if ( ! aborted ) setData( d ); } )
                .catch( () => { if ( ! aborted ) setData( null ); } );
        }, 400 );

        return () => { aborted = true; clearTimeout( timer ); };
    }, [ years, inForce ] );

    if ( ! data ) return null;

    if ( ! data.years ) {
        return (
            <p className="gratora-muted">
                { __( 'No window is set, so nothing is erased automatically. Enter a number of years above.', 'gratora-donation-platform' ) }
            </p>
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
                'gratora-donation-platform'
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
                    'gratora-donation-platform'
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
                'gratora-donation-platform'
            ),
            soon.toLocaleString()
        ) );
    }

    if ( lines.length === 0 ) {
        lines.push( __( 'No donor is due for erasure in the next 30 days.', 'gratora-donation-platform' ) );
    } else if ( ! inForce ) {
        lines.push( __( 'Nothing is erased until this is saved.', 'gratora-donation-platform' ) );
    } else if ( ! pending ) {
        lines.push( __( 'They are erased on the next nightly run.', 'gratora-donation-platform' ) );
    }

    // Only once the window is the saved one. While it is still being chosen the
    // line above already says nothing happens, and two sentences about nothing
    // being erased read as a contradiction rather than as two facts.
    if ( pending && inForce ) {
        lines.push( sprintf(
            /* translators: %s: a date. */
            __( 'Nothing is erased before %s.', 'gratora-donation-platform' ),
            formatDate( new Date( startsAt ).toISOString() )
        ) );
    }

    // A notice for a count of donors about to be erased, which is the one
    // answer somebody should be stopped by. Nobody due is the ordinary reading
    // of the field above, and dressing it as an announcement gives a calm
    // answer the weight of an alarming one.
    if ( now > 0 || soon > 0 ) {
        return (
            <Notice status="warning" isDismissible={ false }>
                { lines.join( ' ' ) }
            </Notice>
        );
    }

    return <p className="gratora-muted">{ lines.join( ' ' ) }</p>;
}

/**
 * Offer detected proxy configuration so IP quotas distinguish visitors behind shared
 * infrastructure.
 */
function ProxyFix( { s } ) {
    const detected = window.gratora?.detectedProxy || null;
    const current  = s.value( 'trusted_proxies', [] ) || [];

    if ( ! detected || current.length ) {
        return null;
    }

    const label = detected === 'cloudflare'
        ? __( 'This site is behind Cloudflare.', 'gratora-donation-platform' )
        : __( 'This site is behind a proxy or load balancer.', 'gratora-donation-platform' );

    return (
        <Notice status="warning" isDismissible={ false }>
            <p>
                <strong>{ label }</strong>{ ' ' }
                { __( 'Every visitor is reaching the site as the same address, so spam limits are counting the whole site as one visitor. Donors can be turned away because of somebody else.', 'gratora-donation-platform' ) }
            </p>
            <p>
                <button
                    type="button"
                    className="button button-primary"
                    onClick={ () => s.setValue( 'trusted_proxies' )( [ detected ] ) }
                >
                    { __( 'Fix this', 'gratora-donation-platform' ) }
                </button>
                { ' ' }
                <span className="gratora-muted">
                    { __( 'Then save. Nothing else to look up.', 'gratora-donation-platform' ) }
                </span>
            </p>
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
        <div className="gratora-panel">
            <Card
                title={ __( 'Donor data handling', 'gratora-donation-platform' ) }
                sub={ __( 'Controls applied to the donor record, IP logs, and what donors can do from their portal.', 'gratora-donation-platform' ) }
                edited={ s.isDirty }
            >
                <FormRow
                    label={ __( 'Privacy policy URL', 'gratora-donation-platform' ) }
                    help={ __( 'Linked from the donation form, wherever a privacy notice block is placed.', 'gratora-donation-platform' ) }
                >
                    <input
                        type="url"
                        className="gratora-input"
                        value={ s.value( 'privacy_policy_url', '' ) }
                        onChange={ ( e ) => s.edit( { privacy_policy_url: e.target.value } ) }
                        placeholder={ __( 'Enter your privacy policy URL', 'gratora-donation-platform' ) }
                    />
                </FormRow>

                <FormRow
                    label={ __( 'Reunite window after redaction (days)', 'gratora-donation-platform' ) }
                    fieldHelp={ __( 'An erased donor who gives again within this window keeps their giving history. After it, they start over as a new donor. Past donations stay counted either way. 0 severs the link at once; it does not mean off.', 'gratora-donation-platform' ) }
                >
                    <input
                        type="number"
                        min={ 0 }
                        max={ 3650 }
                        className="gratora-input"
                        style={ { maxWidth: 120 } }
                        value={ s.value( 'retention_days_after_redaction', 90 ) }
                        onChange={ ( e ) => s.edit( { retention_days_after_redaction: parseInt( e.target.value, 10 ) || 0 } ) }
                    />
                </FormRow>

                <ToggleRow
                    title={ __( 'Erase inactive donors automatically', 'gratora-donation-platform' ) }
                    sub={ __( 'While this is off, a donor is only ever erased because they asked or because an admin erased them. Turning it on lets a nightly run erase donors who have gone years without giving.', 'gratora-donation-platform' ) }
                    checked={ eraseInactive }
                    onChange={ s.setValue( 'erase_inactive_donors' ) }
                />

                { eraseInactive && (
                    <>
                        <FormRow
                            label={ __( 'Erase donors inactive for (years)', 'gratora-donation-platform' ) }
                            fieldHelp={ __( 'Donors with no donation for this long are erased on the nightly run, as if they had asked. Anyone on a recurring plan is skipped. Their donations stay counted.', 'gratora-donation-platform' ) }
                        >
                            <input
                                type="number"
                                min={ 1 }
                                max={ 100 }
                                className="gratora-input"
                                style={ { maxWidth: 120 } }
                                { ...s.bindNumber( 'donor_retention_years' ) }
                            />
                        </FormRow>

                        <RetentionPreview years={ Number( years ) || 0 } inForce={ inForce } />
                    </>
                ) }

                <FormRow
                    label={ __( 'Keep the activity log for (days)', 'gratora-donation-platform' ) }
                    fieldHelp={ __( 'Older entries are deleted. Only the log is affected; donations, donors and receipts are kept. 0 turns this off.', 'gratora-donation-platform' ) }
                >
                    <input
                        type="number"
                        min={ 0 }
                        max={ 36500 }
                        className="gratora-input"
                        style={ { maxWidth: 120 } }
                        value={ s.value( 'event_retention_days', 730 ) }
                        onChange={ ( e ) => s.edit( { event_retention_days: parseInt( e.target.value, 10 ) || 0 } ) }
                    />
                </FormRow>

                <ToggleRow
                    title={ __( 'Anonymize IPs in event logs', 'gratora-donation-platform' ) }
                    sub={ __( 'IPs are hashed (SHA-256) before storage. Only the country is kept in clear text.', 'gratora-donation-platform' ) }
                    checked={ !! s.value( 'anonymize_ips', true ) }
                    onChange={ s.setValue( 'anonymize_ips' ) }
                />

                <ProxyFix s={ s } />

                <FormRow
                    label={ __( 'What is in front of this site', 'gratora-donation-platform' ) }
                    help={ __( 'Leave empty unless a CDN, load balancer or reverse proxy serves this site. Write cloudflare, or private_ranges for a proxy on your own network, or list addresses and CIDR ranges one per line. Spam limits count visitors by address, and behind a proxy every visitor arrives as the proxy, so the whole site would share one visitor\'s allowance.', 'gratora-donation-platform' ) }
                    wide
                >
                    <textarea
                        className="gratora-textarea"
                        rows={ 3 }
                        spellCheck={ false }
                        placeholder={ 'cloudflare' }
                        value={ ( s.value( 'trusted_proxies', [] ) || [] ).join( '\n' ) }
                        onChange={ ( e ) => s.setValue( 'trusted_proxies' )(
                            e.target.value.split( '\n' ).map( ( l ) => l.trim() ).filter( Boolean )
                        ) }
                    />
                </FormRow>

                <ToggleRow
                    title={ __( 'Show Gravatar profile pictures', 'gratora-donation-platform' ) }
                    sub={ __( "Donor lists show Gravatars instead of initials. Each one sends a hash of the donor's email to gravatar.com from the visitor's browser. Anonymous donors are never shown one.", 'gratora-donation-platform' ) }
                    checked={ !! s.value( 'gravatar_avatars', false ) }
                    onChange={ s.setValue( 'gravatar_avatars' ) }
                />

                <ToggleRow
                    title={ __( 'Default new donations to anonymous', 'gratora-donation-platform' ) }
                    sub={ __( 'Pre-check the anonymous toggle on every donation form. Donors can opt out.', 'gratora-donation-platform' ) }
                    checked={ !! s.value( 'always_anonymous_default', false ) }
                    onChange={ s.setValue( 'always_anonymous_default' ) }
                />

                <ToggleRow
                    title={ __( 'Allow data export from portal', 'gratora-donation-platform' ) }
                    sub={ __( 'Donors can download a JSON archive of their data from the portal.', 'gratora-donation-platform' ) }
                    checked={ !! s.value( 'allow_data_export', true ) }
                    onChange={ s.setValue( 'allow_data_export' ) }
                />

                <ToggleRow
                    title={ __( 'Allow account delete from portal', 'gratora-donation-platform' ) }
                    sub={ __( 'Donors can request redaction directly. Donations and receipts are kept either way, for tax and accounting; only the personal details are erased.', 'gratora-donation-platform' ) }
                    checked={ !! s.value( 'allow_account_delete', true ) }
                    onChange={ s.setValue( 'allow_account_delete' ) }
                />
            </Card>
        </div>
    );
}
