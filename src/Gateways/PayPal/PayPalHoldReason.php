<?php

declare(strict_types=1);

namespace GiveFlow\Gateways\PayPal;

/**
 * Turns PayPal's hold codes into what the org has to do about them.
 *
 * The codes are not interchangeable and the difference is the whole point: an
 * eCheck settles itself within days and wants no action, while a receiving
 * preference holds the money until somebody accepts it and will never clear on
 * its own. Both look identical as "processing".
 *
 * @since 1.0.0
 */
final class PayPalHoldReason
{
    /** @since 1.0.0 */
    public static function describe(string $code): string
    {
        switch (strtoupper(trim($code))) {
            case 'ECHECK':
                return __('The donor paid by eCheck. PayPal will settle it in a few working days, and nothing is needed from you.', 'giveflow-fundraising-campaigns');

            case 'RECEIVING_PREFERENCE_MANDATES_MANUAL_ACTION':
                return __('Your PayPal account is set to hold payments like this one. It will not complete until you accept it in PayPal.', 'giveflow-fundraising-campaigns');

            case 'PENDING_REVIEW':
            case 'PAYMENT_REVIEW':
                return __('PayPal is reviewing this payment and has not released it yet.', 'giveflow-fundraising-campaigns');

            case 'VERIFICATION_REQUIRED':
                return __('PayPal is holding this payment until your account is verified.', 'giveflow-fundraising-campaigns');

            case 'TRANSACTION_HOLD':
                return __('PayPal has placed a hold on this transaction.', 'giveflow-fundraising-campaigns');

            case 'UNILATERAL':
                return __('The payment went to an email address that is not confirmed on your PayPal account.', 'giveflow-fundraising-campaigns');

            case 'BUYER_COMPLAINT':
                return __('The donor has raised a complaint with PayPal about this payment.', 'giveflow-fundraising-campaigns');

            case 'CHARGEBACK':
                return __('The donor has charged this payment back through their card issuer.', 'giveflow-fundraising-campaigns');

            case '':
                return __('PayPal is holding this payment and has not said why.', 'giveflow-fundraising-campaigns');

            default:
                return sprintf(
                    /* translators: %s: PayPal's own reason code, e.g. PENDING_REVIEW */
                    __('PayPal is holding this payment (%s).', 'giveflow-fundraising-campaigns'),
                    $code
                );
        }
    }
}
