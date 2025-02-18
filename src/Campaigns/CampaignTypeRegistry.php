<?php

declare(strict_types=1);

namespace Dono\Campaigns;

use RuntimeException;

/**
 * Holds registered CampaignTypeHandler instances, keyed by type slug.
 *
 * @version 1.0.0
 */
final class CampaignTypeRegistry
{
    /** @var array<string,CampaignTypeHandler> */
    private array $handlers = [];

    public function register(CampaignTypeHandler $handler): void
    {
        $type = $handler->type();
        if (isset($this->handlers[$type])) {
            throw new RuntimeException("Campaign type '{$type}' is already registered.");
        }
        $this->handlers[$type] = $handler;
    }

    public function has(string $type): bool
    {
        return isset($this->handlers[$type]);
    }

    public function get(string $type): ?CampaignTypeHandler
    {
        return $this->handlers[$type] ?? null;
    }

    public function handlerFor(string $type): CampaignTypeHandler
    {
        return $this->handlers[$type]
            ?? $this->handlers['standard']
            ?? new DefaultCampaignTypeHandler();
    }

    /** @return array<string,CampaignTypeHandler> */
    public function all(): array
    {
        return $this->handlers;
    }
}
