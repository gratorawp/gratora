<?php

declare(strict_types=1);

namespace Gratora\Campaigns;

/** @since 1.0.0 */
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
        return __('Standard', 'gratora-donation-platform');
    }

    /** @since 1.0.0 */
    public function sidecarModel(): ?string
    {
        return null;
    }
}
