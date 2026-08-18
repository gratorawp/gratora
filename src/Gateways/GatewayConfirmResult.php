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
        // Also not failure: the payment went through and the money has since
        // gone back. The donation cannot be banked, but it was never declined,
        // so a caller must not fail it and must not notify the donor of one.
        public readonly bool $reversed = false,
        // How much of the payment has gone back, in minor units. Above zero on
        // a successful confirm means a slice left the balance before the row
        // was bankable, and the caller owes it a refund record.
        public readonly int $reversed_minor_units = 0,
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
