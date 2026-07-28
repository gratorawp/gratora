<?php

declare(strict_types=1);

namespace Dono\Rest;

use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Donations\DonationService;
use Dono\Foundation\Time\Clock;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\Razorpay\RazorpayGateway;
use Dono\Recurring\FrequencyMap;
use Dono\Recurring\RecurringPlan;
use Dono\Recurring\RecurringPlanRepository;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Donor-facing Razorpay endpoints, called by Checkout's success handler.
 *
 * Unauthenticated by necessity (the donor is a stranger), so neither route
 * trusts the browser for anything that decides money. The order and
 * subscription ids come from what Dono stored at createIntent, never from the
 * request, and the signature Checkout returned is verified against those stored
 * ids before anything is recorded.
 */
final class RazorpayController
{
    private const NS = 'dono/v1';

    public function __construct(
        private DonationRepository $donations,
        private DonationService $donationService,
        private GatewayManager $gateways,
        private RecurringPlanRepository $plans,
        private Clock $clock,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NS, '/gateways/razorpay/capture', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'capture'],
            'permission_callback' => '__return_true',
            'args'                => [
                'reference'  => ['type' => 'string', 'required' => true],
                'payment_id' => ['type' => 'string', 'required' => true],
                'signature'  => ['type' => 'string', 'required' => true],
            ],
        ]);

        register_rest_route(self::NS, '/gateways/razorpay/subscription', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'recordSubscription'],
            'permission_callback' => '__return_true',
            'args'                => [
                'reference'  => ['type' => 'string', 'required' => true],
                'payment_id' => ['type' => 'string', 'required' => true],
                'signature'  => ['type' => 'string', 'required' => true],
            ],
        ]);
    }

    /** Verify and capture the payment the donor just made, then confirm. */
    public function capture(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $donation = $this->pendingDonation((string) $request->get_param('reference'));
        if ($donation instanceof WP_Error) {
            return $donation;
        }

        $gateway = $this->gateway();
        if ($gateway instanceof WP_Error) {
            return $gateway;
        }

        $result = $gateway->confirm($donation, [
            'payment_id' => (string) $request->get_param('payment_id'),
            'signature'  => (string) $request->get_param('signature'),
        ]);

        if (! $result->success) {
            return $this->error(
                'dono_razorpay_capture_failed',
                $result->error ?: __('Razorpay could not complete this payment.', 'dono'),
                400
            );
        }

        $this->donationService->confirm($donation, $result->toArray());
        $fresh = $this->donations->findByReference((string) $donation->reference);

        return new WP_REST_Response([
            'status'    => $fresh?->status ?? 'paid',
            'reference' => $donation->reference,
        ], 200);
    }

    /**
     * Record the subscription the donor authorised. Razorpay charges the first
     * instalment itself; the subscription.charged webhook confirms the donation
     * and bumps the counters.
     */
    public function recordSubscription(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $donation = $this->pendingDonation((string) $request->get_param('reference'));
        if ($donation instanceof WP_Error) {
            return $donation;
        }

        $gateway = $this->gateway();
        if ($gateway instanceof WP_Error) {
            return $gateway;
        }

        if (! FrequencyMap::isRecurring((string) $donation->frequency)) {
            return $this->error('dono_razorpay_not_recurring', __('That donation is not recurring.', 'dono'), 400);
        }
        if ($donation->recurring_plan_id) {
            // Already recorded: a double submit, not an error.
            return new WP_REST_Response(['status' => $donation->status, 'reference' => $donation->reference], 200);
        }

        $verified = $gateway->verifySubscriptionPayload($donation, [
            'payment_id' => (string) $request->get_param('payment_id'),
            'signature'  => (string) $request->get_param('signature'),
        ]);

        if (! $verified) {
            return $this->error(
                'dono_razorpay_bad_signature',
                __('That payment could not be verified against this donation.', 'dono'),
                403
            );
        }

        $subId = (string) $donation->gateway_intent_id;

        try {
            $sub = $gateway->fetchSubscription((bool) $donation->is_test, $subId);
        } catch (RuntimeException $e) {
            return $this->error('dono_razorpay_subscription_lookup', $e->getMessage(), 400);
        }

        $status = (string) ($sub['status'] ?? '');
        if (! in_array($status, ['active', 'authenticated', 'created', 'pending'], true)) {
            return $this->error(
                'dono_razorpay_subscription_status',
                sprintf(
                    /* translators: %s: Razorpay subscription status */
                    __('Razorpay reports this subscription as %s.', 'dono'),
                    $status
                ),
                400
            );
        }

        $plan = $this->createPlan($donation, $subId, $sub, $status);

        return new WP_REST_Response([
            'status'    => $donation->status,
            'reference' => $donation->reference,
            'plan_id'   => (int) $plan->id,
        ], 200);
    }

    /** @param array<string,mixed> $sub */
    private function createPlan(Donation $donation, string $subId, array $sub, string $status): RecurringPlan
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        [$unit, $count] = FrequencyMap::toStripe((string) $donation->frequency);

        $plan = RecurringPlan::make();
        $plan->donor_id           = (int) $donation->donor_id;
        $plan->form_id            = $donation->form_id;
        $plan->campaign_id        = $donation->campaign_id;
        $plan->fund_id            = $donation->fund_id;
        $plan->fundraiser_id      = $donation->fundraiser_id;
        $plan->fundraiser_team_id = $donation->fundraiser_team_id;
        $plan->gateway            = 'razorpay';
        $plan->gateway_subscription_id = $subId;
        $plan->gateway_customer_id     = (string) ($sub['customer_id'] ?? '') ?: null;
        $plan->amount_cents       = (int) $donation->amount_cents;
        $plan->currency           = (string) $donation->currency;
        $plan->base_amount_cents  = $donation->base_amount_cents;
        $plan->fx_rate            = $donation->fx_rate;
        $plan->interval_unit      = $unit;
        $plan->interval_count     = $count;
        // Payment counters stay at zero: the first subscription.charged records
        // them, so the plan is never credited for money that has not landed.
        $plan->status             = $status === 'active' ? 'active' : 'pending';
        $plan->is_test            = (bool) $donation->is_test;
        $plan->started_at         = $now;
        $plan->next_payment_at    = $this->timestampToDatetime($sub['charge_at'] ?? null);
        $plan->created_at         = $now;
        $plan->updated_at         = $now;
        $plan->save();

        $donation->recurring_plan_id = (int) $plan->id;
        $donation->save();

        return $plan;
    }

    /** Razorpay reports schedule fields as unix timestamps. */
    private function timestampToDatetime(mixed $value): ?string
    {
        $ts = is_numeric($value) ? (int) $value : 0;
        return $ts > 0 ? gmdate('Y-m-d H:i:s', $ts) : null;
    }

    private function gateway(): RazorpayGateway|WP_Error
    {
        $gateway = $this->gateways->get('razorpay');
        if (! $gateway instanceof RazorpayGateway) {
            return $this->error('dono_razorpay_unavailable', __('Razorpay is not available.', 'dono'), 400);
        }
        return $gateway;
    }

    private function pendingDonation(string $reference): Donation|WP_Error
    {
        $reference = trim($reference);
        $donation  = $reference !== '' ? $this->donations->findByReference($reference) : null;

        if (! $donation || $donation->gateway !== 'razorpay') {
            return $this->error('dono_razorpay_no_donation', __('We could not find that donation.', 'dono'), 404);
        }
        return $donation;
    }

    private function error(string $code, string $message, int $status): WP_Error
    {
        return new WP_Error($code, $message, ['status' => $status]);
    }
}
