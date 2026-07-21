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
 */
final class RecurringCanceller
{
    public function __construct(
        private RecurringPlanRepository $plans,
        private DonationService $donations,
        private GatewayManager $gateways,
    ) {
    }

    /**
     * Cancel a single plan. Returns true if this call won the active->cancelled
     * transition (its side effects ran); false if it was already cancelled.
     */
    public function cancel(RecurringPlan $plan, ?string $reason = null): bool
    {
        // Null when the gateway isn't SubscriptionAware (e.g. Offline): only the
        // local state flips. cancelSubscription is idempotent per its contract.
        $gateway = $this->gateways->get((string) $plan->gateway);
        if ($gateway instanceof SubscriptionAware) {
            $gateway->cancelSubscription($plan, $reason);
        }

        $won = $this->plans->markCancelled($plan, gmdate('Y-m-d H:i:s'), $reason);
        if ($won) {
            $this->donations->recordRecurringCancellation($plan, $reason);
        }
        return $won;
    }

    /**
     * Cancel every live active plan attributed to a campaign (used when an
     * admin archives a campaign and opts to stop its subscriptions). One
     * plan's gateway failure must not abort the rest or the archive: failures
     * are collected so the caller can surface them, and the loop continues.
     *
     * @return array{cancelled:int, failed:int}
     */
    public function cancelActiveForCampaign(int $campaignId, ?string $reason = null): array
    {
        $cancelled = 0;
        $failed    = 0;
        foreach (
            RecurringPlan::query()
                ->where('campaign_id', $campaignId)
                ->where('status', 'active')
                ->where('is_test', false)
                ->getAll() as $plan
        ) {
            try {
                if ($this->cancel($plan, $reason)) {
                    $cancelled++;
                }
            } catch (\Throwable $e) {
                $failed++;
                error_log(sprintf('dono: archive-cancel failed for plan %d: %s', (int) $plan->id, $e->getMessage()));
            }
        }
        return ['cancelled' => $cancelled, 'failed' => $failed];
    }
}
