<?php

declare(strict_types=1);

namespace FundKit\Tests\Unit\Campaigns;

use FundKit\Campaigns\CampaignTypeHandler;
use FundKit\Campaigns\CampaignTypeRegistry;
use FundKit\Campaigns\DefaultCampaignTypeHandler;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CampaignTypeRegistryTest extends TestCase
{
    public function test_defaults_to_standard_passthrough(): void
    {
        $r = new CampaignTypeRegistry();
        $r->register(new DefaultCampaignTypeHandler());

        $this->assertSame('standard', $r->handlerFor('standard')->type());
        $this->assertSame('standard', $r->handlerFor('unknown')->type());
    }

    public function test_resolves_custom_handler_by_type(): void
    {
        $r = new CampaignTypeRegistry();
        $r->register(new DefaultCampaignTypeHandler());
        $r->register($this->p2p());

        $this->assertSame('p2p', $r->handlerFor('p2p')->type());
        $this->assertTrue($r->has('p2p'));
        $this->assertSame(['standard', 'p2p'], array_keys($r->all()));
    }

    public function test_duplicate_type_throws(): void
    {
        $r = new CampaignTypeRegistry();
        $r->register(new DefaultCampaignTypeHandler());

        $this->expectException(RuntimeException::class);
        $r->register(new DefaultCampaignTypeHandler());
    }

    private function p2p(): CampaignTypeHandler
    {
        return new class implements CampaignTypeHandler {
            public function type(): string
            {
                return 'p2p';
            }

            public function label(): string
            {
                return 'P2P';
            }

            public function sidecarModel(): ?string
            {
                return null;
            }
        };
    }
}
