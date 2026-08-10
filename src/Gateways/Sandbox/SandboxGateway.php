<?php

declare(strict_types=1);

namespace Dono\Gateways\Sandbox;

use Dono\Donations\Donation;
use Dono\Foundation\Time\Clock;
use Dono\Gateways\GatewayConfirmResult;
use Dono\Gateways\GatewayIntentResult;
use Dono\Gateways\PaymentGateway;
use Dono\Gateways\RefundResult;
use Dono\Gateways\WebhookOutcome;
use WP_REST_Request;

/**
 * Simulated gateway for rehearsing the donation flow. Registered only when
 * org-wide test mode is on, so it never reaches production donors. Moves no
 * money: createIntent returns a synthetic id and confirm succeeds immediately.
 *
 * @since 1.0.0
 */
final class SandboxGateway implements PaymentGateway
{
    /** @since 1.0.0 */
    public function __construct(private Clock $clock)
    {
    }

    /** @since 1.0.0 */
    public function id(): string
    {
        return 'sandbox';
    }

    /** @since 1.0.0 */
    public function label(): string
    {
        return __('Test donation', 'dono');
    }

    /** @since 1.0.0 */
    public function description(): string
    {
        return __('Simulated payment for testing. No real money moves and the form is in test mode.', 'dono');
    }

    /** @since 1.0.0 */
    public function frequencies(): array
    {
        return ['one_time', 'recurring'];
    }

    /** @since 1.0.0 */
    public function paymentMethods(): array
    {
        return ['test'];
    }

    /** @since 1.0.0 */
    public function countries(): array
    {
        return ['*'];
    }

    /** @since 1.0.0 */
    public function currencies(): array
    {
        return ['*'];
    }

    /** @since 1.0.0 */
    public function canCharge(): bool
    {
        return true;
    }

    /** @since 1.0.0 */
    public function createIntent(Donation $donation): GatewayIntentResult
    {
        return new GatewayIntentResult(
            intent_id: 'sandbox_' . $donation->reference,
            metadata: [
                'created_at' => $this->clock->now()->format('c'),
                'note'       => 'Sandbox test donation.',
            ],
            // No off-site step; the donation flow should confirm immediately
            // so test donations land as paid and exercise the same
            // post-confirm side effects (receipt, rollups, events) the real
            // gateways trigger via webhook.
            auto_confirm: true,
        );
    }

    /** @since 1.0.0 */
    public function confirm(Donation $donation, array $payload = []): GatewayConfirmResult
    {
        return new GatewayConfirmResult(
            success: true,
            gateway_txn_id: 'sandbox_txn_' . $donation->reference,
            payment_method: 'test',
            payment_method_brand: null,
            payment_method_last4: null,
            fee_cents: 0,
            metadata: ['confirmed_at' => $this->clock->now()->format('c')],
        );
    }

    /** @since 1.0.0 */
    public function handleWebhook(WP_REST_Request $request): WebhookOutcome
    {
        return WebhookOutcome::notSupported($this->id());
    }

    /** @since 1.0.0 */
    public function refund(Donation $donation, int $amountCents, ?string $reason = null): RefundResult
    {
        return new RefundResult(
            success:           true,
            gateway_refund_id: 'sandbox_refund_' . $donation->reference . '_' . bin2hex(random_bytes(4)),
            amount_cents:      $amountCents,
            metadata:          ['reason' => $reason],
        );
    }
}
