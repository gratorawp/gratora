<?php

declare(strict_types=1);

namespace Dono\Gateways\Offline;

use Dono\Donations\Donation;
use Dono\Foundation\Time\Clock;
use Dono\Gateways\GatewayConfirmResult;
use Dono\Gateways\GatewayIntentResult;
use Dono\Gateways\PaymentGateway;
use Dono\Gateways\RefundResult;
use Dono\Gateways\WebhookOutcome;
use WP_REST_Request;

/**
 * Offline gateway for cash, check, or bank transfer. No external API calls,
 * no webhooks. createIntent returns a stable id derived from the donation
 * reference; confirmation is explicit via the admin.
 *
 * @version 1.0.0
 */
final class OfflineGateway implements PaymentGateway
{
    public function __construct(private Clock $clock)
    {
    }

    public function id(): string
    {
        return 'offline';
    }

    public function label(): string
    {
        return __('Offline donations', 'dono');
    }

    public function description(): string
    {
        return __('Pay by bank transfer, check or cash. We confirm it manually.', 'dono');
    }

    public function frequencies(): array
    {
        // Offline donations are confirmed by hand and have no stored payment
        // method, so nothing can auto-renew them. Offering a recurring option
        // would create a plan that silently never charges again.
        return ['one_time'];
    }

    public function paymentMethods(): array
    {
        return ['cash', 'cheque', 'bank_transfer', 'other'];
    }

    public function countries(): array
    {
        return ['*'];
    }

    public function currencies(): array
    {
        return ['*'];
    }

    public function canCharge(): bool
    {
        return true;
    }

    public function createIntent(Donation $donation): GatewayIntentResult
    {
        $intentId = 'offline_' . $donation->reference;

        return new GatewayIntentResult(
            intent_id: $intentId,
            metadata: [
                'created_at' => $this->clock->now()->format('c'),
                'note'       => 'Offline donation, awaiting admin confirmation.',
            ],
        );
    }

    public function confirm(Donation $donation, array $payload = []): GatewayConfirmResult
    {
        return new GatewayConfirmResult(
            success: true,
            gateway_txn_id: 'offline_txn_' . $donation->reference,
            payment_method: $payload['payment_method'] ?? 'offline',
            payment_method_brand: $payload['payment_method_brand'] ?? null,
            payment_method_last4: null,
            fee_cents: 0,
            metadata: [
                'confirmed_at'  => $this->clock->now()->format('c'),
                'admin_user_id' => $payload['admin_user_id'] ?? null,
                'note'          => $payload['note'] ?? null,
            ],
        );
    }

    public function handleWebhook(WP_REST_Request $request): WebhookOutcome
    {
        return WebhookOutcome::notSupported($this->id());
    }

    public function refund(Donation $donation, int $amountCents, ?string $reason = null): RefundResult
    {
        return new RefundResult(
            success:           true,
            gateway_refund_id: 'offline_refund_' . $donation->reference . '_' . bin2hex(random_bytes(4)),
            amount_cents:      $amountCents,
            metadata: [
                'reason'       => $reason,
                'confirmed_at' => $this->clock->now()->format('c'),
            ],
        );
    }
}
