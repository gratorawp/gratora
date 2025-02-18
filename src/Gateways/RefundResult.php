<?php

declare(strict_types=1);

namespace Dono\Gateways;

/**
 * Result of `PaymentGateway::refund()`. `success = true` means the gateway
 * accepted the refund.
 *
 * @version 1.0.0
 */
final class RefundResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $gateway_refund_id = null,
        public readonly ?int $amount_cents = null,
        public readonly ?string $error = null,
        public readonly ?array $metadata = null,
    ) {
    }

    /** Factory for a failed refund with an error message. */
    public static function failure(string $error): self
    {
        return new self(success: false, error: $error);
    }
}
