/**
 * Static metadata (label, description, recipient, merge tags) for donor-facing
 * email templates. Stored values (enabled/subject/body) live in the option.
 */

import { __ } from '@wordpress/i18n';

// Reused tag groups so we don't repeat the same lists.
const BASE_TAGS = [ '{donor_first_name}', '{donor_name}', '{donor_email}', '{organisation_name}', '{date}' ];
const DONATION_TAGS = [ ...BASE_TAGS, '{amount}', '{campaign_title}', '{receipt_number}', '{reference}', '{download_url}' ];

export function getDonorTemplates() {
    return [
        {
            id:        'donation_receipt',
            label:     __( 'Donation receipt', 'dono' ),
            desc:      __( 'Sent the moment a donation is successfully paid. This is the donor-facing thank you and tax receipt.', 'dono' ),
            recipient: __( 'Donor', 'dono' ),
            tags:      DONATION_TAGS,
        },
        {
            id:        'donation_first',
            label:     __( 'First donation welcome', 'dono' ),
            desc:      __( 'A one-off welcome sent when a donor gives for the first time, separate from the receipt. Warm and relational, not transactional.', 'dono' ),
            recipient: __( 'Donor', 'dono' ),
            tags:      [ '{donor_first_name}', '{donor_name}', '{organisation_name}' ],
        },
        {
            id:        'tribute_notification',
            label:     __( 'Tribute notification', 'dono' ),
            desc:      __( 'Sent to the person a tribute donor asked us to notify, telling them a donation was made in honor or in memory of someone. Never sent for test donations, and never names an anonymous donor.', 'dono' ),
            recipient: __( 'Honoree contact', 'dono' ),
            tags:      [ '{honoree_name}', '{tribute_type}', '{donor_name}', '{organisation_name}', '{campaign_title}', '{amount}', '{message}' ],
        },
        {
            id:        'offline_instructions',
            label:     __( 'Offline donation instructions', 'dono' ),
            desc:      __( 'Sent when a donor picks bank transfer. Shows IBAN, reference, and amount so they can complete the transfer.', 'dono' ),
            recipient: __( 'Donor', 'dono' ),
            tags:      [ ...DONATION_TAGS, '{reference}', '{bank_details}' ],
        },
        {
            id:        'donation_pending',
            label:     __( 'Pending donation', 'dono' ),
            desc:      __( 'Sent when a payment is processing (SEPA settlement, delayed cards) before the receipt is issued.', 'dono' ),
            recipient: __( 'Donor', 'dono' ),
            tags:      DONATION_TAGS,
        },
        {
            id:        'donation_refunded',
            label:     __( 'Donation refunded', 'dono' ),
            desc:      __( 'Sent when an admin refunds a donation. Explains when funds will reappear on the donor card.', 'dono' ),
            recipient: __( 'Donor', 'dono' ),
            tags:      DONATION_TAGS,
        },
        {
            id:        'recurring_renewal',
            label:     __( 'Recurring renewal', 'dono' ),
            desc:      __( 'Sent for each successful renewal of a recurring donation.', 'dono' ),
            recipient: __( 'Donor', 'dono' ),
            tags:      DONATION_TAGS,
        },
        {
            id:        'subscription_cancelled',
            label:     __( 'Subscription cancelled', 'dono' ),
            desc:      __( 'Sent when a recurring donation is cancelled, either by the donor or by an admin.', 'dono' ),
            recipient: __( 'Donor', 'dono' ),
            tags:      DONATION_TAGS,
        },
    ];
}

export function getTemplateById( id ) {
    return getDonorTemplates().find( ( t ) => t.id === id ) || null;
}
