<?php

declare(strict_types=1);

namespace Dono\Gateways\Razorpay;

use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Donations\DonationService;
use Dono\Foundation\Time\Clock;
use Dono\Gateways\GatewayConfirmResult;
use Dono\Gateways\GatewayIntentResult;
use Dono\Gateways\PaymentGateway;
use Dono\Gateways\RefundResult;
use Dono\Gateways\SubscriptionAware;
use Dono\Gateways\WebhookOutcome;
use Dono\Gateways\WebhookPaymentGuard;
use Dono\Recurring\FrequencyMap;
use Dono\Recurring\RecurringPlan;
use Dono\Recurring\RecurringPlanRepository;
use RuntimeException;
use WP_REST_Request;

/**
 * Razorpay gateway via Orders (one-time) and Subscriptions (recurring).
 *
 * The donor never leaves the site: Razorpay Checkout opens its own modal
 * covering UPI, netbanking, cards and wallets. Both the Order and the
 * Subscription are created server-side at createIntent, so `gateway_intent_id`
 * exists before the donor pays and the browser is never trusted to say which
 * object it paid.
 *
 * Webhooks are the source of truth for money movement and are idempotent.
 *
 * @version 1.0.0
 */
final class RazorpayGateway implements PaymentGateway, SubscriptionAware
{
    /**
     * Mode of the webhook secret that verified the current delivery. Razorpay
     * events carry no mode of their own, so the verifying secret is the only
     * evidence of which environment an event came from.
     */
    private ?bool $verifiedIsTest = null;

    public function __construct(
        private RazorpayApi $api,
        private RazorpayAccount $account,
        private DonationRepository $donations,
        private DonationService $donationService,
        private RazorpayPlans $plans,
        private RecurringPlanRepository $planRepo,
        private Clock $clock,
    ) {
    }

    public function id(): string
    {
        return 'razorpay';
    }

    public function label(): string
    {
        return __('Razorpay', 'dono');
    }

    public function description(): string
    {
        return __('Pay by UPI, card, netbanking or wallet.', 'dono');
    }

    public function frequencies(): array
    {
        return ['one_time', 'recurring'];
    }

    public function paymentMethods(): array
    {
        return ['upi', 'card', 'netbanking', 'wallet', 'emi'];
    }

    /**
     * Wildcard: the merchant is Indian, but donors need not be. An NRI paying an
     * INR order from abroad is an ordinary case, and the currency check below is
     * the constraint that actually matters.
     */
    public function countries(): array
    {
        return ['*'];
    }

    /**
     * INR only. Razorpay accounts are INR by default and reject other
     * currencies unless international acceptance is separately enabled, so
     * offering anything else would put the donor in front of a payment that
     * fails at the last step.
     */
    public function currencies(): array
    {
        return ['INR'];
    }

    public function canCharge(): bool
    {
        return $this->account->canCharge();
    }

    /**
     * One-time creates an Order; recurring provisions a Plan and opens the
     * Subscription. Either way the id the donor will pay against exists before
     * the browser is involved.
     */
    public function createIntent(Donation $donation): GatewayIntentResult
    {
        $this->account->useTestMode((bool) $donation->is_test);

        if (FrequencyMap::isRecurring((string) $donation->frequency)) {
            return $this->createSubscriptionIntent($donation);
        }

        $currency = strtoupper((string) $donation->currency);

        $order = $this->api->post('/v1/orders', [
            'amount'   => RazorpayMoney::toAmount((int) $donation->amount_cents, $currency),
            'currency' => $currency,
            // Razorpay caps receipt at 40 characters.
            'receipt'  => substr((string) $donation->reference, 0, 40),
            'notes'    => ['dono_reference' => (string) $donation->reference],
        ]);

        $orderId = (string) ($order['id'] ?? '');
        if ($orderId === '') {
            throw new RuntimeException('Razorpay did not return an order id.');
        }

        $donation->gateway_account_id = $this->account->keyIdFor((bool) $donation->is_test);

        return new GatewayIntentResult(
            intent_id: $orderId,
            metadata: [
                'razorpay_mode'     => $donation->is_test ? 'test' : 'live',
                'razorpay_kind'     => 'order',
                'razorpay_order_id' => $orderId,
            ],
        );
    }

