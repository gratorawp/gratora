<?php

declare(strict_types=1);

namespace Dono\Gateways\PayPal;

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
use Dono\Gateways\SubscriptionChangeNeedsApproval;
use Dono\Gateways\WebhookPaymentGuard;
use Dono\Recurring\FrequencyMap;
use Dono\Recurring\RecurringPlan;
use Dono\Recurring\RecurringPlanRepository;
use RuntimeException;
use WP_REST_Request;

/**
 * PayPal gateway via Orders v2 (one-time) and Subscriptions v1 (recurring).
 *
 * The donor never leaves the site: the JS SDK renders PayPal's buttons and
 * opens its own popup. For one-time donations Dono creates the Order up front
 * so `gateway_intent_id` exists before the donor approves; the browser then
 * approves it and Dono captures server-side. For recurring, Dono provisions a
 * Product + Plan and the button creates the Subscription against that plan.
 *
 * Webhooks are the source of truth for money movement and are idempotent, so a
 * capture that also arrives by webhook is confirmed only once.
 *
 * @version 1.0.0
 */
final class PayPalGateway implements PaymentGateway, SubscriptionAware
{
    /**
     * Mode of the credentials that verified the current webhook. Set once per
     * delivery in handleWebhook; null outside a webhook request.
     */
    private ?bool $verifiedIsTest = null;

    public function __construct(
        private PayPalApi $api,
        private PayPalAccount $account,
        private DonationRepository $donations,
        private DonationService $donationService,
        private PayPalPlans $plans,
        private RecurringPlanRepository $planRepo,
        private Clock $clock,
    ) {
    }

    public function id(): string
    {
        return 'paypal';
    }

    public function label(): string
    {
        return __('PayPal', 'dono');
    }

    public function description(): string
    {
        return __('Pay with your PayPal balance, a bank account, or a card. No PayPal account required.', 'dono');
    }

    public function frequencies(): array
    {
        return ['one_time', 'recurring'];
    }

    public function paymentMethods(): array
    {
        return ['paypal', 'venmo', 'paylater', 'card'];
    }

    public function countries(): array
    {
        // Wildcard: defer to PayPal's own country rules.
        return ['*'];
    }

    /**
     * PayPal settles in a fixed set of currencies and, unlike Stripe, rejects
     * anything outside it outright. Listing them keeps the donor form from
     * offering PayPal for a currency the order would fail on.
     */
    public function currencies(): array
    {
        return [
            'AUD', 'BRL', 'CAD', 'CHF', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD', 'HUF',
            'ILS', 'JPY', 'MXN', 'MYR', 'NOK', 'NZD', 'PHP', 'PLN', 'SEK', 'SGD',
            'THB', 'TWD', 'USD',
        ];
    }

    public function canCharge(): bool
    {
        return $this->account->canCharge();
    }

