<?php

declare(strict_types=1);

namespace Dono\Campaigns;

/**
 * Behaviour a non-default campaign_type handler provides.
 * Core only provides the registry seam; consumers read it.
 *
 * @version 1.0.0
 */
interface CampaignTypeHandler
{
    public function type(): string;

    public function label(): string;

    /** @return class-string<\Dono\Vendor\Queryable\Model>|null sidecar PK = parent id, or null */
    public function sidecarModel(): ?string;
}