    private function createSubscriptionIntent(Donation $donation): GatewayIntentResult
    {
        $currency = strtoupper((string) $donation->currency);
        [$period, $interval] = $this->periodFor((string) $donation->frequency);

        $planId = $this->plans->resolvePlan(
            (bool) $donation->is_test,
            (int) $donation->amount_cents,
            $currency,
            $period,
            $interval
        );

        // Razorpay requires a finite total_count, so an open-ended donation gets
        // ten years of cycles. A donor who is still giving then can renew, which
        // beats the alternative of a subscription that cannot be created at all.
        $subscription = $this->api->post('/v1/subscriptions', [
            'plan_id'         => $planId,
            'total_count'     => $this->totalCountFor($period, $interval),
            'customer_notify' => 1,
            'notes'           => ['dono_reference' => (string) $donation->reference],
        ]);

        $subId = (string) ($subscription['id'] ?? '');
        if ($subId === '') {
            throw new RuntimeException('Razorpay did not return a subscription id.');
        }

        $donation->gateway_account_id = $this->account->keyIdFor((bool) $donation->is_test);

        return new GatewayIntentResult(
            intent_id: $subId,
            metadata: [
                'razorpay_mode'            => $donation->is_test ? 'test' : 'live',
                'razorpay_kind'            => 'subscription',
                'razorpay_subscription_id' => $subId,
                'razorpay_plan_id'         => $planId,
            ],
        );
    }

    /**
     * Confirm a one-time payment the donor just made in Checkout.
     *
     * `$payload` carries what Checkout handed the browser: the payment id and
     * the signature. The order id is deliberately not taken from the payload,
     * it is read from the donation, so a caller cannot present a signature for
     * some other order they legitimately paid.
     */
    public function confirm(Donation $donation, array $payload = []): GatewayConfirmResult
    {
        $orderId = (string) ($donation->gateway_intent_id ?? '');
        if ($orderId === '') {
            return new GatewayConfirmResult(success: false, error: 'No Razorpay order on this donation.');
        }

        $paymentId = trim((string) ($payload['payment_id'] ?? ''));
        $signature = trim((string) ($payload['signature'] ?? ''));
        if ($paymentId === '' || $signature === '') {
            return new GatewayConfirmResult(success: false, error: 'Missing Razorpay payment id or signature.');
        }

        $test = (bool) $donation->is_test;
        $this->account->useTestMode($test);

        $expected = RazorpaySignature::forOrder($orderId, $paymentId, $this->account->keySecretFor($test));
        if (! RazorpaySignature::matches($expected, $signature)) {
            return new GatewayConfirmResult(success: false, error: 'Razorpay signature did not verify.');
        }

        try {
            $payment = $this->api->get('/v1/payments/' . rawurlencode($paymentId));
        } catch (RuntimeException $e) {
            return new GatewayConfirmResult(success: false, error: $e->getMessage());
        }

        // The signature proves the pair was issued by Razorpay; this proves the
        // payment really belongs to the order stored on this donation.
        if ((string) ($payment['order_id'] ?? '') !== $orderId) {
            return new GatewayConfirmResult(success: false, error: 'That Razorpay payment belongs to another order.');
        }

        $status = (string) ($payment['status'] ?? '');

        if ($status === 'failed') {
            return new GatewayConfirmResult(
                success: false,
                error: (string) ($payment['error_description'] ?? 'Razorpay reports this payment as failed.'),
            );
        }

        // An authorised payment is money held, not taken. Capturing is what
        // actually moves it, and Razorpay auto-voids anything left authorised.
        if ($status === 'authorized') {
            try {
                $payment = $this->api->post('/v1/payments/' . rawurlencode($paymentId) . '/capture', [
                    'amount'   => (int) ($payment['amount'] ?? 0),
                    'currency' => strtoupper((string) ($payment['currency'] ?? $donation->currency)),
                ]);
            } catch (RuntimeException $e) {
                // Already captured (a racing webhook, or a retried request) is
                // success: re-read rather than fail a donation that was paid.
                if (! $this->isAlreadyCaptured($e)) {
                    return new GatewayConfirmResult(success: false, error: $e->getMessage());
                }
                try {
                    $payment = $this->api->get('/v1/payments/' . rawurlencode($paymentId));
                } catch (RuntimeException $inner) {
                    return new GatewayConfirmResult(success: false, error: $inner->getMessage());
                }
            }
        }

        return $this->confirmResultFromPayment($payment);
    }