    /**
     * One-time: create the Order now so the donation carries a gateway id
     * before the donor approves. Recurring: no order exists, so hand the
     * browser the plan id to open a Subscription against.
     */
    public function createIntent(Donation $donation): GatewayIntentResult
    {
        $this->account->useTestMode((bool) $donation->is_test);

        if (FrequencyMap::isRecurring($donation->frequency)) {
            return $this->createSubscriptionIntent($donation);
        }

        $currency = strtoupper((string) $donation->currency);
        $order = $this->api->post('/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                // custom_id rides the whole lifecycle (capture, refund, webhook)
                // so an event can always be matched back to the donation.
                'custom_id'   => (string) $donation->reference,
                'description' => 'Donation ' . $donation->reference,
                'amount'      => [
                    'currency_code' => $currency,
                    'value'         => PayPalMoney::toValue((int) $donation->amount_cents, $currency),
                ],
            ]],
            'payment_source' => [
                'paypal' => [
                    'experience_context' => [
                        'shipping_preference' => 'NO_SHIPPING',
                        'user_action'         => 'PAY_NOW',
                    ],
                ],
            ],
        ], ['PayPal-Request-Id' => 'dono_order_' . $donation->reference]);

        $orderId = (string) ($order['id'] ?? '');
        if ($orderId === '') {
            throw new RuntimeException('PayPal did not return an order id.');
        }

        $donation->gateway_account_id = $this->account->clientIdFor((bool) $donation->is_test);

        return new GatewayIntentResult(
            intent_id: $orderId,
            metadata: [
                'paypal_mode'     => $donation->is_test ? 'sandbox' : 'live',
                'paypal_kind'     => 'order',
                'paypal_order_id' => $orderId,
            ],
        );
    }

    /**
     * Recurring needs a Plan, not an Order. The plan is keyed on amount plus
     * interval and reused across donors, so a repeat monthly amount does not
     * create a second plan on the PayPal account.
     */
    private function createSubscriptionIntent(Donation $donation): GatewayIntentResult
    {
        $currency = strtoupper((string) $donation->currency);
        [$unit, $count] = $this->intervalFor((string) $donation->frequency);

        $planId = $this->plans->resolvePlan(
            (bool) $donation->is_test,
            (int) $donation->amount_cents,
            $currency,
            $unit,
            $count
        );

        return new GatewayIntentResult(
            intent_id: 'pending_subscription_' . $donation->reference,
            metadata: [
                'paypal_mode'    => $donation->is_test ? 'sandbox' : 'live',
                'paypal_kind'    => 'subscription',
                'paypal_plan_id' => $planId,
            ],
        );
    }

    /**
     * Capture an approved order. Called from the public capture route once the
     * donor finishes in the PayPal popup, and safe to re-enter: PayPal reports
     * ORDER_ALREADY_CAPTURED, which we treat as success and let the stored
     * capture stand.
     */
    public function confirm(Donation $donation, array $payload = []): GatewayConfirmResult
    {
        if (! $donation->gateway_intent_id) {
            return new GatewayConfirmResult(success: false, error: 'No PayPal order on this donation.');
        }

        $this->account->useTestMode((bool) $donation->is_test);
        $orderId = (string) $donation->gateway_intent_id;

        try {
            $result = $this->api->post(
                '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture',
                [],
                ['PayPal-Request-Id' => 'dono_capture_' . $donation->reference]
            );
        } catch (RuntimeException $e) {
            if (! $this->isAlreadyCaptured($e)) {
                return new GatewayConfirmResult(success: false, error: $e->getMessage());
            }
            // Already captured: read the order back so the txn id is recorded.
            try {
                $result = $this->api->get('/v2/checkout/orders/' . rawurlencode($orderId));
            } catch (RuntimeException $inner) {
                return new GatewayConfirmResult(success: false, error: $inner->getMessage());
            }
        }

        return $this->buildConfirmResultFromOrder($result);
    }

    /** @param array<string,mixed> $order */
    private function buildConfirmResultFromOrder(array $order): GatewayConfirmResult
    {
        $status = (string) ($order['status'] ?? '');
        $capture = $order['purchase_units'][0]['payments']['captures'][0] ?? null;

        if (! is_array($capture)) {
            return new GatewayConfirmResult(
                success: false,
                error: "PayPal order is {$status} with no capture.",
            );
        }

        $captureStatus = (string) ($capture['status'] ?? '');
        if (! in_array($captureStatus, ['COMPLETED', 'PENDING'], true)) {
            return new GatewayConfirmResult(
                success: false,
                error: "PayPal capture status is {$captureStatus}.",
            );
        }

        // PayPal reports its own fee on the capture, so the net is knowable.
        $fee = $capture['seller_receivable_breakdown']['paypal_fee']['value'] ?? null;
        $feeCurrency = (string) ($capture['seller_receivable_breakdown']['paypal_fee']['currency_code'] ?? '');

        return new GatewayConfirmResult(
            success: $captureStatus === 'COMPLETED',
            gateway_txn_id: (string) ($capture['id'] ?? ''),
            payment_method: 'paypal',
            fee_cents: $fee !== null ? PayPalMoney::toStoredCents((string) $fee, $feeCurrency) : null,
            error: $captureStatus === 'COMPLETED' ? null : 'PayPal capture is pending review.',
            metadata: [
                'paypal_order_id'   => (string) ($order['id'] ?? ''),
                'paypal_capture_id' => (string) ($capture['id'] ?? ''),
                'payer_email'       => (string) ($order['payer']['email_address'] ?? ''),
            ],
        );
    }

    public function handleWebhook(WP_REST_Request $request): WebhookOutcome
    {
        $raw = (string) $request->get_body();

        // PayPal's transmission headers, normalised: WP lowercases and
        // underscores incoming header names.
        $headers = [
            'transmission_id'   => (string) $request->get_header('paypal_transmission_id'),
            'transmission_time' => (string) $request->get_header('paypal_transmission_time'),
            'transmission_sig'  => (string) $request->get_header('paypal_transmission_sig'),
            'cert_url'          => (string) $request->get_header('paypal_cert_url'),
            'auth_algo'         => (string) $request->get_header('paypal_auth_algo'),
        ];

        $event = json_decode($raw, true);
        if (! is_array($event) || ! isset($event['event_type'], $event['id'])) {
            return new WebhookOutcome(
                signature_ok: false,
                error: 'Malformed PayPal event payload.',
                http_status: 400,
            );
        }

        // Verification is an API call, so the mode must be set first. PayPal
        // does not stamp livemode on the event: the sandbox and live webhooks
        // are separate endpoints with separate ids, so try the mode whose
        // webhook id matches, preferring live.
        $verified = false;
        foreach ([false, true] as $test) {
            if ($this->account->webhookId($test) === '') continue;
            $this->account->useTestMode($test);
            if ($this->api->verifyWebhookSignature($headers, $raw)) {
                // Which mode verified is what later stops a sandbox event
                // confirming a live donation.
                $this->verifiedIsTest = $test;
                $verified = true;
                break;
            }
        }

        if (! $verified) {
            return new WebhookOutcome(signature_ok: false, http_status: 400);
        }

        $eventId  = (string) $event['id'];
        $type     = (string) $event['event_type'];
        $resource = (array) ($event['resource'] ?? []);

        return match ($type) {
            'PAYMENT.CAPTURE.COMPLETED' => $this->handleCaptureCompleted($eventId, $type, $resource),
            'PAYMENT.CAPTURE.DENIED',
            'PAYMENT.CAPTURE.DECLINED'  => $this->handleCaptureDenied($eventId, $type, $resource),
            'PAYMENT.CAPTURE.REFUNDED'  => $this->handleCaptureRefunded($eventId, $type, $resource),
            'BILLING.SUBSCRIPTION.ACTIVATED' => $this->handleSubscriptionActivated($eventId, $type, $resource),
            'BILLING.SUBSCRIPTION.CANCELLED',
            'BILLING.SUBSCRIPTION.EXPIRED'   => $this->handleSubscriptionEnded($eventId, $type, $resource),
            'PAYMENT.SALE.COMPLETED'    => $this->handleRenewalPaid($eventId, $type, $resource),
            default => new WebhookOutcome(
                signature_ok: true,
                external_id: $eventId,
                event_type: $type,
                handled: false,
            ),
        };
    }

    /** @param array<string,mixed> $capture */
    private function handleCaptureCompleted(string $eventId, string $type, array $capture): WebhookOutcome
    {
        $donation = $this->donationForCapture($capture);
        if (! $donation) {
            return $this->unmatched($eventId, $type, 'capture');
        }

        // A PayPal-signed event proves PayPal sent it, not that it is about this
        // donation for this amount. The browser picks custom_id and the amount
        // when it creates the order, so without this a $0.01 capture confirmed a
        // $10,000 donation.
        $currency = strtoupper((string) ($capture['amount']['currency_code'] ?? ''));
        $refusal  = WebhookPaymentGuard::refuse(
            $donation,
            $this->id(),
            $this->verifiedIsTest,
            isset($capture['amount']['value'])
                ? PayPalMoney::toStoredCents((string) $capture['amount']['value'], $currency ?: (string) $donation->currency)
                : null,
            $currency !== '' ? $currency : null,
        );
        if ($refusal !== null) {
            return $this->refused($eventId, $type, $refusal);
        }

        // Idempotent: DonationService::confirm() no-ops on an already-paid row.
        $this->donationService->confirm($donation, [
            'gateway_txn_id' => (string) ($capture['id'] ?? ''),
            'payment_method' => 'paypal',
            'metadata'       => ['paypal_capture_id' => (string) ($capture['id'] ?? '')],
        ]);

        return new WebhookOutcome(
            signature_ok: true,
            external_id: $eventId,
            event_type: $type,
            handled: true,
        );
    }

    /** @param array<string,mixed> $capture */
    private function handleCaptureDenied(string $eventId, string $type, array $capture): WebhookOutcome
    {
        $donation = $this->donationForCapture($capture);
        if (! $donation) {
            return $this->unmatched($eventId, $type, 'capture');
        }

        // The browser picks custom_id when it creates the order, so this event
        // names a donation an attacker chose. handleCaptureCompleted checks the
        // pair; the two that reverse a payment did not, so a sandbox-signed
        // event could fail a live donation.
        if ($reason = WebhookPaymentGuard::refuseToTouch($donation, $this->id(), $this->verifiedIsTest)) {
            return $this->refused($eventId, $type, $reason);
        }

        $this->donationService->markFailed($donation, 'PayPal declined the payment.');

        return new WebhookOutcome(
            signature_ok: true,
            external_id: $eventId,
            event_type: $type,
            handled: true,
        );
    }

    /**
     * A refund issued from the PayPal dashboard. Recorded so Dono's totals
     * match PayPal without an admin re-entering it.
     *
     * @param array<string,mixed> $refund
     */
    private function handleCaptureRefunded(string $eventId, string $type, array $refund): WebhookOutcome
    {
        $reference = (string) ($refund['custom_id'] ?? '');
        $donation  = $reference !== '' ? $this->donations->findByReference($reference) : null;

        if (! $donation) {
            return $this->unmatched($eventId, $type, 'refund');
        }

        // custom_id is chosen by whoever created the order, so this refund
        // names a donation of the caller's choosing. Without the pair check a
        // sandbox credential could refund live money: the donation drops out of
        // every total, its receipt is voided, and the donor is emailed about a
        // refund PayPal never made.
        if ($reason = WebhookPaymentGuard::refuseToTouch($donation, $this->id(), $this->verifiedIsTest)) {
            return $this->refused($eventId, $type, $reason);
        }

        $currency = strtoupper((string) ($refund['amount']['currency_code'] ?? $donation->currency));
        $amount   = PayPalMoney::toStoredCents((string) ($refund['amount']['value'] ?? '0'), $currency);

        $this->donationService->recordExternalRefund(
            $donation,
            $amount,
            (string) ($refund['id'] ?? ''),
            'PayPal dashboard refund'
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
    /**
     * When the next charge is due, from the plan's own interval.
     *
     * Stripe reads this off the invoice's period end; PayPal's sale event does
     * not carry it, and fetching the subscription would cost an API round trip
     * on every renewal. Left unwritten, next_payment_at kept the value set at
     * signup, so it went stale on the donor's portal and on the admin screen,
     * and "skip next payment" computed its new date from a moment in the past.
     */
    private function nextPaymentAfter(RecurringPlan $plan): ?string
    {
        try {
            $next = FrequencyMap::nextRenewalAfter(
                (int) $this->clock->now()->format('U'),
                (string) $plan->interval_unit,
                max(1, (int) $plan->interval_count)
            );
        } catch (RuntimeException $e) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $next);
    }

    private function handleSubscriptionEnded(string $eventId, string $type, array $sub): WebhookOutcome
    {
        $plan = $this->planRepo->findBySubscriptionId($this->id(), (string) ($sub['id'] ?? ''));
        if (! $plan) {
            return $this->unmatched($eventId, $type, 'subscription');
        }

        $reason = $type === 'BILLING.SUBSCRIPTION.EXPIRED'
            ? 'Subscription expired at PayPal'
            : 'Cancelled at PayPal';

        // markCancelled is idempotent and owns the status/timestamp writes.
        $won = $this->planRepo->markCancelled($plan, $this->now(), $reason);

        // Gated on winning the transition, as Stripe's handler is: a cancel
        // that started in the portal already recorded the event before telling
        // PayPal, so only a PayPal-initiated end emits here and the donor gets
        // exactly one email. Without this a subscription ended at PayPal (by
        // the donor there, or by dunning) left no recurring.cancelled event and
        // sent the donor nothing at all.
        if ($won) {
            $this->donationService->recordRecurringCancellation($plan, $reason);
        }

        return new WebhookOutcome(
            signature_ok: true,
            external_id: $eventId,
            event_type: $type,
            handled: true,
        );
    }

    /**
     * A renewal payment on an existing subscription. The first payment is
     * already recorded by the checkout flow, so a sale whose amount matches an
     * existing donation for this billing period must not double-count.
     *
     * @param array<string,mixed> $sale
     */
    private function handleRenewalPaid(string $eventId, string $type, array $sale): WebhookOutcome
    {
        $subId = (string) ($sale['billing_agreement_id'] ?? '');
        if ($subId === '') {
            return new WebhookOutcome(
                signature_ok: true,
                external_id: $eventId,
                event_type: $type,
                handled: false,
            );
        }

        $plan = $this->planRepo->findBySubscriptionId($this->id(), $subId);
        if (! $plan) {
            // Not unmatched(): that returns 200 on the reasoning that a valid
            // event which is not ours should not be retried for days. A sale
            // carrying a billing_agreement_id arrived on our own account's
            // webhook, so it is ours - the plan row simply does not exist yet.
            //
            // PayPal bills the moment the donor approves, and the plan is
            // written by the browser's POST to /gateways/paypal/subscription.
            // When the webhook wins that race, or the donor closes the tab
            // before it fires, the 200 told PayPal the opening payment had been
            // accepted and it never redelivered. The first payment of every
            // affected subscription was simply never booked.
            //
            // 503 so PayPal redelivers while the browser call catches up. Its
            // retry schedule ends on its own, so this cannot retry forever.
            return new WebhookOutcome(
                signature_ok: true,
                external_id: $eventId,
                event_type: $type,
                handled: false,
                error: "No local plan for subscription {$subId} yet; asking PayPal to redeliver",
                http_status: 503,
            );
        }

        $saleId   = (string) ($sale['id'] ?? '');
        $currency = strtoupper((string) ($sale['amount']['currency'] ?? $plan->currency));
        $amount   = PayPalMoney::toStoredCents((string) ($sale['amount']['total'] ?? '0'), $currency);

        if ($saleId === '' || $amount <= 0) {
            return new WebhookOutcome(
                signature_ok: true,
                external_id: $eventId,
                event_type: $type,
                handled: false,
                error: 'PayPal sale missing id or amount',
            );
        }

        $confirmResult = [
            'gateway_txn_id' => $saleId,
            'payment_method' => 'paypal',
            'metadata'       => ['paypal_subscription_id' => $subId],
        ];

        // PayPal takes the first payment the moment the donor approves, so the
        // opening PAYMENT.SALE.COMPLETED belongs to the signup donation that is
        // already on file. Treating it as a renewal would bank the same money
        // twice, so confirm the pending signup row instead when one exists.
        $signup = Donation::query()
            ->where('recurring_plan_id', (int) $plan->id)
            ->where('status', 'pending')
            ->get();

        if ($signup instanceof Donation) {
            // Claiming the sale id on the still-pending row is the single-winner
            // transition for the opening payment, the same shape the renewal
            // branch below gets from $renewal['created']. recordPayment
            // increments unconditionally, so without it two deliveries of one
            // sale both bump payments_count - and PayPal redelivers by design,
            // which the 503 early-sale path now makes more likely, not less.
            //
            // A targeted UPDATE rather than save(): a whole-row write from the
            // loser's stale copy would push status back to pending over the
            // winner's paid row. <=> is null-safe, so a row with no intent id
            // yet still claims.
            // whereRaw first: it emits no AND connector, so anywhere else in
            // the chain it fuses onto the preceding condition.
            $won = Donation::query()
                ->whereRaw('NOT (gateway_intent_id <=> %s)', $saleId)
                ->where('id', (int) $signup->id)
                ->where('status', 'pending')
                ->update([
                    'gateway_intent_id' => $saleId,
                    'updated_at'        => $this->now(),
                ])
                ->affectedRows > 0;

            // confirm() stays unconditional: it is idempotent, and a loser that
            // still finds the row pending heals a delivery that died between
            // the claim and the confirm rather than stranding it.
            $signup->gateway_intent_id = $saleId;
            $this->donationService->confirm($signup, $confirmResult);

            if ($won) {
                $fresh = $this->planRepo->findBySubscriptionId($this->id(), $subId);
                if ($fresh) {
                    $this->planRepo->recordPayment($fresh, $amount, $this->now(), $this->nextPaymentAfter($fresh));
                }
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
            $saleId,
            $confirmResult,
        );

        // Only a genuinely new renewal bumps the counters. PayPal redelivers
        // events, and recordPayment increments unconditionally, so a replay
        // would otherwise permanently inflate payments_count.
        if ($renewal['created']) {
            $fresh = $this->planRepo->findBySubscriptionId($this->id(), $subId);
            if ($fresh) {
                $this->planRepo->recordPayment($fresh, $amount, $this->now(), $this->nextPaymentAfter($fresh));
            }
        }

        return new WebhookOutcome(
            signature_ok: true,
            external_id: $eventId,
            event_type: $type,
            handled: true,
        );
    }

    /** Clock returns a DateTimeImmutable; the models store MySQL datetimes. */
    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    /** @param array<string,mixed> $capture */
    private function donationForCapture(array $capture): ?Donation
    {
        $reference = (string) ($capture['custom_id'] ?? '');
        if ($reference !== '') {
            $found = $this->donations->findByReference($reference);
            if ($found) return $found;
        }

        // Fall back to the order id carried on the capture's up link.
        $orderId = (string) ($capture['supplementary_data']['related_ids']['order_id'] ?? '');
        return $orderId !== ''
            ? $this->donations->findByGatewayIntent($this->id(), $orderId)
            : null;
    }

    /**
     * A genuinely PayPal-signed event that must not touch this donation. 200,
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
        // make PayPal retry it for days.
        return new WebhookOutcome(
            signature_ok: true,
            external_id: $eventId,
            event_type: $type,
            handled: false,
            error: "No donation matched this PayPal {$what}",
            http_status: 200,
        );
    }

    public function refund(Donation $donation, int $amountCents, ?string $reason = null): RefundResult
    {
        $captureId = (string) ($donation->gateway_txn_id ?? '');
        if ($captureId === '') {
            return RefundResult::failure('This donation has no PayPal capture to refund.');
        }

        $this->account->useTestMode((bool) $donation->is_test);
        $currency = strtoupper((string) $donation->currency);

        try {
            $refund = $this->api->post(
                '/v2/payments/captures/' . rawurlencode($captureId) . '/refund',
                [
                    'amount' => [
                        'currency_code' => $currency,
                        'value'         => PayPalMoney::toValue($amountCents, $currency),
                    ],
                    'note_to_payer' => $reason !== null ? substr($reason, 0, 255) : null,
                ],
                [
                    // Stable per attempt so a timed-out refund that already
                    // processed returns the original instead of issuing a second.
                    'PayPal-Request-Id' => 'dono_refund_' . $donation->id . '_'
                        . (int) $donation->refunded_cents . '_' . $amountCents,
                ]
            );
        } catch (RuntimeException $e) {
            return RefundResult::failure($e->getMessage());
        }

        $status = (string) ($refund['status'] ?? '');
        if (! in_array($status, ['COMPLETED', 'PENDING'], true)) {
            return RefundResult::failure("PayPal refund status is {$status}.");
        }

        $echoed = (string) ($refund['amount']['value'] ?? '');
        $echoedCurrency = strtoupper((string) ($refund['amount']['currency_code'] ?? $currency));

        return new RefundResult(
            success: true,
            gateway_refund_id: (string) ($refund['id'] ?? ''),
            amount_cents: $echoed !== '' ? PayPalMoney::toStoredCents($echoed, $echoedCurrency) : $amountCents,
            metadata: ['paypal_refund_status' => $status],
        );
    }

    public function cancelSubscription(RecurringPlan $plan, ?string $reason = null): void
    {
        $this->account->useTestMode((bool) $plan->is_test);
        try {
            $this->api->post(
                '/v1/billing/subscriptions/' . rawurlencode($plan->gateway_subscription_id) . '/cancel',
                ['reason' => $reason !== null ? substr($reason, 0, 127) : 'Cancelled by donor']
            );
        } catch (RuntimeException $e) {
            // Idempotent: cancelling an already-cancelled subscription is fine.
            if (! $this->isAlreadyInThatState($e)) {
                throw $e;
            }
        }
    }

    public function pauseSubscription(RecurringPlan $plan, ?string $resumesAt = null): void
    {
        $this->account->useTestMode((bool) $plan->is_test);
        try {
            $this->api->post(
                '/v1/billing/subscriptions/' . rawurlencode($plan->gateway_subscription_id) . '/suspend',
                ['reason' => 'Paused by donor']
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
                '/v1/billing/subscriptions/' . rawurlencode($plan->gateway_subscription_id) . '/activate',
                ['reason' => 'Resumed by donor']
            );
        } catch (RuntimeException $e) {
            if (! $this->isAlreadyInThatState($e)) {
                throw $e;
            }
        }
    }

    /**
     * PayPal has no "change the price on this subscription" call: the amount
     * lives on the plan, so changing it means revising the subscription onto a
     * plan at the new amount (provisioned on demand and reused).
     */
    public function updateSubscriptionAmount(RecurringPlan $plan, int $amountCents): void
    {
        $this->account->useTestMode((bool) $plan->is_test);

        $planId = $this->plans->resolvePlan(
            (bool) $plan->is_test,
            $amountCents,
            strtoupper((string) $plan->currency),
            (string) $plan->interval_unit,
            (int) $plan->interval_count
        );

        $revised = $this->api->post(
            '/v1/billing/subscriptions/' . rawurlencode($plan->gateway_subscription_id) . '/revise',
            ['plan_id' => $planId]
        );

        // PayPal does not apply a revise until the subscriber approves it, and
        // says so by handing back an approve link. The response was discarded,
        // so the caller wrote the new amount to the plan while PayPal carried
        // on charging the old one: the portal showed the donor the figure they
        // asked for and their card showed the figure they had. Nothing later
        // reconciled the two.
        $approveUrl = '';
        foreach ((array) ($revised['links'] ?? []) as $link) {
            if (strtolower((string) ($link['rel'] ?? '')) === 'approve') {
                $approveUrl = (string) ($link['href'] ?? '');
                break;
            }
        }

        if ($approveUrl !== '') {
            throw new SubscriptionChangeNeedsApproval(
                'PayPal needs the donor to approve this change before it takes effect.',
                $approveUrl
            );
        }
    }

    /**
     * @return array{0:string,1:int} interval unit + count for a Dono frequency.
     *
     * Delegates to FrequencyMap rather than repeating the table: a local match
     * with a monthly default silently billed biweekly donors once a month.
     */
    private function intervalFor(string $frequency): array
    {
        return FrequencyMap::toStripe($frequency);
    }

    /**
     * A second capture on an order PayPal already took. Safe to re-enter: the
     * caller re-reads the order and confirms the donation from it.
     *
     * PayPal always sends a `description` next to the issue code, and the
     * message builder prefers the description, so grepping the message for the
     * code only ever worked against a response shape PayPal does not send. A
     * double-click or a retried tab therefore told the donor the payment had
     * failed on money already taken.
     */
    private function isAlreadyCaptured(RuntimeException $e): bool
    {
        return $e instanceof PayPalApiException && $e->hasIssue('ORDER_ALREADY_CAPTURED');
    }

    /**
     * Pause/resume/cancel are safe to re-enter, so PayPal telling us the
     * subscription is already in the state we asked for is success, not failure.
     *
     * Matched on the issue codes rather than on the message: the previous
     * `already` needle also matched the *description* of unrelated errors, and
     * the codes it looked for never survived message formatting anyway.
     */
    private function isAlreadyInThatState(RuntimeException $e): bool
    {
        return $e instanceof PayPalApiException
            && $e->hasIssue('SUBSCRIPTION_STATUS_INVALID', 'INVALID_STATE', 'INVALID_RESOURCE_STATE');
    }
}
