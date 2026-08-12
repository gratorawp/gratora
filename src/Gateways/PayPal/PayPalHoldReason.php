<?php

declare(strict_types=1);

namespace Dono\Gateways\PayPal;

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
                return __('The donor paid by eCheck. PayPal will settle it in a few working days, and nothing is needed from you.', 'dono-fundraising-platform');

            case 'RECEIVING_PREFERENCE_MANDATES_MANUAL_ACTION':
                return __('Your PayPal account is set to hold payments like this one. It will not complete until you accept it in PayPal.', 'dono-fundraising-platform');

            case 'PENDING_REVIEW':
            case 'PAYMENT_REVIEW':
                return __('PayPal is reviewing this payment and has not released it yet.', 'dono-fundraising-platform');

            case 'VERIFICATION_REQUIRED':
                return __('PayPal is holding this payment until your account is verified.', 'dono-fundraising-platform');

            case 'TRANSACTION_HOLD':
                return __('PayPal has placed a hold on this transaction.', 'dono-fundraising-platform');

            case 'UNILATERAL':
                return __('The payment went to an email address that is not confirmed on your PayPal account.', 'dono-fundraising-platform');

            case 'BUYER_COMPLAINT':
                return __('The donor has raised a complaint with PayPal about this payment.', 'dono-fundraising-platform');

            case 'CHARGEBACK':
                return __('The donor has charged this payment back through their card issuer.', 'dono-fundraising-platform');

            case '':
                return __('PayPal is holding this payment and has not said why.', 'dono-fundraising-platform');

            default:
                return sprintf(
                    /* translators: %s: PayPal's own reason code, e.g. PENDING_REVIEW */
                    __('PayPal is holding this payment (%s).', 'dono-fundraising-platform'),
                    $code
                );
        }
    }
}
