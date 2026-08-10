<?php

declare(strict_types=1);

namespace Dono\Gateways;

/**
 * Result of `PaymentGateway::confirm()`.
 *
 * @since 1.0.0
 */
final class GatewayConfirmResult
{
    /** @since 1.0.0 */
    public function __construct(
        public readonly bool $success,
        public readonly ?string $gateway_txn_id = null,
        public readonly ?string $payment_method = null,
        public readonly ?string $payment_method_brand = null,
        public readonly ?string $payment_method_last4 = null,
        public readonly ?int $fee_cents = null,
        public readonly ?string $error = null,
        public readonly ?array $metadata = null,
        // Not success, but not failure either: the processor took the money and
        // is holding it. A caller that treats this as failure tells the donor
        // their payment did not go through while it is still going through.
        public readonly bool $pending = false,
    ) {
    }

    /** @since 1.0.0 */
    public function toArray(): array
    {
        return [
            'gateway_txn_id'       => $this->gateway_txn_id,
            'payment_method'       => $this->payment_method,
            'payment_method_brand' => $this->payment_method_brand,
            'payment_method_last4' => $this->payment_method_last4,
            'fee_cents'            => $this->fee_cents,
            'metadata'             => $this->metadata,
        ];
    }
}
