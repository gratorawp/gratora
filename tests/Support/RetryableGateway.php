<?php

declare(strict_types=1);

namespace Gratora\Tests\Support;

use Gratora\Donations\Donation;
use Gratora\Gateways\GatewayConfirmResult;
use Gratora\Gateways\GatewayIntentResult;
use Gratora\Gateways\PaymentGateway;
use Gratora\Gateways\RefundResult;
use Gratora\Gateways\SupportsPaymentRetry;
use Gratora\Gateways\WebhookOutcome;
use Gratora\Recurring\RecurringPlan;
use WP_REST_Request;

/**
 * A gateway that takes a retry instruction.
 *
 * Stripe is the only core gateway that does, and it registers only while
 * credentials are stored, so without this the retryable half of every
 * screen's behaviour is whatever the suite happens to have registered.
 */
final class RetryableGateway implements PaymentGateway, SupportsPaymentRetry
{
    public function __construct(private string $id = 'retryable', private string $label = 'Retryable')
    {
    }

    public function id(): string { return $this->id; }
    public function label(): string { return $this->label; }
    public function description(): string { return ''; }
    public function frequencies(): array { return ['recurring']; }
    public function paymentMethods(): array { return []; }
    public function countries(): array { return []; }
    public function currencies(): array { return []; }
    public function canCharge(): bool { return true; }
    public function createIntent(Donation $donation): GatewayIntentResult { return new GatewayIntentResult(); }
    public function confirm(Donation $donation, array $payload = []): GatewayConfirmResult { return new GatewayConfirmResult(success: false); }
    public function handleWebhook(WP_REST_Request $request): WebhookOutcome { return new WebhookOutcome(signature_ok: false); }
    public function refund(Donation $donation, int $amountCents, ?string $reason = null): RefundResult { return new RefundResult(success: false); }
    public function retryPayment(RecurringPlan $plan): void {}
}
