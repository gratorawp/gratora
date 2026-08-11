/**
 * Static metadata (label, description, recipient, merge tags) for donor-facing
 * email templates. Stored values (enabled/subject/body) live in the option.
 */

import { __ } from '@wordpress/i18n';

// Each template lists exactly the tags its handler passes. A shared "all
// donation tags" list was tempting and wrong: an unsupplied tag is not
// ignored, it reaches the donor as literal braces, so advertising one the
// handler never fills is an invitation to ship {receipt_number} in an email.

/**
 * The tags PHP says this template's sender passes.
 *
 * PHP owns the answer because the sender does. The literals below are only a
 * fallback for a page that somehow renders without the globals; if the two
 * disagree, PHP is right.
 */
function tagsFor( id, fallback ) {
    const fromServer = typeof window !== 'undefined'
        && window.dono
        && window.dono.email_template_tags
        && window.dono.email_template_tags[ id ];

    return Array.isArray( fromServer ) && fromServer.length
        ? fromServer.map( ( t ) => `{${ t }}` )
        : fallback;
}

/**
 * Templates registered by an add-on. PHP is the only place that knows they
 * exist, so without these the admin cannot find or edit an email their site
 * is already sending.
 */
function addonTemplates() {
    const meta = typeof window !== 'undefined'
        && window.dono
        && window.dono.email_template_meta;

    if ( ! Array.isArray( meta ) ) return [];

    return meta
        .filter( ( t ) => t && t.id )
        .map( ( t ) => ( {
            id:        String( t.id ),
            label:     String( t.label || t.id ),
            desc:      String( t.desc || '' ),
            recipient: String( t.recipient || '' ),
            tags:      tagsFor( t.id, [] ),
        } ) );
}

export function getDonorTemplates() {
    return [ ...coreTemplates(), ...addonTemplates() ];
}

