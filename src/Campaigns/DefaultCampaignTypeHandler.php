<?php

declare(strict_types=1);

namespace FundKit\Campaigns;

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
        return __('Standard', 'fundraising-toolkit');
    }

    /** @since 1.0.0 */
    public function sidecarModel(): ?string
    {
        return null;
    }
}
