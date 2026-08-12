<?php

declare(strict_types=1);

namespace Dono\Campaigns;

/**
 * Built-in handler for the 'standard' campaign type.
 *
 * @since 1.0.0
 */
final class DefaultCampaignTypeHandler implements CampaignTypeHandler
{
    /** @since 1.0.0 */
    public function type(): string
    {
        return 'standard';
    }

    /** @since 1.0.0 */
    public function label(): string
    {
        return __('Standard', 'dono-fundraising-platform');
    }

    /** @since 1.0.0 */
    public function sidecarModel(): ?string
    {
        return null;
    }
}
