<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Gateways;

use DateTimeImmutable;
use Dono\Donations\Donation;
use Dono\Foundation\Time\FrozenClock;
use Dono\Gateways\Offline\OfflineGateway;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

final class OfflineGatewayTest extends TestCase
{
    private OfflineGateway $gateway;

    protected function setUp(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-05-13 09:00:00'));
        $this->gateway = new OfflineGateway($clock);
    }

    public function test_identity_and_support_metadata(): void
    {
        $this->assertSame('offline', $this->gateway->id());
        // Offline cannot auto-renew, so it must only ever offer one-time.
        $this->assertSame(['one_time'], $this->gateway->frequencies());
        $this->assertNotContains('recurring', $this->gateway->frequencies(), 'offline never advertises recurring');
        $this->assertSame(['*'], $this->gateway->countries());
        $this->assertSame(['*'], $this->gateway->currencies());
        $this->assertContains('cash', $this->gateway->paymentMethods());
    }

    public function test_create_intent_derives_id_from_donation_reference(): void
    {
        $donation = $this->donationFor('DONO-2026-00042');
        $result = $this->gateway->createIntent($donation);

        $this->assertSame('offline_DONO-2026-00042', $result->intent_id);
        $this->assertNull($result->client_secret);
        $this->assertFalse($result->requires_action);
    }

    public function test_confirm_returns_success_with_synthetic_txn_id(): void
    {
        $donation = $this->donationFor('DONO-2026-00099');
        $result = $this->gateway->confirm($donation);

        $this->assertTrue($result->success);
        $this->assertSame('offline_txn_DONO-2026-00099', $result->gateway_txn_id);
        $this->assertSame('offline', $result->payment_method);
    }

    public function test_handle_webhook_reports_not_supported(): void
    {
        $request = new WP_REST_Request();
        $outcome = $this->gateway->handleWebhook($request);

        $this->assertFalse($outcome->signature_ok);
        $this->assertSame(405, $outcome->http_status);
        $this->assertFalse($outcome->handled);
    }

    private function donationFor(string $reference): Donation
    {
        $d = Donation::make();
        $d->reference = $reference;
        return $d;
    }
}
