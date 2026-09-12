<?php

declare(strict_types=1);

namespace Gratora\Rest;

use Gratora\Analytics\ErrorLog;
use Gratora\Donations\AntiSpamGuard;
use Gratora\Donations\Donation;
use Gratora\Donations\DonationQueries;
use Gratora\Donations\DonationRepository;
use Gratora\Donations\DonationService;
use Gratora\Gateways\ChargeLock;
use Gratora\Gateways\GatewayManager;
use Gratora\Gateways\PayPal\PayPalAccount;
use Gratora\Gateways\PayPal\PayPalApi;
use Gratora\Gateways\PayPal\PayPalGateway;
use Gratora\Gateways\PayPal\PayPalPlanRecorder;
use Gratora\Gateways\PayPal\PayPalPlanRefused;
use Gratora\Recurring\FrequencyMap;
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
 *  - capture uses the order id Gratora stored at createIntent, never the one the
 *    client posts, so a caller cannot point a capture at a different order;
 *  - the subscription route re-reads the subscription from PayPal and requires
 *    its custom_id to match the donation reference before it records anything.
 *
 * @since 1.0.0
 */
final class PayPalController
{
    private const NS = 'gratora/v1';

    /**
     * Both routes here call PayPal, so a caller who repeats one spends this
     * site's workers and the org's PayPal rate limit rather than their own.
     * Generous enough for a shared address: a donation costs one call, and a
     * NAT'd office or a campus is many donors behind one IP.
     */
    private const CALLBACK_MAX    = 30;
    private const CALLBACK_WINDOW = 900;

    /**
     * States a capture can no longer be part of. Mirrors the rule
     * DonationService::confirm already enforces on the write itself, applied
     * here so a settled donation costs no PayPal call to refuse.
     */
    private const SETTLED = ['paid', 'refunded', 'partial_refund'];

    private ChargeLock $lock;

    public function __construct(
        private DonationRepository $donations,
        private DonationService $donationService,
        private GatewayManager $gateways,
        private PayPalApi $api,
        private PayPalAccount $account,
        private PayPalPlanRecorder $planRecorder,
        private AntiSpamGuard $spam,
    ) {
        $this->lock = new ChargeLock('paypal');
    }

