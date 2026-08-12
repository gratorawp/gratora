<?php

declare(strict_types=1);

namespace Dono\Recurring;

use Dono\Donations\DonationService;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\SubscriptionAware;

/**
 * The one canonical way to cancel a recurring plan: tell the gateway, then run
 * the local side effects (status transition + cancellation email) gated on
 * winning markCancelled's conditional update, so they fire exactly once even
 * when a gateway webhook races the same cancel. Shared by the donor portal, the
 * admin CLI, and campaign archiving.
 *
 * @since 1.0.0
 */
final class RecurringCanceller
{
    /** @since 1.0.0 */
    public function __construct(
        private RecurringPlanRepository $plans,
        private DonationService $donations,
        private GatewayManager $gateways,
    ) {
    }

    /**
     * Cancel a single plan. Returns true if this call won the active->cancelled
     * transition (its side effects ran); false if it was already cancelled.
     *
     * @throws GatewayUnreachable when the plan lives at a processor this site
     *                            cannot currently talk to
     *
     * @since 1.0.0
     */
    public function cancel(RecurringPlan $plan, ?string $reason = null): bool
    {
        $gateway = $this->gateways->get((string) $plan->gateway);

        // Offline is registered and simply has no subscriptions, so a local flip
        // is the whole of it. A gateway that is absent entirely is a different
        // answer: Stripe and PayPal register only while their credentials are
        // stored, so a disconnected Stripe means "cannot reach the processor",
        // not "this plan has no processor". Flipping local state on that reading
        // marks the plan cancelled, emails the donor to say so, and leaves the
        // card charged every month with the renewals no longer even handled.
        if ($gateway === null) {
            throw new GatewayUnreachable(esc_html(sprintf(
                'Cannot cancel plan %d: the %s gateway is not available, so its subscription would keep billing.',
                (int) $plan->id,
                (string) $plan->gateway
            )));
        }

        // cancelSubscription is idempotent per its contract.
        if ($gateway instanceof SubscriptionAware) {
            $gateway->cancelSubscription($plan, $reason);
        }

        $won = $this->plans->markCancelled($plan, gmdate('Y-m-d H:i:s'), $reason);
        if ($won) {
            $this->donations->recordRecurringCancellation($plan, $reason);
        }
        return $won;
    }
}
