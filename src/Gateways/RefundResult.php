<?php

declare(strict_types=1);

namespace Dono\Gateways;

/**
 * Result of `PaymentGateway::refund()`. `success = true` means the gateway
 * accepted the refund.
 *
 * @since 1.0.0
 */
final class RefundResult
{
    /** @since 1.0.0 */
    public function __construct(
        public readonly bool $success,
        public readonly ?string $gateway_refund_id = null,
        public readonly ?int $amount_cents = null,
        public readonly ?string $error = null,
        public readonly ?array $metadata = null,
    ) {
    }

    /** @since 1.0.0 */
    public static function failure(string $error): self
    {
        return new self(success: false, error: $error);
    }
}