    /** @since 1.0.0 */
    public function registerRoutes(): void
    {
        register_rest_route(self::NS, '/gateways/paypal/capture', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'capture'],
            'permission_callback' => '__return_true',
            'args'                => [
                'reference'    => ['type' => 'string', 'required' => true],
                'status_token' => ['type' => 'string', 'required' => true],
            ],
        ]);

        register_rest_route(self::NS, '/gateways/paypal/subscription', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'recordSubscription'],
            'permission_callback' => '__return_true',
            'args'                => [
                'reference'       => ['type' => 'string', 'required' => true],
                'status_token'    => ['type' => 'string', 'required' => true],
                'subscription_id' => ['type' => 'string', 'required' => true],
            ],
        ]);
    }

    /** @since 1.0.0 */
    public function capture(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($err = $this->spam->consumeIpBudget('gratora_paypal_cb', self::CALLBACK_MAX, self::CALLBACK_WINDOW)) {
            return $err;
        }

        $donation = $this->pendingDonation($request);
        if ($donation instanceof WP_Error) {
            return $donation;
        }

        // Money that has already moved. Re-capturing only asks PayPal to refuse,
        // and the token that reaches this line is held for the life of the
        // donation, so without this one settled donation is an endless supply of
        // outbound calls made on the caller's schedule.
        //
        // Answered as the successful capture answered, not as an error: a double
        // submit and a retried request both land here, and telling a donor whose
        // money moved that their donation failed sends them to give again.
        // A trashed row is answered the same way, and deliberately before the
        // gateway is resolved or the lock is claimed: the lock closes only this
        // site's in-flight window, so what makes the stop durable is refusing
        // to call out about a row an admin has stopped.
        if (in_array($donation->status, self::SETTLED, true) || DonationQueries::isTrashed($donation)) {
            return new WP_REST_Response([
                'status'    => $donation->status,
                'reference' => $donation->reference,
            ], 200);
        }

        $gateway = $this->gateways->get('paypal');
        if (! $gateway instanceof PayPalGateway) {
            return $this->error('gratora_paypal_unavailable', __('PayPal is not available.', 'gratora-donation-platform'), 400);
        }

        // Claimed before PayPal is called, not after. Reading the status and
        // then spending the capture chain on the network leaves a window where
        // a second request reads pending too and captures the same order.
        //
        // Answered as the settled case is answered, for the same reason: the
        // donor whose second tab lost the race has not failed at anything.
        if (! $this->lock->claim($donation)) {
            return new WP_REST_Response([
                'status'    => $donation->status,
                'reference' => $donation->reference,
            ], 200);
        }

        try {
            // confirm() reads the stored gateway_intent_id: the client cannot
            // redirect this at another order.
            $result = $gateway->confirm($donation);

            // A held capture is money PayPal has taken and will settle by webhook.
            // Reporting it as a failure would send the donor back to give again and
            // throw away the capture id that the refund path and the settling
            // webhook both need.
            if (! $result->success && $result->pending) {
                $donation->gateway_txn_id = (string) $result->gateway_txn_id;
                $this->donationService->markProcessing(
                    $donation,
                    'paypal_capture_pending',
                    // Only genuinely absent values are dropped. A bare array_filter
                    // also discards '0' and false, and the key that survives this
                    // is the one an admin reads to find out why PayPal is holding
                    // the money.
                    array_filter((array) $result->metadata, static fn ($v) => $v !== null && $v !== '')
                );

                return new WP_REST_Response([
                    'status'    => 'processing',
                    'reference' => $donation->reference,
                ], 200);
            }

            if (! $result->success) {
                // The reason is PayPal's own wording about an API call, written for
                // whoever integrated it. The donor needs the one thing it never
                // says: whether their money moved. A failed capture usually means
                // it did not, but a capture PayPal took and we failed to read looks
                // the same from here, so the copy points at the receipt instead of
                // promising either way.
                ErrorLog::record('gateway.paypal.capture', (string) $result->error, [
                    'donation_id' => (int) $donation->id,
                    'reference'   => (string) $donation->reference,
                ]);

                return $this->error(
                    'gratora_paypal_capture_failed',
                    __('PayPal could not complete this donation. If any money has left your account we will email your receipt, so please check before donating again.', 'gratora-donation-platform'),
                    400
                );
            }

            $this->donationService->confirm($donation, $result->toArray());
            $fresh = $this->donations->findByReference((string) $donation->reference);

            return new WP_REST_Response([
                'status'    => $fresh?->status ?? 'paid',
                'reference' => $donation->reference,
            ], 200);
        } finally {
            $this->lock->release($donation);
        }
    }

    /**
     * Record the subscription the donor approved. PayPal has already taken the
     * first payment by this point; the opening PAYMENT.SALE.COMPLETED webhook
     * confirms the donation and bumps the counters.
     *
     * @since 1.0.0
     */
    public function recordSubscription(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($err = $this->spam->consumeIpBudget('gratora_paypal_cb', self::CALLBACK_MAX, self::CALLBACK_WINDOW)) {
            return $err;
        }

        $donation = $this->pendingDonation($request);
        if ($donation instanceof WP_Error) {
            return $donation;
        }

        if (! FrequencyMap::isRecurring((string) $donation->frequency)) {
            return $this->error('gratora_paypal_not_recurring', __('That donation is not recurring.', 'gratora-donation-platform'), 400);
        }
        $subId = trim((string) $request->get_param('subscription_id'));
        if ($subId === '') {
            return $this->error('gratora_paypal_bad_subscription', __('Missing subscription id.', 'gratora-donation-platform'), 400);
        }

        $this->account->useTestMode((bool) $donation->is_test);

        try {
            $sub = $this->api->get('/v1/billing/subscriptions/' . rawurlencode($subId));
        } catch (RuntimeException $e) {
            // PayPal has the donor's approval and their first payment by now,
            // so the copy must not read as a failed donation and must not send
            // them round again: BILLING.SUBSCRIPTION.ACTIVATED records the plan
            // without this route.
            ErrorLog::record('gateway.paypal.subscription', $e->getMessage(), [
                'donation_id'     => (int) $donation->id,
                'reference'       => (string) $donation->reference,
                'subscription_id' => $subId,
            ]);

            return $this->error(
                'gratora_paypal_subscription_lookup',
                __('PayPal has your donation, but we could not finish setting up the repeat schedule here. There is no need to donate again: we will email you once it is confirmed.', 'gratora-donation-platform'),
                400
            );
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

    /** @since 1.0.0 */
    /**
     * The donation this request is allowed to act on.
     *
     * References are sequential and printed on receipts, so they identify a
     * donation without proving anything about who is asking. The status token
     * is the per-donation secret the submit response handed to the browser, and
     * both routes here move money.
     *
     * A wrong token answers exactly like a wrong reference: telling a stranger
     * that GRATORA-2026-00007 exists is the same leak either way.
     */
    private function pendingDonation(WP_REST_Request $request): Donation|WP_Error
    {
        $notFound = $this->error(
            'gratora_paypal_no_donation',
            __('We could not find that donation.', 'gratora-donation-platform'),
            404
        );

        $reference = trim((string) $request->get_param('reference'));
        $donation  = $reference !== '' ? $this->donations->findByReference($reference) : null;

        if (! $donation || $donation->gateway !== 'paypal') {
            return $notFound;
        }

        $expected = (string) $donation->status_token_hash;
        $provided = hash('sha256', (string) $request->get_param('status_token'));
        if ($expected === '' || ! hash_equals($expected, $provided)) {
            return $notFound;
        }

        return $donation;
    }

    /** @since 1.0.0 */
    private function error(string $code, string $message, int $status): WP_Error
    {
        return new WP_Error($code, $message, ['status' => $status]);
    }
}
