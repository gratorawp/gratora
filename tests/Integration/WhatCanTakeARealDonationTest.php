<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donations\Donation;
use Gratora\Foundation\Plugin;
use Gratora\Foundation\Time\Clock;
use Gratora\Gateways\GatewayConfirmResult;
use Gratora\Gateways\GatewayIntentResult;
use Gratora\Gateways\GatewayManager;
use Gratora\Gateways\ModeCredentialed;
use Gratora\Gateways\PaymentGateway;
use Gratora\Gateways\RefundResult;
use Gratora\Gateways\Sandbox\SandboxGateway;
use Gratora\Gateways\WebhookOutcome;
use Gratora\Recurring\RecurringPlanRepository;
use WP_REST_Request;

final class WhatCanTakeARealDonationTest extends IntegrationTestCase
{
    private function manager(): GatewayManager
    {
        return Plugin::instance()->container->get(GatewayManager::class);
    }

    private function registerTestDonationMethod(): void
    {
        if ($this->manager()->get('sandbox')) {
            return;
        }

        $this->manager()->register(new SandboxGateway(
            Plugin::instance()->container->get(Clock::class),
            new RecurringPlanRepository()
        ));
    }

    public function test_the_test_donation_method_alone_is_not_enough(): void
    {
        update_option('gratora_gateway_config', ['test_mode' => true]);
        $this->registerTestDonationMethod();

        $this->assertSame([], $this->manager()->realMethods());
    }

    public function test_bank_transfer_with_nothing_written_is_not_enough(): void
    {
        update_option('gratora_gateway_config', []);

        $this->assertSame([], $this->manager()->realMethods());
    }

    public function test_bank_transfer_counts_once_its_details_are_written(): void
    {
        update_option('gratora_gateway_config', ['offline' => ['bank_details' => 'IBAN HR12 1001 0051 8630 0016 0']]);

        $this->assertSame(['Offline donations'], $this->manager()->realMethods());
    }

    public function test_it_counts_the_same_while_test_mode_is_on(): void
    {
        update_option('gratora_gateway_config', [
            'test_mode' => true,
            'offline'   => ['bank_details' => 'IBAN HR12 1001 0051 8630 0016 0'],
        ]);
        $this->registerTestDonationMethod();

        $this->assertSame(['Offline donations'], $this->manager()->realMethods());
    }

    public function test_a_method_that_is_switched_off_does_not_count(): void
    {
        update_option('gratora_gateway_config', ['offline' => ['enabled' => false, 'bank_details' => 'IBAN HR12']]);

        $this->assertSame([], $this->manager()->realMethods());
    }

    public function test_a_gateway_from_an_add_on_counts(): void
    {
        update_option('gratora_gateway_config', []);
        $this->manager()->register($this->addOnGateway('acme-bank', 'Acme Bank', live: true));

        $this->assertSame(['Acme Bank'], $this->manager()->realMethods());
    }

    public function test_a_gateway_that_holds_test_keys_only_does_not_count(): void
    {
        update_option('gratora_gateway_config', ['test_mode' => true]);
        $this->manager()->register($this->addOnGateway('acme-cards', 'Acme Cards', live: false));

        $this->assertSame([], $this->manager()->realMethods());
    }

    private function addOnGateway(string $id, string $label, bool $live): PaymentGateway
    {
        return new class($id, $label, $live) implements PaymentGateway, ModeCredentialed {
            public function __construct(private string $id, private string $label, private bool $live)
            {
            }

            public function id(): string { return $this->id; }
            public function label(): string { return $this->label; }
            public function description(): string { return ''; }
            public function frequencies(): array { return ['one_time']; }
            public function paymentMethods(): array { return ['card']; }
            public function countries(): array { return ['*']; }
            public function currencies(): array { return ['USD']; }
            public function canCharge(): bool { return true; }
            public function chargesInMode(bool $test): bool { return $test || $this->live; }
            public function frequenciesInMode(bool $test): array { return ['one_time']; }
            public function currenciesInMode(bool $test): array { return ['USD']; }
            public function createIntent(Donation $d): GatewayIntentResult
            {
                return new GatewayIntentResult(intent_id: 'x');
            }
            public function confirm(Donation $d, array $p = []): GatewayConfirmResult
            {
                return new GatewayConfirmResult(success: true);
            }
            public function refund(Donation $d, int $c, ?string $r = null): RefundResult
            {
                return RefundResult::failure('no');
            }
            public function handleWebhook(WP_REST_Request $r): WebhookOutcome
            {
                return WebhookOutcome::notSupported($this->id);
            }
        };
    }
}
