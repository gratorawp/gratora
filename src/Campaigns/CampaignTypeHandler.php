<?php

declare(strict_types=1);

namespace Dono\Campaigns;

/**
 * Behavior a non-default campaign_type handler provides.
 * Core only provides the registry seam; consumers read it.
 *
 * @since 1.0.0
 */
interface CampaignTypeHandler
{
    /** @since 1.0.0 */
    public function type(): string;

    /** @since 1.0.0 */
    public function label(): string;

    /**
     * @return class-string<\Dono\Vendor\Queryable\Model>|null sidecar PK = parent id, or null
     *
     * @since 1.0.0
     */
    public function sidecarModel(): ?string;
}