// magic_link stays off this list until the editor can hide the "Send this
// email" toggle for it: turning that off leaves a donor asking for a sign-in
// link with a confirmation screen and no email.
function coreTemplates() {
    return [
        {
            id:        'donation_receipt',
            label:     __( 'Donation receipt', 'dono' ),
            desc:      __( 'Sent the moment a donation is successfully paid. This is the donor-facing thank you and tax receipt.', 'dono' ),
            recipient: __( 'Donor', 'dono' ),
            tags:      tagsFor( 'donation_receipt', [ '{donor_first_name}', '{donor_name}', '{organisation_name}', '{amount}', '{campaign_title}', '{receipt_number}', '{reference}', '{download_url}' ] ),
        },
        {
            id:        'donation_first',
            label:     __( 'First donation welcome', 'dono' ),
            desc:      __( 'A one-off welcome sent when a donor gives for the first time, separate from the receipt. Warm and relational, not transactional.', 'dono' ),
            recipient: __( 'Donor', 'dono' ),
            tags:      tagsFor( 'donation_first', [ '{donor_first_name}', '{donor_name}', '{organisation_name}' ] ),
        },
        {
            id:        'offline_instructions',
            label:     __( 'Offline donation instructions', 'dono' ),
            desc:      __( 'Sent when a donor picks bank transfer. Shows IBAN, reference, and amount so they can complete the transfer.', 'dono' ),
            recipient: __( 'Donor', 'dono' ),
            tags:      tagsFor( 'offline_instructions', [ '{donor_name}', '{organisation_name}', '{amount}', '{campaign_title}', '{reference}', '{bank_details}', '{instructions}' ] ),
        },
        {
            id:        'donation_pending',
            label:     __( 'Pending donation', 'dono' ),
            desc:      __( 'Sent when a payment is processing (SEPA settlement, delayed cards) before the receipt is issued.', 'dono' ),
            recipient: __( 'Donor', 'dono' ),
            tags:      tagsFor( 'donation_pending', [ '{donor_first_name}', '{donor_name}', '{organisation_name}', '{amount}', '{campaign_title}', '{reference}' ] ),
        },
        {
            id:        'donation_refunded',
            label:     __( 'Donation refunded', 'dono' ),
            desc:      __( 'Sent when an admin refunds a donation. Explains when funds will reappear on the donor card.', 'dono' ),
            recipient: __( 'Donor', 'dono' ),
            tags:      tagsFor( 'donation_refunded', [ '{donor_first_name}', '{donor_name}', '{organisation_name}', '{amount}', '{campaign_title}', '{reference}' ] ),
        },
        {
            id:        'recurring_renewal',
            label:     __( 'Recurring renewal', 'dono' ),
            desc:      __( 'Sent for each successful renewal of a recurring donation.', 'dono' ),
            recipient: __( 'Donor', 'dono' ),
            tags:      tagsFor( 'recurring_renewal', [ '{donor_first_name}', '{donor_name}', '{organisation_name}', '{amount}', '{campaign_title}', '{receipt_number}', '{reference}' ] ),
        },
        {
            id:        'subscription_payment_failed',
            label:     __( 'Recurring payment failed', 'dono' ),
            desc:      __( 'Sent the first time a renewal is declined, so the donor can update their card before the donation lapses. Retries do not re-send it.', 'dono' ),
            recipient: __( 'Donor', 'dono' ),
            // Only what onRecurringFailed actually passes. The wider donation
            // list advertises tags this email has no value for (receipt
            // number, download url), and an unsupplied tag renders literally.
            tags:      tagsFor( 'subscription_payment_failed', [ '{donor_first_name}', '{donor_name}', '{organisation_name}', '{amount}', '{campaign_title}', '{portal_url}' ] ),
        },
        {
            id:        'subscription_cancelled',
            label:     __( 'Subscription cancelled', 'dono' ),
            desc:      __( 'Sent when a recurring donation is cancelled, either by the donor or by an admin.', 'dono' ),
            recipient: __( 'Donor', 'dono' ),
            tags:      tagsFor( 'subscription_cancelled', [ '{donor_first_name}', '{donor_name}', '{organisation_name}', '{amount}', '{campaign_title}' ] ),
        },
        {
            id:        'recurring_amount_changed',
            label:     __( 'Recurring amount changed', 'dono' ),
            desc:      __( 'Sent when someone at the organization changes the amount of a recurring donation. A donor who changes their own in the portal is not emailed.', 'dono' ),
            recipient: __( 'Donor', 'dono' ),
            tags:      tagsFor( 'recurring_amount_changed', [ '{donor_first_name}', '{donor_name}', '{organisation_name}', '{amount}', '{campaign_title}', '{old_amount}', '{portal_url}' ] ),
        },
        {
            id:        'recurring_paused',
            label:     __( 'Recurring donation paused', 'dono' ),
            desc:      __( 'Sent when someone at the organization pauses a recurring donation, with the date it restarts. A donor who pauses their own in the portal is not emailed.', 'dono' ),
            recipient: __( 'Donor', 'dono' ),
            tags:      tagsFor( 'recurring_paused', [ '{donor_first_name}', '{donor_name}', '{organisation_name}', '{amount}', '{campaign_title}', '{resumes_at}', '{portal_url}' ] ),
        },
        {
            id:        'recurring_resumed',
            label:     __( 'Recurring donation restarted', 'dono' ),
            desc:      __( 'Sent when someone at the organization restarts a paused recurring donation, with the next payment date.', 'dono' ),
            recipient: __( 'Donor', 'dono' ),
            tags:      tagsFor( 'recurring_resumed', [ '{donor_first_name}', '{donor_name}', '{organisation_name}', '{amount}', '{campaign_title}', '{next_payment_at}', '{portal_url}' ] ),
        },
        {
            id:        'recurring_skipped',
            label:     __( 'Next donation skipped', 'dono' ),
            desc:      __( 'Sent when someone at the organization skips one upcoming payment. The recurring donation itself continues.', 'dono' ),
            recipient: __( 'Donor', 'dono' ),
            tags:      tagsFor( 'recurring_skipped', [ '{donor_first_name}', '{donor_name}', '{organisation_name}', '{amount}', '{campaign_title}', '{next_payment_at}', '{portal_url}' ] ),
        },
    ];
}
