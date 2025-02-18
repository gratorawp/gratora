<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Gateways;

use Dono\Gateways\WebhookOutcome;
use PHPUnit\Framework\TestCase;

final class WebhookOutcomeTest extends TestCase
{
    public function test_bad_signature_factory_produces_401_with_default_message(): void
    {
        $o = WebhookOutcome::badSignature();
        $this->assertFalse($o->signature_ok);
        $this->assertSame(401, $o->http_status);
        $this->assertSame('Invalid signature.', $o->error);
        $this->assertFalse($o->handled);
        $this->assertNull($o->external_id);
    }

    public function test_bad_signature_factory_accepts_custom_message(): void
    {
        $o = WebhookOutcome::badSignature('No Stripe-Signature header.');
        $this->assertSame('No Stripe-Signature header.', $o->error);
        $this->assertSame(401, $o->http_status);
    }

    public function test_not_supported_factory_produces_405_with_gateway_name(): void
    {
        $o = WebhookOutcome::notSupported('offline');
        $this->assertFalse($o->signature_ok);
        $this->assertSame(405, $o->http_status);
        $this->assertStringContainsString('offline', $o->error);
    }

    public function test_handled_outcome_carries_all_metadata(): void
    {
        $o = new WebhookOutcome(
            signature_ok: true,
            external_id:  'evt_xyz',
            event_type:   'payment_intent.succeeded',
            handled:      true,
        );

        $this->assertTrue($o->signature_ok);
        $this->assertTrue($o->handled);
        $this->assertSame('evt_xyz', $o->external_id);
        $this->assertSame('payment_intent.succeeded', $o->event_type);
        $this->assertSame(200, $o->http_status, 'default http_status is 200');
    }
}
