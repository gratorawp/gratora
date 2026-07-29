<?php

declare(strict_types=1);

namespace Dono\Gateways;

use Dono\Donations\Donation;

/**
 * The check every webhook must pass before it is allowed to mark a donation paid.
 *
 * A verified signature only proves the event came from the processor. It does not
 * prove the event is about *this* donation, for *this* amount, in *this* mode. The
 * QA sweep of 2026-07-28 found all three gaps live: a $0.01 PayPal capture
 * confirmed a $10,000 donation, one gateway's event confirmed another's donation, and
 * a test-mode signing secret confirmed live money on two gateways.
 *
 * The donor-facing amount is always exactly `donation.amount_cents` on every
 * gateway (a covered fee is a portion of that total, not an addition), so an
 * exact match is the correct test rather than a tolerance.
 *
 * @version 1.0.0
 */
final class WebhookPaymentGuard
{
    /**
     * @param string      $gateway         the gateway handling the event.
     * @param bool|null   $verifiedIsTest  the mode of the secret that actually
     *                                     verified the signature, not the mode
     *                                     the event claims. Null means unknown,
     *                                     which is refused.
     * @param int|null    $observedCents   what the processor says was paid, in
     *                                     Dono's storage units. Null is refused:
     *                                     an unknown amount cannot be checked.
     * @param string|null $observedCurrency ISO code, or null to skip.
     *
     * @return string|null null when the payment may confirm this donation,
     *                     otherwise the reason it may not.
     */
    public static function refuse(
        Donation $donation,
        string $gateway,
        ?bool $verifiedIsTest,
        ?int $observedCents,
        ?string $observedCurrency = null,
    ): ?string {
        if ((string) $donation->gateway !== $gateway) {
            return sprintf(
                'event is from %s but the donation is a %s donation',
                $gateway,
                (string) $donation->gateway
            );
        }

        // A test secret must never confirm live money, nor the reverse. This is
        // checked against the secret that verified, because the event body is
        // attacker-controlled and its own mode flag proves nothing.
        if ($verifiedIsTest === null) {
            return 'the mode of the verifying secret is unknown';
        }
        if ($verifiedIsTest !== (bool) $donation->is_test) {
            return sprintf(
                'a %s-mode secret verified this event but the donation is %s',
                $verifiedIsTest ? 'test' : 'live',
                $donation->is_test ? 'test' : 'live'
            );
        }

        if ($observedCents === null) {
            return 'the event does not state an amount';
        }

        if ($observedCurrency !== null
            && strtoupper($observedCurrency) !== strtoupper((string) $donation->currency)) {
            return sprintf(
                'paid in %s but the donation is in %s',
                strtoupper($observedCurrency),
                strtoupper((string) $donation->currency)
            );
        }

        if ($observedCents !== (int) $donation->amount_cents) {
            return sprintf(
                'paid %d but the donation is for %d',
                $observedCents,
                (int) $donation->amount_cents
            );
        }

        return null;
    }
}