    /** @param array<string,mixed> $payment */
    private function confirmResultFromPayment(array $payment): GatewayConfirmResult
    {
        $status = (string) ($payment['status'] ?? '');
        if (! in_array($status, ['captured', 'refunded'], true)) {
            return new GatewayConfirmResult(
                success: false,
                error: "Razorpay payment status is {$status}.",
            );
        }

        $currency = strtoupper((string) ($payment['currency'] ?? 'INR'));
        $fee      = $payment['fee'] ?? null;

        $card = is_array($payment['card'] ?? null) ? $payment['card'] : [];

        return new GatewayConfirmResult(
            success: true,
            gateway_txn_id: (string) ($payment['id'] ?? ''),
            payment_method: (string) ($payment['method'] ?? 'razorpay'),
            payment_method_brand: (string) ($card['network'] ?? '') ?: null,
            payment_method_last4: (string) ($card['last4'] ?? '') ?: null,
            // Razorpay's `fee` is inclusive of `tax`, so it is the whole cost.
            fee_cents: $fee !== null ? RazorpayMoney::toStoredCents((int) $fee, $currency) : null,
            metadata: [
                'razorpay_payment_id' => (string) ($payment['id'] ?? ''),
                'razorpay_order_id'   => (string) ($payment['order_id'] ?? ''),
                'razorpay_method'     => (string) ($payment['method'] ?? ''),
            ],
        );
    }

    /**
     * Record the subscription the donor just authorised in Checkout. The
     * subscription id is read from the donation, not the payload, for the same
     * reason confirm() reads the order id from there.
     *
     * @param array{payment_id?:string,signature?:string} $payload
     */
    public function verifySubscriptionPayload(Donation $donation, array $payload): bool
    {
        $subId = (string) ($donation->gateway_intent_id ?? '');
        $paymentId = trim((string) ($payload['payment_id'] ?? ''));
        $signature = trim((string) ($payload['signature'] ?? ''));

        if ($subId === '' || $paymentId === '' || $signature === '') {
            return false;
        }

        $test = (bool) $donation->is_test;
        $this->account->useTestMode($test);

        return RazorpaySignature::matches(
            RazorpaySignature::forSubscription($paymentId, $subId, $this->account->keySecretFor($test)),
            $signature
        );
    }

    /** @return array<string,mixed> the Razorpay subscription object. */
    public function fetchSubscription(bool $test, string $subscriptionId): array
    {
        $this->account->useTestMode($test);
        return $this->api->get('/v1/subscriptions/' . rawurlencode($subscriptionId));
    }

    public function handleWebhook(WP_REST_Request $request): WebhookOutcome
    {
        $raw       = (string) $request->get_body();
        $signature = (string) $request->get_header('x_razorpay_signature');
        // Razorpay puts the event id in a header, not the body, and it is what
        // makes redelivery detectable.
        $eventId = (string) $request->get_header('x_razorpay_event_id');

        $event = json_decode($raw, true);
        if (! is_array($event) || ! isset($event['event'])) {
            return new WebhookOutcome(
                signature_ok: false,
                error: 'Malformed Razorpay event payload.',
                http_status: 400,
            );
        }

        // Test and live deliveries hit the same endpoint with different secrets,
        // and the body does not say which mode it is, so try both. Live first:
        // real money is the case that must not be mistaken for the other.
        $verified = false;
        foreach ([false, true] as $test) {
            $secret = $this->account->webhookSecret($test);
            if ($secret === '') continue;
            if (RazorpaySignature::matches(RazorpaySignature::forWebhook($raw, $secret), $signature)) {
                $this->account->useTestMode($test);
                $this->verifiedIsTest = $test;
                $verified = true;
                break;
            }
        }

        if (! $verified) {
            return WebhookOutcome::badSignature();
        }

        $type    = (string) $event['event'];
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];

