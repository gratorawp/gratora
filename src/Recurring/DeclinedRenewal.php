<?php

declare(strict_types=1);

namespace Gratora\Recurring;

defined('ABSPATH') || exit;

use Gratora\Gateways\GatewayLabels;
use Gratora\Gateways\PaymentGateway;
use Gratora\Gateways\SupportsPaymentMethodUpdate;
use Gratora\Gateways\SupportsPaymentRetry;

/**
 * What can be done about a renewal the gateway did not collect.
 *
 * The donor profile says it in a banner and the subscriptions table says it
 * where the Retry control would have been, and both are answering the same
 * question about the same gateway. Asked twice they answer differently, which
 * is how the profile came to send an admin to a portal button that gateway
 * does not render.
 *
 * @since 1.0.0
 */
final class DeclinedRenewal
{
    /**
     * A plan with a renewal the gateway has not collected. The counter is
     * reset on collection, so it counts what is outstanding now.
     *
     * @since 1.0.0
     */
    public static function isOutstanding(RecurringPlan $plan): bool
    {
        if (in_array((string) $plan->status, RecurringPlanActions::TERMINAL, true)) {
            return false;
        }

        return (int) $plan->failed_renewals_count > 0 || (string) $plan->status === 'past_due';
    }

    /**
     * Null when the gateway takes a retry instruction: there the control is
     * the answer and a sentence beside it would only be in the way.
     *
     * @since 1.0.0
     */
    public static function whatCanBeDone(?PaymentGateway $gateway, string $slug): ?string
    {
        if ($gateway instanceof SupportsPaymentRetry) {
            return null;
        }

        $name = GatewayLabels::for($slug);

        if ($gateway === null) {
            return sprintf(
                /* translators: %s: the payment gateway's name, such as Stripe. */
                __('%s is not set up on this site, so nothing can be asked of it. Add its keys under Settings, Payment gateways and the payment can be retried from here.', 'gratora-donation-platform'),
                $name
            );
        }

        if ($gateway instanceof SupportsPaymentMethodUpdate) {
            return sprintf(
                /* translators: %s: the payment gateway's name, such as PayPal. */
                __('%s decides when to try a failed payment again and offers no way to ask it sooner. Asking the donor to update their card in the donor portal is what moves it.', 'gratora-donation-platform'),
                $name
            );
        }

        return sprintf(
            /* translators: %s: the payment gateway's name, such as GoCardless. */
            __('%s decides when to try a failed payment again, and neither you nor the donor can change the payment details from here. If it keeps failing, ask the donor to set the donation up again.', 'gratora-donation-platform'),
            $name
        );
    }
}
