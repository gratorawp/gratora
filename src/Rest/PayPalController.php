<?php

declare(strict_types=1);

namespace Dono\Rest;

use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Donations\DonationService;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\PayPal\PayPalAccount;
use Dono\Gateways\PayPal\PayPalApi;
use Dono\Gateways\PayPal\PayPalGateway;
use Dono\Gateways\PayPal\PayPalPlanRecorder;
use Dono\Gateways\PayPal\PayPalPlanRefused;
use Dono\Gateways\PayPal\PayPalMoney;
use Dono\Recurring\FrequencyMap;
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
        private PayPalPlanRecorder $planRecorder,
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

        // A held capture is money PayPal has taken and will settle by webhook.
        // Reporting it as a failure sent the donor back to give again, and threw
        // away the capture id that the refund path and the settling webhook
        // both need.
        if (! $result->success && $result->pending) {
            $donation->gateway_txn_id = (string) $result->gateway_txn_id;
            $this->donationService->markProcessing(
                $donation,
                'paypal_capture_pending',
                array_filter((array) $result->metadata)
            );

            return new WP_REST_Response([
                'status'    => 'processing',
                'reference' => $donation->reference,
            ], 200);
        }

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

        // Every check and the write itself live in the recorder, because the
        // webhook handlers have to apply exactly the same ones when they
        // recover a plan this route never got to record.
        try {
            $plan = $this->planRecorder->record($sub + ['id' => $subId]);
        } catch (PayPalPlanRefused $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->status);
        }

        return new WP_REST_Response([
            'status'    => $donation->status,
            'reference' => $donation->reference,
            'plan_id'   => (int) $plan->id,
        ], 200);
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
