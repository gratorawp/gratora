<?php

declare(strict_types=1);

namespace Dono\Campaigns;

/**
 * Built-in handler for the 'standard' campaign type.
 *
 * @version 1.0.0
 */
final class DefaultCampaignTypeHandler implements CampaignTypeHandler
{
    public function type(): string
    {
        return 'standard';
    }

    public function label(): string
    {
        return __('Standard', 'dono');
    }

    public function sidecarModel(): ?string
    {
        return null;
    }
}
