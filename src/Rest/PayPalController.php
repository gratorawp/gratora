<?php

declare(strict_types=1);

namespace Dono\Rest;

use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Donations\DonationService;
use Dono\Foundation\Time\Clock;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\PayPal\PayPalAccount;
use Dono\Gateways\PayPal\PayPalApi;
use Dono\Gateways\PayPal\PayPalGateway;
use Dono\Gateways\PayPal\PayPalMoney;
use Dono\Recurring\FrequencyMap;
use Dono\Recurring\RecurringPlan;
use Dono\Recurring\RecurringPlanRepository;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Donor-facing PayPal endpoints, called by the JS SDK buttons once the donor
 * finishes in PayPal's popup.
 *
 * These are unauthenticated by necessity (the donor is a stranger), so neither
 * route trusts the browser for anything that decides money:
 *  - capture uses the order id Dono stored at createIntent, never the one the
 *    client posts, so a caller cannot point a capture at a different order;
 *  - the subscription route re-reads the subscription from PayPal and requires
 *    its custom_id to match the donation reference before it records anything.
 */
final class PayPalController
{
    private const NS = 'dono/v1';

    public function __construct(
        private DonationRepository $donations,
        private DonationService $donationService,
        private GatewayManager $gateways,
        private PayPalApi $api,
        private PayPalAccount $account,
        private RecurringPlanRepository $plans,
        private Clock $clock,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NS, '/gateways/paypal/capture', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'capture'],
            'permission_callback' => '__return_true',
            'args'                => [
                'reference' => ['type' => 'string', 'required' => true],
            ],
        ]);

        register_rest_route(self::NS, '/gateways/paypal/subscription', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'recordSubscription'],
            'permission_callback' => '__return_true',
            'args'                => [
                'reference'       => ['type' => 'string', 'required' => true],
                'subscription_id' => ['type' => 'string', 'required' => true],
            ],
        ]);
    }

    /** Capture the order the donor just approved and confirm the donation. */
    public function capture(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $donation = $this->pendingDonation((string) $request->get_param('reference'));
        if ($donation instanceof WP_Error) {
            return $donation;
        }

        $gateway = $this->gateways->get('paypal');
        if (! $gateway instanceof PayPalGateway) {
            return $this->error('dono_paypal_unavailable', __('PayPal is not available.', 'dono'), 400);
        }

        // confirm() reads the stored gateway_intent_id: the client cannot
        // redirect this at another order.
        $result = $gateway->confirm($donation);

        if (! $result->success) {
            return $this->error(
                'dono_paypal_capture_failed',
                $result->error ?: __('PayPal could not complete this payment.', 'dono'),
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
     * Record the subscription the donor approved. PayPal has already taken the
     * first payment by this point; the opening PAYMENT.SALE.COMPLETED webhook
     * confirms the donation and bumps the counters.
     */
    public function recordSubscription(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $donation = $this->pendingDonation((string) $request->get_param('reference'));
        if ($donation instanceof WP_Error) {
            return $donation;
        }

        if (! FrequencyMap::isRecurring((string) $donation->frequency)) {
            return $this->error('dono_paypal_not_recurring', __('That donation is not recurring.', 'dono'), 400);
        }
        if ($donation->recurring_plan_id) {
            // Already recorded: a double submit, not an error.
            return new WP_REST_Response(['status' => $donation->status, 'reference' => $donation->reference], 200);
        }

        $subId = trim((string) $request->get_param('subscription_id'));
        if ($subId === '') {
            return $this->error('dono_paypal_bad_subscription', __('Missing subscription id.', 'dono'), 400);
        }

        $this->account->useTestMode((bool) $donation->is_test);

        try {
            $sub = $this->api->get('/v1/billing/subscriptions/' . rawurlencode($subId));
        } catch (RuntimeException $e) {
            return $this->error('dono_paypal_subscription_lookup', $e->getMessage(), 400);
        }

        // The browser supplied this id, so bind it to this donation before
        // trusting it: PayPal echoes back the custom_id set at create time.
        if ((string) ($sub['custom_id'] ?? '') !== (string) $donation->reference) {
            return $this->error(
                'dono_paypal_subscription_mismatch',
                __('That subscription does not belong to this donation.', 'dono'),
                403
            );
        }

        // custom_id proves which donation the subscription is for. It says
        // nothing about the money, and the browser chooses the plan: the SDK is
        // handed a plan id for this donation's amount, and can just as easily
        // create the subscription on a cheaper plan and hand that back here.
        // Nothing compared the two, so a 1.00 subscription could stand behind a
        // 1000.00 recurring donation, and the plan would be recorded, reported
        // and renewed at the amount nobody was charging.
        $meta         = (array) ($donation->gateway_metadata ?? []);
        $expectedPlan = (string) ($meta['paypal_plan_id'] ?? '');
        if ($expectedPlan === '' || (string) ($sub['plan_id'] ?? '') !== $expectedPlan) {
            return $this->error(
                'dono_paypal_subscription_plan_mismatch',
                __('That subscription is not for this donation amount.', 'dono'),
                403
            );
        }

        $status = (string) ($sub['status'] ?? '');
        if (! in_array($status, ['ACTIVE', 'APPROVED', 'APPROVAL_PENDING'], true)) {
            return $this->error(
                'dono_paypal_subscription_status',
                sprintf(
                    /* translators: %s: PayPal subscription status */
                    __('PayPal reports this subscription as %s.', 'dono'),
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
        $plan->gateway            = 'paypal';
        $plan->gateway_subscription_id = $subId;
        $plan->gateway_customer_id     = (string) ($sub['subscriber']['payer_id'] ?? '') ?: null;
        $plan->amount_cents       = (int) $donation->amount_cents;
        $plan->currency           = (string) $donation->currency;
        $plan->base_amount_cents  = $donation->base_amount_cents;
        $plan->fx_rate            = $donation->fx_rate;
        $plan->interval_unit      = $unit;
        $plan->interval_count     = $count;
        // Payment counters stay at zero: the opening sale webhook records them,
        // so the plan is never credited for money that has not landed.
        $plan->status             = $status === 'ACTIVE' ? 'active' : 'pending';
        $plan->is_test            = (bool) $donation->is_test;
        $plan->started_at         = $now;
        $plan->next_payment_at    = (string) ($sub['billing_info']['next_billing_time'] ?? '') ?: null;
        $plan->created_at         = $now;
        $plan->updated_at         = $now;
        $plan->save();

        $donation->recurring_plan_id = (int) $plan->id;
        $donation->gateway_intent_id = $subId;
        $donation->save();

        return $plan;
    }

    private function pendingDonation(string $reference): Donation|WP_Error
    {
        $reference = trim($reference);
        $donation  = $reference !== '' ? $this->donations->findByReference($reference) : null;

        if (! $donation || $donation->gateway !== 'paypal') {
            return $this->error('dono_paypal_no_donation', __('We could not find that donation.', 'dono'), 404);
        }
        return $donation;
    }

    private function error(string $code, string $message, int $status): WP_Error
    {
        return new WP_Error($code, $message, ['status' => $status]);
    }
}
