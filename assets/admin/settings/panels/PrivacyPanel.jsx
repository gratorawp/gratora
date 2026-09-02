import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { formatDate } from '@fundkit/ui/utils/format';

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
            apiFetch( { path: `/fundkit/v1/admin/settings/retention-preview?days=30&years=${ years }` } )
                .then( ( d ) => { if ( ! aborted ) setData( d ); } )
                .catch( () => { if ( ! aborted ) setData( null ); } );
        }, 400 );

        return () => { aborted = true; clearTimeout( timer ); };
    }, [ years, inForce ] );

    if ( ! data ) return null;

    if ( ! data.years ) {
        return (
            <p className="fundkit-muted">
                { __( 'No window is set, so nothing is erased automatically. Enter a number of years above.', 'fundraising-toolkit' ) }
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
                'fundraising-toolkit'
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
                    'fundraising-toolkit'
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
                'fundraising-toolkit'
            ),
            soon.toLocaleString()
        ) );
    }

    if ( lines.length === 0 ) {
        lines.push( __( 'No donor is due for erasure in the next 30 days.', 'fundraising-toolkit' ) );
    } else if ( ! inForce ) {
        lines.push( __( 'Nothing is erased until this is saved.', 'fundraising-toolkit' ) );
    } else if ( ! pending ) {
        lines.push( __( 'They are erased on the next nightly run.', 'fundraising-toolkit' ) );
    }

    // Only once the window is the saved one. While it is still being chosen the
    // line above already says nothing happens, and two sentences about nothing
    // being erased read as a contradiction rather than as two facts.
    if ( pending && inForce ) {
        lines.push( sprintf(
            /* translators: %s: a date. */
            __( 'Nothing is erased before %s.', 'fundraising-toolkit' ),
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

    return <p className="fundkit-muted">{ lines.join( ' ' ) }</p>;
}

/**
 * What sits in front of the site, said in a sentence and fixed with a button.
 *
 * Behind a CDN or reverse proxy every visitor arrives as the same address, so
 * limits meant for one visitor apply to everyone at once: donors are refused
 * because of somebody else, and one caller can close the form for all of them.
 * It fails quietly, by turning a donor away, so nothing surfaces it unless this
 * does.
 *
 * The ranges are ours to know. Telling an org to go and find their proxy's CIDR
 * blocks is telling them to leave it broken.
 */
function ProxyFix( { s } ) {
    const detected = window.fundkit?.detectedProxy || null;
    const current  = s.value( 'trusted_proxies', [] ) || [];

    if ( ! detected || current.length ) {
        return null;
    }

    const label = detected === 'cloudflare'
        ? __( 'This site is behind Cloudflare.', 'fundraising-toolkit' )
        : __( 'This site is behind a proxy or load balancer.', 'fundraising-toolkit' );

    return (
        <Notice status="warning" isDismissible={ false }>
            <p>
                <strong>{ label }</strong>{ ' ' }
                { __( 'Every visitor is reaching the site as the same address, so spam limits are counting the whole site as one visitor. Donors can be turned away because of somebody else.', 'fundraising-toolkit' ) }
            </p>
            <p>
                <button
                    type="button"
                    className="button button-primary"
                    onClick={ () => s.setValue( 'trusted_proxies' )( [ detected ] ) }
                >
                    { __( 'Fix this', 'fundraising-toolkit' ) }
                </button>
                { ' ' }
                <span className="fundkit-muted">
                    { __( 'Then save. Nothing else to look up.', 'fundraising-toolkit' ) }
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
        <div className="fundkit-panel">
            <Card
                title={ __( 'Donor data handling', 'fundraising-toolkit' ) }
                sub={ __( 'Controls applied to the donor record, IP logs, and what donors can do from their portal.', 'fundraising-toolkit' ) }
                edited={ s.isDirty }
            >
                <FormRow
                    label={ __( 'Privacy policy URL', 'fundraising-toolkit' ) }
                    help={ __( 'Linked from the donation form, wherever a privacy notice block is placed.', 'fundraising-toolkit' ) }
                >
                    <input
                        type="url"
                        className="fundkit-input"
                        value={ s.value( 'privacy_policy_url', '' ) }
                        onChange={ ( e ) => s.edit( { privacy_policy_url: e.target.value } ) }
                        placeholder={ __( 'Enter your privacy policy URL', 'fundraising-toolkit' ) }
                    />
                </FormRow>

                <FormRow
                    label={ __( 'Reunite window after redaction (days)', 'fundraising-toolkit' ) }
                    fieldHelp={ __( 'An erased donor who gives again within this window keeps their giving history. After it, they start over as a new donor. Past donations stay counted either way. 0 severs the link at once; it does not mean off.', 'fundraising-toolkit' ) }
                >
                    <input
                        type="number"
                        min={ 0 }
                        max={ 3650 }
                        className="fundkit-input"
                        style={ { maxWidth: 120 } }
                        value={ s.value( 'retention_days_after_redaction', 90 ) }
                        onChange={ ( e ) => s.edit( { retention_days_after_redaction: parseInt( e.target.value, 10 ) || 0 } ) }
                    />
                </FormRow>

                <ToggleRow
                    title={ __( 'Erase inactive donors automatically', 'fundraising-toolkit' ) }
                    sub={ __( 'While this is off, a donor is only ever erased because they asked or because an admin erased them. Turning it on lets a nightly run erase donors who have gone years without giving.', 'fundraising-toolkit' ) }
                    checked={ eraseInactive }
                    onChange={ s.setValue( 'erase_inactive_donors' ) }
                />

                { eraseInactive && (
                    <>
                        <FormRow
                            label={ __( 'Erase donors inactive for (years)', 'fundraising-toolkit' ) }
                            fieldHelp={ __( 'Donors with no donation for this long are erased on the nightly run, as if they had asked. Anyone on a recurring plan is skipped. Their donations stay counted.', 'fundraising-toolkit' ) }
                        >
                            <input
                                type="number"
                                min={ 1 }
                                max={ 100 }
                                className="fundkit-input"
                                style={ { maxWidth: 120 } }
                                { ...s.bindNumber( 'donor_retention_years' ) }
                            />
                        </FormRow>

                        <RetentionPreview years={ Number( years ) || 0 } inForce={ inForce } />
                    </>
                ) }

                <FormRow
                    label={ __( 'Keep the activity log for (days)', 'fundraising-toolkit' ) }
                    fieldHelp={ __( 'Older entries are deleted. Only the log is affected; donations, donors and receipts are kept. 0 turns this off.', 'fundraising-toolkit' ) }
                >
                    <input
                        type="number"
                        min={ 0 }
                        max={ 36500 }
                        className="fundkit-input"
                        style={ { maxWidth: 120 } }
                        value={ s.value( 'event_retention_days', 730 ) }
                        onChange={ ( e ) => s.edit( { event_retention_days: parseInt( e.target.value, 10 ) || 0 } ) }
                    />
                </FormRow>

                <ToggleRow
                    title={ __( 'Anonymize IPs in event logs', 'fundraising-toolkit' ) }
                    sub={ __( 'IPs are hashed (SHA-256) before storage. Only the country is kept in clear text.', 'fundraising-toolkit' ) }
                    checked={ !! s.value( 'anonymize_ips', true ) }
                    onChange={ s.setValue( 'anonymize_ips' ) }
                />

                <ProxyFix s={ s } />

                <FormRow
                    label={ __( 'What is in front of this site', 'fundraising-toolkit' ) }
                    help={ __( 'Leave empty unless a CDN, load balancer or reverse proxy serves this site. Write cloudflare, or private_ranges for a proxy on your own network, or list addresses and CIDR ranges one per line. Spam limits count visitors by address, and behind a proxy every visitor arrives as the proxy, so the whole site would share one visitor\'s allowance.', 'fundraising-toolkit' ) }
                    wide
                >
                    <textarea
                        className="fundkit-textarea"
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
                    title={ __( 'Show Gravatar profile pictures', 'fundraising-toolkit' ) }
                    sub={ __( "Donor lists show Gravatars instead of initials. Each one sends a hash of the donor's email to gravatar.com from the visitor's browser. Anonymous donors are never shown one.", 'fundraising-toolkit' ) }
                    checked={ !! s.value( 'gravatar_avatars', false ) }
                    onChange={ s.setValue( 'gravatar_avatars' ) }
                />

                <ToggleRow
                    title={ __( 'Default new donations to anonymous', 'fundraising-toolkit' ) }
                    sub={ __( 'Pre-check the anonymous toggle on every donation form. Donors can opt out.', 'fundraising-toolkit' ) }
                    checked={ !! s.value( 'always_anonymous_default', false ) }
                    onChange={ s.setValue( 'always_anonymous_default' ) }
                />

                <ToggleRow
                    title={ __( 'Allow data export from portal', 'fundraising-toolkit' ) }
                    sub={ __( 'Donors can download a JSON archive of their data from the portal.', 'fundraising-toolkit' ) }
                    checked={ !! s.value( 'allow_data_export', true ) }
                    onChange={ s.setValue( 'allow_data_export' ) }
                />

                <ToggleRow
                    title={ __( 'Allow account delete from portal', 'fundraising-toolkit' ) }
                    sub={ __( 'Donors can request redaction directly. Donations and receipts are kept either way, for tax and accounting; only the personal details are erased.', 'fundraising-toolkit' ) }
                    checked={ !! s.value( 'allow_account_delete', true ) }
                    onChange={ s.setValue( 'allow_account_delete' ) }
                />
            </Card>
        </div>
    );
}
