<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Forms;

use Dono\Donations\Donation;
use Dono\Donations\DonationIntent;
use Dono\Forms\DefaultFormTypeHandler;
use Dono\Forms\FormTypeHandler;
use Dono\Forms\FormTypeRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FormTypeRegistryTest extends TestCase
{
    private function intent(array $extra = []): DonationIntent
    {
        return new DonationIntent(
            email: 'd@example.com',
            amount_cents: 1000,
            currency: 'USD',
            gateway: 'offline',
            extra: $extra,
        );
    }

    public function test_defaults_to_passthrough_when_no_type(): void
    {
        $r = new FormTypeRegistry();
        $r->register(new DefaultFormTypeHandler());

        $handler = $r->handlerFor($this->intent());
        $this->assertSame('donation', $handler->type());
        $intent = $this->intent();
        $this->assertSame($intent, $handler->prepareIntent($intent, []));
    }

    public function test_unknown_type_falls_back_to_default(): void
    {
        $r = new FormTypeRegistry();
        $r->register(new DefaultFormTypeHandler());

        $handler = $r->handlerFor($this->intent(['form_type' => 'nope']));
        $this->assertSame('donation', $handler->type());
    }

    public function test_resolves_custom_handler_by_type(): void
    {
        $r = new FormTypeRegistry();
        $r->register(new DefaultFormTypeHandler());
        $r->register($this->p2p());

        $handler = $r->handlerFor($this->intent(['form_type' => 'p2p']));
        $this->assertSame('p2p', $handler->type());
        $this->assertTrue($r->has('p2p'));
        $this->assertSame(['donation', 'p2p'], array_keys($r->all()));
    }

    public function test_duplicate_type_throws(): void
    {
        $r = new FormTypeRegistry();
        $r->register(new DefaultFormTypeHandler());

        $this->expectException(RuntimeException::class);
        $r->register(new DefaultFormTypeHandler());
    }

    private function p2p(): FormTypeHandler
    {
        return new class implements FormTypeHandler {
            public function type(): string
            {
                return 'p2p';
            }

            public function label(): string
            {
                return 'P2P';
            }

            public function prepareIntent(DonationIntent $intent, array $body): DonationIntent
            {
                return $intent;
            }

            public function onDonationCreated(Donation $donation, array $body): void
            {
            }

            public function sidecarModel(): ?string
            {
                return null;
            }
        };
    }
}
