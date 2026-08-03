<?php

declare(strict_types=1);

namespace Dono\Gateways;

use Dono\Donations\Donation;
use Dono\Recurring\RecurringPlan;

/**
 * The check every webhook must pass before it is allowed to mark a donation paid.
 *
 * A verified signature only proves the event came from the processor. It does not
 * prove the event is about *this* donation, for *this* amount, in *this* mode.
 * Without all three checks a $0.01 capture confirms a $10,000 donation, one
 * gateway's event confirms another's donation, and a test-mode signing secret
 * confirms live money.
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
        $refusal = self::refuseToTouch($donation, $gateway, $verifiedIsTest);
        if ($refusal !== null) {
            return $refusal;
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

    /**
     * May this event act on this donation at all?
     *
     * A refund, a failure and a cancellation each need the same two answers a
     * confirmation needs (right gateway, right mode) but cannot use refuse():
     * a refund states its own amount rather than the donation's, and a
     * cancellation states none, so the amount check would refuse them all.
     *
     * @return string|null null when the event may act, otherwise why not.
     */
    public static function refuseToTouch(
        Donation $donation,
        string $gateway,
        ?bool $verifiedIsTest,
    ): ?string {
        return self::sameGatewayAndMode(
            (string) $donation->gateway,
            (bool) $donation->is_test,
            $gateway,
            $verifiedIsTest,
            'donation'
        );
    }

    /** The same question for a recurring plan, which carries the same two facts. */
    public static function refuseToTouchPlan(
        RecurringPlan $plan,
        string $gateway,
        ?bool $verifiedIsTest,
    ): ?string {
        return self::sameGatewayAndMode(
            (string) $plan->gateway,
            (bool) $plan->is_test,
            $gateway,
            $verifiedIsTest,
            'plan'
        );
    }

    private static function sameGatewayAndMode(
        string $rowGateway,
        bool $rowIsTest,
        string $gateway,
        ?bool $verifiedIsTest,
        string $noun,
    ): ?string {
        if ($rowGateway !== $gateway) {
            return sprintf('event is from %s but the %s is a %s %s', $gateway, $noun, $rowGateway, $noun);
        }

        // Checked against the secret that verified, because the event body is
        // attacker-controlled and its own mode flag proves nothing.
        if ($verifiedIsTest === null) {
            return 'the mode of the verifying secret is unknown';
        }
        if ($verifiedIsTest !== $rowIsTest) {
            return sprintf(
                'a %s-mode secret verified this event but the %s is %s',
                $verifiedIsTest ? 'test' : 'live',
                $noun,
                $rowIsTest ? 'test' : 'live'
            );
        }

        return null;
    }
}