        if ($eventId === '') {
            // Fall back to something stable per event so the log still dedups.
            $eventId = $type . '_' . md5($raw);
        }

        return match ($type) {
            'payment.captured'       => $this->handlePaymentCaptured($eventId, $type, $this->entity($payload, 'payment')),
            'payment.failed'         => $this->handlePaymentFailed($eventId, $type, $this->entity($payload, 'payment')),
            'refund.processed',
            'refund.created'         => $this->handleRefund($eventId, $type, $this->entity($payload, 'refund')),
            'subscription.charged'   => $this->handleSubscriptionCharged(
                $eventId,
                $type,
                $this->entity($payload, 'subscription'),
                $this->entity($payload, 'payment')
            ),
            'subscription.activated' => $this->handleSubscriptionActivated($eventId, $type, $this->entity($payload, 'subscription')),
            'subscription.cancelled',
            'subscription.completed',
            'subscription.halted'    => $this->handleSubscriptionEnded($eventId, $type, $this->entity($payload, 'subscription')),
            default => new WebhookOutcome(
                signature_ok: true,
                external_id: $eventId,
                event_type: $type,
                handled: false,
            ),
        };
    }

    /**
     * Razorpay wraps every entity as payload.<name>.entity.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function entity(array $payload, string $name): array
    {
        $entity = $payload[$name]['entity'] ?? null;
        return is_array($entity) ? $entity : [];
    }

    /** @param array<string,mixed> $payment */
    private function handlePaymentCaptured(string $eventId, string $type, array $payment): WebhookOutcome
    {
        // A subscription's own charges arrive as subscription.charged too, and
        // that handler owns the renewal bookkeeping. Handling them here as well
        // would confirm the same money down two different paths.
        if ((string) ($payment['invoice_id'] ?? '') !== '' || (string) ($payment['subscription_id'] ?? '') !== '') {
            return new WebhookOutcome(
                signature_ok: true,
                external_id: $eventId,
                event_type: $type,
                handled: false,
            );
        }

        $donation = $this->donationForPayment($payment);
        if (! $donation) {
            return $this->unmatched($eventId, $type, 'payment');
        }

        // A verified signature proves Razorpay sent this, not that it is about
        // this donation for this amount in this mode.
        $currency = strtoupper((string) ($payment['currency'] ?? ''));
        $refusal  = WebhookPaymentGuard::refuse(
            $donation,
            $this->id(),
            $this->verifiedIsTest,
            isset($payment['amount'])
                ? RazorpayMoney::toStoredCents((int) $payment['amount'], $currency ?: (string) $donation->currency)
                : null,
            $currency !== '' ? $currency : null,
        );
        if ($refusal !== null) {
            return $this->refused($eventId, $type, $refusal);
        }

        // Idempotent: DonationService::confirm() no-ops on an already-paid row.
        $this->donationService->confirm($donation, $this->confirmResultFromPayment($payment)->toArray());

        return new WebhookOutcome(
            signature_ok: true,
            external_id: $eventId,
            event_type: $type,
            handled: true,
        );
    }

    /** @param array<string,mixed> $payment */
    private function handlePaymentFailed(string $eventId, string $type, array $payment): WebhookOutcome
    {
        $donation = $this->donationForPayment($payment);
        if (! $donation) {
            return $this->unmatched($eventId, $type, 'payment');
        }

        $this->donationService->markFailed(
            $donation,
            (string) ($payment['error_description'] ?? 'Razorpay declined the payment.')
        );

        return new WebhookOutcome(
            signature_ok: true,
            external_id: $eventId,
            event_type: $type,
            handled: true,
        );
    }

    /**
     * A refund issued from the Razorpay dashboard, recorded so Dono's totals
     * match without an admin re-entering it.
     *
     * @param array<string,mixed> $refund
     */
    private function handleRefund(string $eventId, string $type, array $refund): WebhookOutcome
    {
        $paymentId = (string) ($refund['payment_id'] ?? '');
        $donation  = $paymentId !== '' ? $this->donations->findByGatewayTxn($this->id(), $paymentId) : null;

        if (! $donation) {
            return $this->unmatched($eventId, $type, 'refund');
        }

        $currency = strtoupper((string) ($refund['currency'] ?? $donation->currency));

        $this->donationService->recordExternalRefund(
            $donation,
            RazorpayMoney::toStoredCents((int) ($refund['amount'] ?? 0), $currency),
            (string) ($refund['id'] ?? ''),
            'Razorpay dashboard refund'
        );

        return new WebhookOutcome(
            signature_ok: true,
            external_id: $eventId,
            event_type: $type,
            handled: true,
        );
    }

    /** @param array<string,mixed> $sub */
    private function handleSubscriptionActivated(string $eventId, string $type, array $sub): WebhookOutcome
    {
        $plan = $this->planRepo->findBySubscriptionId($this->id(), (string) ($sub['id'] ?? ''));
        if ($plan && $plan->status !== 'active') {
            $plan->status     = 'active';
            $plan->updated_at = $this->now();
            $plan->save();
        }

        return new WebhookOutcome(
            signature_ok: true,
            external_id: $eventId,
            event_type: $type,
            handled: $plan !== null,
        );
    }

    /** @param array<string,mixed> $sub */
    private function handleSubscriptionEnded(string $eventId, string $type, array $sub): WebhookOutcome
    {
        $plan = $this->planRepo->findBySubscriptionId($this->id(), (string) ($sub['id'] ?? ''));
        if (! $plan) {
            return $this->unmatched($eventId, $type, 'subscription');
        }

        $this->planRepo->markCancelled($plan, $this->now(), match ($type) {
            'subscription.completed' => 'Subscription completed at Razorpay',
            'subscription.halted'    => 'Halted at Razorpay after repeated payment failures',
            default                  => 'Cancelled at Razorpay',
        });

        return new WebhookOutcome(
            signature_ok: true,
            external_id: $eventId,
            event_type: $type,
            handled: true,
        );
    }

    /**
     * A charge on a subscription. Razorpay fires this for the very first
     * payment too, so the opening one belongs to the signup donation already on
     * file: treating it as a renewal would bank the same money twice.
     *
     * @param array<string,mixed> $sub
     * @param array<string,mixed> $payment
     */
    private function handleSubscriptionCharged(string $eventId, string $type, array $sub, array $payment): WebhookOutcome
    {
        $subId = (string) ($sub['id'] ?? '');
        if ($subId === '') {
            return new WebhookOutcome(
                signature_ok: true,
                external_id: $eventId,
                event_type: $type,
                handled: false,
                error: 'Razorpay subscription event has no subscription id',
            );
        }

        $plan = $this->planRepo->findBySubscriptionId($this->id(), $subId);
        if (! $plan) {
            return $this->unmatched($eventId, $type, 'subscription');
        }

        $paymentId = (string) ($payment['id'] ?? '');
        $currency  = strtoupper((string) ($payment['currency'] ?? $plan->currency));
        $amount    = RazorpayMoney::toStoredCents((int) ($payment['amount'] ?? 0), $currency);

        if ($paymentId === '' || $amount <= 0) {
            return new WebhookOutcome(
                signature_ok: true,
                external_id: $eventId,
                event_type: $type,
                handled: false,
                error: 'Razorpay subscription charge missing payment id or amount',
            );
        }

        $confirmResult = [
            'gateway_txn_id' => $paymentId,
            'payment_method' => (string) ($payment['method'] ?? 'razorpay'),
            'metadata'       => ['razorpay_subscription_id' => $subId],
        ];

        $signup = Donation::query()
            ->where('recurring_plan_id', (int) $plan->id)
            ->where('status', 'pending')
            ->get();

        if ($signup instanceof Donation) {
            $refusal = WebhookPaymentGuard::refuse(
                $signup,
                $this->id(),
                $this->verifiedIsTest,
                $amount,
                $currency,
            );
            if ($refusal !== null) {
                return $this->refused($eventId, $type, $refusal);
            }

            $this->donationService->confirm($signup, $confirmResult);

            $fresh = $this->planRepo->findBySubscriptionId($this->id(), $subId);
            if ($fresh) {
                $this->planRepo->recordPayment($fresh, $amount, $this->now());
            }

            return new WebhookOutcome(
                signature_ok: true,
                external_id: $eventId,
                event_type: $type,
                handled: true,
            );
        }

        $renewal = $this->donationService->createRenewal(
            $plan,
            $amount,
            $currency,
            $this->id(),
            $paymentId,
            $confirmResult,
        );

        // Only a genuinely new renewal bumps the counters: Razorpay redelivers
        // events, and recordPayment increments unconditionally, so a replay
        // would otherwise permanently inflate payments_count.
        if ($renewal['created']) {
            $fresh = $this->planRepo->findBySubscriptionId($this->id(), $subId);
            if ($fresh) {
                $this->planRepo->recordPayment($fresh, $amount, $this->now());
            }
        }

        return new WebhookOutcome(
            signature_ok: true,
            external_id: $eventId,
            event_type: $type,
            handled: true,
        );
    }

    public function refund(Donation $donation, int $amountCents, ?string $reason = null): RefundResult
    {
        $paymentId = (string) ($donation->gateway_txn_id ?? '');
        if ($paymentId === '') {
            return RefundResult::failure('This donation has no Razorpay payment to refund.');
        }

        $this->account->useTestMode((bool) $donation->is_test);
        $currency = strtoupper((string) $donation->currency);

        try {
            $refund = $this->api->post('/v1/payments/' . rawurlencode($paymentId) . '/refund', [
                'amount' => RazorpayMoney::toAmount($amountCents, $currency),
                'speed'  => 'normal',
                'notes'  => ['reason' => $reason !== null ? substr($reason, 0, 255) : 'Refunded in Dono'],
            ]);
        } catch (RuntimeException $e) {
            return RefundResult::failure($e->getMessage());
        }

        $status = (string) ($refund['status'] ?? '');
        if (! in_array($status, ['processed', 'pending'], true)) {
            return RefundResult::failure("Razorpay refund status is {$status}.");
        }

        $echoed = $refund['amount'] ?? null;

        return new RefundResult(
            success: true,
            gateway_refund_id: (string) ($refund['id'] ?? ''),
            amount_cents: $echoed !== null ? RazorpayMoney::toStoredCents((int) $echoed, $currency) : $amountCents,
            metadata: ['razorpay_refund_status' => $status],
        );
    }

    public function cancelSubscription(RecurringPlan $plan, ?string $reason = null): void
    {
        $this->account->useTestMode((bool) $plan->is_test);
        try {
            $this->api->post(
                '/v1/subscriptions/' . rawurlencode($plan->gateway_subscription_id) . '/cancel',
                ['cancel_at_cycle_end' => 0]
            );
        } catch (RuntimeException $e) {
            if (! $this->isAlreadyInThatState($e)) {
                throw $e;
            }
        }
    }

    public function pauseSubscription(RecurringPlan $plan, ?string $resumesAt = null): void
    {
        $this->account->useTestMode((bool) $plan->is_test);
        try {
            // Razorpay pauses indefinitely and has no scheduled resume, so a
            // resume date cannot be honoured here; resumeSubscription is the
            // only way back, which is what the portal calls.
            $this->api->post(
                '/v1/subscriptions/' . rawurlencode($plan->gateway_subscription_id) . '/pause',
                ['pause_at' => 'now']
            );
        } catch (RuntimeException $e) {
            if (! $this->isAlreadyInThatState($e)) {
                throw $e;
            }
        }
    }

    public function resumeSubscription(RecurringPlan $plan): void
    {
        $this->account->useTestMode((bool) $plan->is_test);
        try {
            $this->api->post(
                '/v1/subscriptions/' . rawurlencode($plan->gateway_subscription_id) . '/resume',
                ['resume_at' => 'now']
            );
        } catch (RuntimeException $e) {
            if (! $this->isAlreadyInThatState($e)) {
                throw $e;
            }
        }
    }

    /**
     * The amount lives on the plan, so changing it means moving the
     * subscription onto a plan at the new amount (provisioned on demand and
     * reused), same as PayPal.
     */
    public function updateSubscriptionAmount(RecurringPlan $plan, int $amountCents): void
    {
        $this->account->useTestMode((bool) $plan->is_test);

        [$period, $interval] = $this->periodForInterval(
            (string) $plan->interval_unit,
            (int) $plan->interval_count
        );

        $planId = $this->plans->resolvePlan(
            (bool) $plan->is_test,
            $amountCents,
            strtoupper((string) $plan->currency),
            $period,
            $interval
        );

        $this->api->patch('/v1/subscriptions/' . rawurlencode($plan->gateway_subscription_id), [
            'plan_id'            => $planId,
            'schedule_change_at' => 'cycle_end',
        ]);
    }

    /**
     * Dono frequency -> Razorpay period + interval. FrequencyMap is the single
     * source for the interval maths; this only renames the unit to Razorpay's
     * vocabulary.
     *
     * @return array{0:string,1:int}
     */
    private function periodFor(string $frequency): array
    {
        [$unit, $count] = FrequencyMap::toStripe($frequency);
        return $this->periodForInterval($unit, $count);
    }

    /** @return array{0:string,1:int} */
    private function periodForInterval(string $unit, int $count): array
    {
        $period = match ($unit) {
            'day'   => 'daily',
            'week'  => 'weekly',
            'year'  => 'yearly',
            default => 'monthly',
        };

        return [$period, max(1, $count)];
    }

    /** Ten years of billing cycles, since Razorpay will not take an open end. */
    private function totalCountFor(string $period, int $interval): int
    {
        $perYear = match ($period) {
            'daily'  => 365,
            'weekly' => 52,
            'yearly' => 1,
            default  => 12,
        };

        return max(1, (int) floor(($perYear * 10) / max(1, $interval)));
    }

    /** Clock returns a DateTimeImmutable; the models store MySQL datetimes. */
    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    /** @param array<string,mixed> $payment */
    private function donationForPayment(array $payment): ?Donation
    {
        $reference = (string) ($payment['notes']['dono_reference'] ?? '');
        if ($reference !== '') {
            $found = $this->donations->findByReference($reference);
            if ($found) return $found;
        }

        $orderId = (string) ($payment['order_id'] ?? '');
        return $orderId !== ''
            ? $this->donations->findByGatewayIntent($this->id(), $orderId)
            : null;
    }

    /**
     * A genuinely Razorpay-signed event that must not touch this donation. 200,
     * not 5xx: retrying will not make it acceptable.
     */
    private function refused(string $eventId, string $type, string $reason): WebhookOutcome
    {
        return new WebhookOutcome(
            signature_ok: true,
            external_id:  $eventId,
            event_type:   $type,
            handled:      false,
            error:        'Refused: ' . $reason,
            http_status:  200,
        );
    }

    private function unmatched(string $eventId, string $type, string $what): WebhookOutcome
    {
        // 200, not 5xx: the event is valid, it just is not ours. A 5xx would
        // make Razorpay retry it for hours.
        return new WebhookOutcome(
            signature_ok: true,
            external_id: $eventId,
            event_type: $type,
            handled: false,
            error: "No donation matched this Razorpay {$what}",
            http_status: 200,
        );
    }

    private function isAlreadyCaptured(RuntimeException $e): bool
    {
        $msg = strtolower($e->getMessage());
        return str_contains($msg, 'already been captured') || str_contains($msg, 'already captured');
    }

    /** Razorpay's "you cannot do that from the current state" family. */
    private function isAlreadyInThatState(RuntimeException $e): bool
    {
        $msg = strtolower($e->getMessage());
        foreach (['already', 'not in a valid state', 'invalid state', 'cannot be cancelled'] as $needle) {
            if (str_contains($msg, $needle)) return true;
        }
        return false;
    }
}
