<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donations\Donation;
use Gratora\Foundation\Plugin;
use Gratora\Gateways\GatewayConfirmResult;
use Gratora\Gateways\GatewayIntentResult;
use Gratora\Gateways\GatewayManager;
use Gratora\Gateways\PaymentGateway;
use Gratora\Gateways\RefundResult;
use Gratora\Gateways\WebhookOutcome;
use WP_REST_Request;

/**
 * The registry is process-wide and the container memoises it, so a test that
 * registers a double left every later test in the run measuring a site that has
 * it. Around forty classes do that, register() throws on a duplicate id, and
 * three separate workarounds in this suite exist because of it: a gateway whose
 * settings genuinely fail to persist could pass in one invocation and fail in
 * another depending on what ran first.
 */
final class GatewayRegistryIsolationTest extends IntegrationTestCase
{
    private function registry(): GatewayManager
    {
        return Plugin::instance()->container->get(GatewayManager::class);
    }

    /** Runs first: leaves a gateway registered and walks away, as forty classes do. */
    public function test_a_double_can_be_registered(): void
    {
        $this->registry()->register(new IsolationProbeGateway());

        $this->assertNotNull($this->registry()->get('gratora-isolation-probe'));
    }

    /** Runs after it, and must see the site the product actually ships. */
    public function test_the_next_test_does_not_inherit_it(): void
    {
        $this->assertNull(
            $this->registry()->get('gratora-isolation-probe'),
            'a double from an earlier test is still registered, so this test measures a site nobody has'
        );
    }
}

final class IsolationProbeGateway implements PaymentGateway
{
    public function id(): string { return 'gratora-isolation-probe'; }
    public function label(): string { return 'Isolation probe'; }
    public function description(): string { return ''; }
    public function frequencies(): array { return ['one_time']; }
    public function paymentMethods(): array { return ['card']; }
    public function countries(): array { return ['*']; }
    public function currencies(): array { return ['*']; }
    public function canCharge(): bool { return false; }

    public function createIntent(Donation $donation): GatewayIntentResult
    {
        return new GatewayIntentResult(intent_id: 'probe');
    }

    public function confirm(Donation $donation, array $payload = []): GatewayConfirmResult
    {
        return new GatewayConfirmResult(success: false);
    }

    public function handleWebhook(WP_REST_Request $request): WebhookOutcome
    {
        return WebhookOutcome::notSupported('gratora-isolation-probe');
    }

    public function refund(Donation $donation, int $amountCents, ?string $reason = null): RefundResult
    {
        return new RefundResult(success: false, amount_cents: 0);
    }
}
