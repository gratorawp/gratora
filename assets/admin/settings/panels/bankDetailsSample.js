import { __ } from '@wordpress/i18n';

/** The sample for the bank details box, in the shape the site's country pays by. */
export function bankDetailsSample( country ) {
    const account = String( country || '' ).toUpperCase() === 'US'
        ? [
            __( 'Account name: …', 'gratora-donation-platform' ),
            __( 'Routing number: …', 'gratora-donation-platform' ),
            __( 'Account number: …', 'gratora-donation-platform' ),
        ]
        : [
            __( 'Account holder: …', 'gratora-donation-platform' ),
            __( 'IBAN: …', 'gratora-donation-platform' ),
            __( 'BIC: …', 'gratora-donation-platform' ),
        ];

    return [
        ...account,
        /* translators: sample bank transfer line. Keep {reference} exactly as written: it is expanded when the donor is shown their transfer details. */
        __( 'Reference: {reference}', 'gratora-donation-platform' ),
        /* translators: sample bank transfer line. Keep {amount} exactly as written: it is expanded when the donor is shown their transfer details. */
        __( 'Amount: {amount}', 'gratora-donation-platform' ),
    ].join( '\n' );
}
